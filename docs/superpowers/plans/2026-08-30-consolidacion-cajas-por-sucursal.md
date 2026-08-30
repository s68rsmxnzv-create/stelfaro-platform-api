# Consolidación de Cajas por Sucursal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Formalizar "1 caja por sucursal" como única forma de operar, atar al rol cajero a la caja de su sucursal asignada, y dar a `company_admin`/`owner` un consolidado en vivo de todas las cajas del tenant en el dashboard.

**Architecture:** Backend en `stelfaro-platform-api` (Laravel): se endurece la columna `core_sucursal_id` de `cash_registers` (NOT NULL + índice único parcial), se agregan dos métodos de autorización en `PlatformAccessPolicy` y se aplican en `CashRegisterController`, y se agrega un endpoint de consolidado respaldado por un método nuevo en `CashService`. Frontend en `stelfaro-platform` (`packages/api-client` + `packages/billing`, Vue 3): un método nuevo en `PlatformClient`, un componente `CashConsolidatedCard.vue` montado en el dashboard existente (mismo patrón que `SucursalActivityCard.vue`), y un ajuste en `CashPage.vue`/`BillingAppPage.vue` para ocultar el selector de sucursal al rol cajero.

**Tech Stack:** Laravel 11 + PostgreSQL (`pgsql`), PHPUnit; Vue 3 `<script setup>` + TypeScript, `ky` (vía `PlatformClient`), Tailwind.

**Spec:** `docs/superpowers/specs/2026-08-30-consolidacion-cajas-por-sucursal-design.md`

## Global Constraints

- Motor de base de datos: PostgreSQL (`DB_CONNECTION=pgsql`). El índice único parcial usa sintaxis Postgres (`WHERE status = 'active'`).
- No se instala `doctrine/dbal` — los cambios de columna (`ALTER COLUMN ... SET NOT NULL`) se hacen con `DB::statement()` crudo, no con `Blueprint::change()`.
- No se crea tabla local de sucursales — `core_sucursal_id` sigue siendo la única fuente de vínculo, resuelta en vivo contra `dte-core` cuando hace falta (decisión de arquitectura ya tomada en el spec).
- Roles restringidos a su sucursal: **solo `billing_user` (cajero)** por ahora. `owner`, `company_admin`, `billing_admin` y `seller` operan cualquier caja del tenant sin restricción — no ampliar esto sin pedirlo explícitamente.
- `reconcileExpense()` no lleva chequeo de sucursal — no está atado a una caja concreta (decisión explícita del spec).
- `packages/billing` y `packages/api-client` no tienen suite de tests (verificado: no hay `*.test.ts`/`*.spec.ts` ni script `test` en sus `package.json`). La verificación de las tareas de frontend es `npm run build` dentro de `stelfaro-platform-api` (que compila estos paquetes vía Vite) — no se agregan tests de componente.
- Todo el trabajo va sobre la rama `integration` de cada repo (`stelfaro-platform-api`, `stelfaro-platform`), nunca `dev`/`main` directamente.

---

## Task 1: Endurecer `core_sucursal_id` en `cash_registers` (NOT NULL + 1 caja activa por sucursal)

**Files:**
- Create: `stelfaro-platform-api/database/migrations/2026_08_30_120000_harden_cash_register_branch_scope.php`
- Modify: `stelfaro-platform-api/app/Services/Cash/CashService.php:191-210` (método `defaultRegister`)
- Modify: `stelfaro-platform-api/app/Http/Controllers/Api/V1/Platform/CashSettingsController.php:24-27` (método `upsert`)
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php` (arreglar 4 llamadas existentes + 2 tests nuevos)

**Interfaces:**
- Produces: `CashService::defaultRegister(Tenant $tenant, array $branch = []): CashRegister` — mismo nombre y firma, pero ahora lanza `\Illuminate\Validation\ValidationException` (mensaje bajo la clave `cash_register`) si no puede resolver ninguna sucursal, y usa `[tenant_id, core_sucursal_id]` como clave de unicidad (ya no incluye `name`).

- [ ] **Step 1: Verificar que la suite de caja pasa antes de tocar nada**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: `9 passed`

- [ ] **Step 2: Crear la migración que endurece el esquema**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropUnique('cash_register_tenant_branch_name_unique');
        });

        DB::statement('ALTER TABLE cash_registers ALTER COLUMN core_sucursal_id SET NOT NULL');

        DB::statement(
            'CREATE UNIQUE INDEX cash_register_tenant_active_branch_unique '
            .'ON cash_registers (tenant_id, core_sucursal_id) '
            ."WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cash_register_tenant_active_branch_unique');
        DB::statement('ALTER TABLE cash_registers ALTER COLUMN core_sucursal_id DROP NOT NULL');

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'core_sucursal_id', 'name'], 'cash_register_tenant_branch_name_unique');
        });
    }
};
```

Save as `database/migrations/2026_08_30_120000_harden_cash_register_branch_scope.php`.

- [ ] **Step 3: Ejecutar la migración en la base de datos local y confirmar que aplica sin error**

Run: `cd stelfaro-platform-api && php artisan migrate`
Expected: la migración `2026_08_30_120000_harden_cash_register_branch_scope` corre sin errores (ya se verificó el 2026-08-30 que las 2 filas existentes de `cash_registers` tienen `core_sucursal_id` poblado y sin duplicados por sucursal, así que `SET NOT NULL` no debería fallar).

- [ ] **Step 4: Endurecer `CashService::defaultRegister()`**

Reemplazar el método completo (líneas 191-210 de `app/Services/Cash/CashService.php`):

```php
    public function defaultRegister(Tenant $tenant, array $branch = []): CashRegister
    {
        if (! isset($branch['core_sucursal_id'])) {
            $branch = array_merge($branch, $this->resolveMainBranch($tenant));
        }

        if (! isset($branch['core_sucursal_id'])) {
            throw ValidationException::withMessages([
                'cash_register' => 'No pudimos determinar la sucursal para esta caja. Indica una sucursal o configura la sucursal matriz en dte-core.',
            ]);
        }

        return CashRegister::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'core_sucursal_id' => (int) $branch['core_sucursal_id'],
        ], [
            'name' => trim((string) ($branch['name'] ?? 'Caja principal')),
            'core_sucursal_code' => $branch['core_sucursal_code'] ?? null,
            'core_sucursal_name' => $branch['core_sucursal_name'] ?? null,
            'status' => 'active',
        ]);
    }
```

`ValidationException` ya está importado en este archivo (se usa en otros métodos de la misma clase) — no hace falta agregar el `use`.

- [ ] **Step 5: Quitar el fallback muerto en `CashSettingsController::upsert()`**

En `app/Http/Controllers/Api/V1/Platform/CashSettingsController.php`, dentro de `upsert()`, reemplazar:

```php
        $register = CashRegister::query()->where('tenant_id', $tenant->id)->where('core_sucursal_id', $data['core_sucursal_id'])->first()
            ?? CashRegister::query()->where('tenant_id', $tenant->id)->whereNull('core_sucursal_id')->whereDoesntHave('setting')->oldest()->first()
            ?? $cash->defaultRegister($tenant, $data);
```

por:

```php
        $register = CashRegister::query()->where('tenant_id', $tenant->id)->where('core_sucursal_id', $data['core_sucursal_id'])->first()
            ?? $cash->defaultRegister($tenant, $data);
```

(la rama de en medio buscaba una caja "huérfana" con `core_sucursal_id = NULL` para adoptarla — ya no puede existir ninguna, la columna es `NOT NULL`).

- [ ] **Step 6: Arreglar las llamadas existentes que abrían sesión sin sucursal**

En `tests/Feature/CashRegisterTest.php`, estas 4 llamadas dependen hoy del fallback silencioso de `resolveMainBranch()` (que en tests siempre falla porque el tenant no tiene `core_empresa_id` vinculado, y devolvía `[]` sin lanzar error). Después del Step 4 lanzan `ValidationException` (422) si no se les pasa `core_sucursal_id`. Agregar el dato explícito en las 4:

En `test_cash_session_tracks_expense_and_closes_with_expected_balance` (línea 30):
```php
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])
            ->assertCreated()->json('data');
```

En `test_expense_reconciliation_does_not_duplicate_cash_movement` (línea 46):
```php
        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 0, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated();
```

En `test_transfer_is_visible_in_the_session_without_changing_expected_cash` (línea 58):
```php
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 100, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated()->json('data');
```

En `test_dte_receivable_accepts_partial_idempotent_payments_and_updates_the_report` (línea 85):
```php
        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 20, 'name' => 'Caja principal', 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])->assertCreated()->json('data');
```

- [ ] **Step 7: Ejecutar la suite de caja y confirmar que las 4 llamadas arregladas siguen pasando**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: `9 passed` (mismo conteo que el Step 1 — todavía no se agregaron los tests nuevos).

- [ ] **Step 8: Agregar test — abrir sin ninguna sucursal resoluble falla con 422**

Agregar al final de la clase `CashRegisterTest`, antes del método privado `member()`:

```php
    public function test_opening_a_session_without_a_resolvable_branch_fails_with_a_clear_error(): void
    {
        [$user, $tenant] = $this->member();

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions", ['opening_balance' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_register');
    }
```

- [ ] **Step 9: Agregar test — `defaultRegister()` no crea una segunda caja para la misma sucursal por cambiar el nombre**

```php
    public function test_default_register_is_identified_by_branch_not_by_name(): void
    {
        [, $tenant] = $this->member();

        $first = app(\App\Services\Cash\CashService::class)->defaultRegister($tenant, ['core_sucursal_id' => 3, 'name' => 'Caja A']);
        $second = app(\App\Services\Cash\CashService::class)->defaultRegister($tenant, ['core_sucursal_id' => 3, 'name' => 'Caja B']);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('cash_registers', 1);
    }
```

- [ ] **Step 10: Ejecutar la suite completa de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: `11 passed`

- [ ] **Step 11: Commit**

```bash
cd stelfaro-platform-api
git add database/migrations/2026_08_30_120000_harden_cash_register_branch_scope.php \
        app/Services/Cash/CashService.php \
        app/Http/Controllers/Api/V1/Platform/CashSettingsController.php \
        tests/Feature/CashRegisterTest.php
git commit -m "$(cat <<'EOF'
feat(cash): 1 caja activa por sucursal (NOT NULL + índice único parcial)

core_sucursal_id deja de ser opcional y de admitir varias cajas en la
misma sucursal con solo cambiar el nombre. Sin sucursal resoluble,
abrir caja falla con un error claro en vez de crear una caja huérfana.
EOF
)"
```

---

## Task 2: Cajero atado a su sucursal — permisos, alcance de la lista de cajas y apertura automática

**Files:**
- Modify: `stelfaro-platform-api/app/Services/PlatformAccessPolicy.php`
- Modify: `stelfaro-platform-api/app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php`

**Interfaces:**
- Consumes: `UserTenantMembership::fiscalAssignments(): HasMany<UserFiscalAssignment>` (ya existe en `app/Models/UserTenantMembership.php:27`); `UserFiscalAssignment->core_sucursal_id: int` (ya existe).
- Produces:
  - `PlatformAccessPolicy::activeMembershipFor(?User $user, Tenant|int $tenant): ?UserTenantMembership` — mismo método que ya existía como `private`, pasa a `public` (sin cambiar firma ni comportamiento).
  - `PlatformAccessPolicy::canOperateCashRegister(?User $user, Tenant|int $tenant, CashRegister $register): bool` — nuevo.
  - `PlatformAccessPolicy::canViewCashConsolidated(?User $user, Tenant|int $tenant): bool` — nuevo (lo consume la Task 3).

- [ ] **Step 1: Escribir los tests que deben fallar primero**

Agregar a `tests/Feature/CashRegisterTest.php`, antes del método privado `member()`:

```php
    public function test_cashier_can_only_open_the_cash_register_of_their_assigned_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal'])
            ->assertForbidden();

        $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal'])
            ->assertCreated();
    }

    public function test_cashier_opening_a_session_without_specifying_a_branch_uses_their_assigned_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $base = "/api/v1/platform/tenants/{$tenant->id}/cash";

        $session = $this->actingAs($user)->postJson($base.'/sessions', ['opening_balance' => 50])
            ->assertCreated()->json('data');

        $this->assertSame(5, $session['register']['branch_id']);
    }

    public function test_cashier_cannot_close_a_cash_register_of_another_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        $session = CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions/{$session->id}/close", ['declared_balance' => 0])
            ->assertForbidden();
    }

    public function test_cashier_cannot_register_a_movement_against_another_branchs_cash_register(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/movements", [
            'direction' => 'in', 'kind' => 'manual_income', 'method' => 'cash', 'amount' => 10,
            'description' => 'Intento ajeno', 'idempotency_key' => 'ajeno-1', 'cash_register_id' => $register->id,
        ])->assertForbidden();
    }

    public function test_cashier_without_a_fiscal_assignment_cannot_operate_any_cash_register(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: null);

        $this->actingAs($user)->postJson("/api/v1/platform/tenants/{$tenant->id}/cash/sessions", ['opening_balance' => 50, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz'])
            ->assertForbidden();
    }

    public function test_cashier_only_sees_the_cash_register_of_their_assigned_branch_in_the_overview(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 5, 'core_sucursal_code' => 'S005', 'core_sucursal_name' => 'Mi sucursal', 'name' => 'Caja mía', 'status' => 'active']);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja ajena', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash")
            ->assertOk()
            ->assertJsonCount(1, 'registers')
            ->assertJsonPath('registers.0.branch_id', 5);
    }
```

Agregar también el helper privado `cashierMember()`, junto al `member()` existente (al final de la clase):

```php
    private function cashierMember(?int $assignedSucursalId): array
    {
        $app = PlatformApp::query()->create(['key' => 'facturacion', 'name' => 'Facturación', 'host' => 'new.stelfaro.com', 'default_path' => '/', 'status' => 'active']);
        $tenant = Tenant::query()->create(['slug' => 'cash-cashier-company', 'name' => 'Cash Cashier Company', 'status' => 'active', 'primary_app_id' => $app->id]);
        $tenant->appAccesses()->create(['platform_app_id' => $app->id, 'status' => 'active', 'is_default' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'must_change_password' => false]);
        $membership = $user->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'billing_user', 'status' => 'active', 'is_default' => true]);
        if ($assignedSucursalId !== null) {
            $membership->fiscalAssignments()->create(['core_empresa_id' => 900, 'core_sucursal_id' => $assignedSucursalId, 'core_punto_venta_id' => 1, 'is_default' => true, 'status' => 'active']);
        }

        return [$user, $tenant];
    }
```

`slug` debe ser único entre tenants de la suite — `cash-cashier-company` no colisiona con `cash-company` (usado por `member()`).

- [ ] **Step 2: Ejecutar los tests nuevos y confirmar que fallan**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: FAIL — `test_cashier_can_only_open_the_cash_register_of_their_assigned_branch` y los otros 5 tests nuevos fallan (hoy no existe ninguna restricción de sucursal para el cajero; el segundo `postJson` de sucursal 9 debería dar 403 y da 201).

- [ ] **Step 3: Agregar `canOperateCashRegister` y `canViewCashConsolidated` a `PlatformAccessPolicy`, y exponer `activeMembershipFor`**

En `app/Services/PlatformAccessPolicy.php`, agregar el import:

```php
use App\Models\CashRegister;
```

(junto a los demás `use App\Models\...` al inicio del archivo).

Cambiar la firma del método privado existente (línea 227) de:

```php
    private function activeMembershipFor(?User $user, Tenant|int $tenant): ?UserTenantMembership
```

a:

```php
    public function activeMembershipFor(?User $user, Tenant|int $tenant): ?UserTenantMembership
```

(el cuerpo del método no cambia).

Agregar estos dos métodos nuevos, justo después de `canOperateTenant()` (línea 109):

```php
    public function canOperateCashRegister(?User $user, Tenant|int $tenant, CashRegister $register): bool
    {
        if ($this->hasGlobalAdminRole($user)) {
            return true;
        }

        $membership = $this->activeMembershipFor($user, $tenant);

        if ($membership === null) {
            return false;
        }

        if ($membership->role === PlatformRoles::BILLING_USER) {
            return $membership->fiscalAssignments()
                ->where('status', 'active')
                ->where('core_sucursal_id', $register->core_sucursal_id)
                ->exists();
        }

        return in_array($membership->role, [
            PlatformRoles::OWNER,
            PlatformRoles::COMPANY_ADMIN,
            PlatformRoles::BILLING_ADMIN,
            PlatformRoles::SELLER,
        ], true);
    }

    public function canViewCashConsolidated(?User $user, Tenant|int $tenant): bool
    {
        if ($this->hasGlobalAdminRole($user)) {
            return true;
        }

        return $this->hasTenantUserAdminRole($user, $tenant);
    }
```

- [ ] **Step 4: Aplicar `canOperateCashRegister` en `CashRegisterController` y resolver la sucursal del cajero automáticamente al abrir**

En `app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`, agregar los imports:

```php
use App\Models\User;
use App\Support\Platform\PlatformRoles;
```

(junto a los `use App\Models\...` existentes).

Reemplazar el método `overview()` completo (líneas 24-63) por:

```php
    public function overview(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $query = CashMovement::query()->where('tenant_id', $tenant->id)->with(['expense.supplier', 'order.device.customer']);
        $query->when($data['cash_register_id'] ?? null, fn ($q, $registerId) => $q->where('cash_register_id', $registerId));
        $query->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date));
        $query->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date));
        $query->when($data['method'] ?? null, fn ($q, $method) => $q->where('method', $method));
        $query->when($data['direction'] ?? null, fn ($q, $direction) => $q->where('direction', $direction));
        $summaryQuery = clone $query;
        $movements = $query->latest('occurred_at')->paginate((int) ($data['per_page'] ?? 20));
        $selectedRegisterId = isset($data['cash_register_id']) ? (int) $data['cash_register_id'] : null;
        $session = $cash->activeSession($tenant, $request->user()?->id, $selectedRegisterId);

        $membership = $policy->activeMembershipFor($request->user(), $tenant);
        $registersQuery = $tenant->cashRegisters()->where('status', 'active')->with('setting');
        if ($membership?->role === PlatformRoles::BILLING_USER) {
            $registersQuery->whereIn('core_sucursal_id', $membership->fiscalAssignments()->where('status', 'active')->pluck('core_sucursal_id'));
        }

        return response()->json([
            'registers' => $registersQuery->orderBy('core_sucursal_name')->orderBy('name')->get()->map(fn (CashRegister $register) => [
                'id' => $register->id, 'name' => $register->name, 'status' => $register->status,
                'branch_id' => $register->core_sucursal_id, 'branch_code' => $register->core_sucursal_code, 'branch_name' => $register->core_sucursal_name,
                'configured' => $register->setting !== null,
            ])->values(),
            'active_session' => $session ? $this->sessionPayload($session->load('register'), $cash) : null,
            'pending_counts' => CashSession::query()->where('tenant_id', $tenant->id)->where('status', 'closed_unverified')
                ->when($selectedRegisterId, fn ($query) => $query->where('cash_register_id', $selectedRegisterId))
                ->with('register')->oldest('business_date')->get()->map(fn (CashSession $pending) => $this->sessionPayload($pending, $cash))->values(),
            'summary' => [
                'inflows' => round((float) (clone $summaryQuery)->whereNull('reversed_at')->where('direction', 'in')->sum('amount'), 2),
                'outflows' => round((float) (clone $summaryQuery)->whereNull('reversed_at')->where('direction', 'out')->sum('amount'), 2),
                'pending_documents' => CashExpense::query()->where('tenant_id', $tenant->id)->where('status', 'pending_document')->count(),
            ],
            'data' => collect($movements->items())->map(fn (CashMovement $movement) => $this->movementPayload($movement))->values(),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }
```

(único cambio real: el bloque `$membership = ...` / `$registersQuery = ...` nuevo, y `'registers' => $registersQuery->...` en vez de `$tenant->cashRegisters()->where('status', 'active')->with('setting')->...` inline).

Reemplazar el método `open()` completo (líneas 65-100) por:

```php
    public function open(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'name' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000'],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'], 'core_sucursal_code' => ['nullable', 'string', 'max:30'], 'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $session = DB::transaction(function () use ($tenant, $request, $cash, $data, $policy): CashSession {
            $register = isset($data['cash_register_id'])
                ? CashRegister::query()->where('tenant_id', $tenant->id)->findOrFail($data['cash_register_id'])
                : $cash->defaultRegister($tenant, $this->branchForOpening($data, $policy, $request->user(), $tenant));
            abort_unless($policy->canOperateCashRegister($request->user(), $tenant, $register), 403);
            $locked = $register->sessions()->where('status', 'open')->lockForUpdate()->first();
            if ($locked) {
                throw ValidationException::withMessages(['cash_register' => 'Esta caja ya tiene una sesión abierta.']);
            }

            $timezone = $register->setting?->timezone ?? 'America/El_Salvador';
            $businessDate = now($timezone)->toDateString();
            if ($register->sessions()->whereDate('business_date', $businessDate)->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'Esta caja ya tiene una sesión para hoy.']);
            }

            return CashSession::query()->create([
                'tenant_id' => $tenant->id, 'cash_register_id' => $register->id,
                'opened_by' => $request->user()->id, 'opening_balance' => $data['opening_balance'],
                'business_date' => $businessDate, 'opening_source' => 'manual', 'count_status' => 'pending',
                'status' => 'open', 'opening_notes' => $data['notes'] ?? null, 'opened_at' => now(),
            ])->load('register');
        });
        $audit->record($request, 'cash.session.opened', ['cash_session_id' => $session->id]);

        return response()->json(['data' => $this->sessionPayload($session, $cash)], 201);
    }

    /**
     * Si no viene una sucursal explícita en la petición y quien abre es un cajero
     * (billing_user), usa la sucursal de su asignación fiscal activa en vez de caer
     * en la sucursal matriz del tenant — un cajero siempre abre su propia caja.
     *
     * @return array<string, mixed>
     */
    private function branchForOpening(array $data, PlatformAccessPolicy $policy, ?User $user, Tenant $tenant): array
    {
        if (isset($data['core_sucursal_id'])) {
            return $data;
        }

        $membership = $policy->activeMembershipFor($user, $tenant);
        if ($membership?->role !== PlatformRoles::BILLING_USER) {
            return $data;
        }

        $assignment = $membership->fiscalAssignments()->where('status', 'active')->orderByDesc('is_default')->first();
        if ($assignment === null) {
            return $data;
        }

        return [...$data, 'core_sucursal_id' => $assignment->core_sucursal_id];
    }
```

Reemplazar la línea de autorización en `close()` (línea 105):

```php
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
```

por:

```php
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        abort_unless($policy->canOperateCashRegister($request->user(), $tenant, $cashSession->register), 403);
```

En `storeMovement()`, reemplazar el cuerpo de la clausura de la transacción (líneas 143-154):

```php
        $movement = DB::transaction(function () use ($tenant, $request, $cash, $data): CashMovement {
            $registerId = isset($data['cash_register_id']) ? (int) $data['cash_register_id'] : null;
            $session = $cash->activeSession($tenant, $request->user()->id, $registerId);
            if ($data['method'] === 'cash' && ! $session) {
                throw ValidationException::withMessages(['method' => 'Abre una caja antes de registrar efectivo.']);
            }
            if ($data['method'] !== 'cash' && $registerId) {
                $register = CashRegister::query()->where('tenant_id', $tenant->id)->with('setting')->findOrFail($registerId);
                if ($register->setting && ! $register->setting->allow_non_cash_when_closed && ! $cash->activeSession($tenant, $request->user()->id, $registerId)) {
                    throw ValidationException::withMessages(['method' => 'Esta caja debe estar abierta para registrar cualquier forma de pago.']);
                }
            }
```

por:

```php
        $movement = DB::transaction(function () use ($tenant, $request, $cash, $data, $policy): CashMovement {
            $registerId = isset($data['cash_register_id']) ? (int) $data['cash_register_id'] : null;
            $session = $cash->activeSession($tenant, $request->user()->id, $registerId);
            if ($data['method'] === 'cash' && ! $session) {
                throw ValidationException::withMessages(['method' => 'Abre una caja antes de registrar efectivo.']);
            }
            $register = $session?->register;
            if ($data['method'] !== 'cash' && $registerId) {
                $register = CashRegister::query()->where('tenant_id', $tenant->id)->with('setting')->findOrFail($registerId);
                if ($register->setting && ! $register->setting->allow_non_cash_when_closed && ! $cash->activeSession($tenant, $request->user()->id, $registerId)) {
                    throw ValidationException::withMessages(['method' => 'Esta caja debe estar abierta para registrar cualquier forma de pago.']);
                }
            }
            if ($register) {
                abort_unless($policy->canOperateCashRegister($request->user(), $tenant, $register), 403);
            }
```

(el resto del cuerpo del método, desde `$expense = null;` hasta el final, no cambia).

En `reverse()`, reemplazar:

```php
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
```

por:

```php
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        if ($cashMovement->cash_register_id) {
            abort_unless($policy->canOperateCashRegister($request->user(), $tenant, $cashMovement->register), 403);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
```

`reconcileExpense()` no se toca.

- [ ] **Step 5: Ejecutar toda la suite de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: `17 passed` (11 del Task 1 + 6 nuevos de este task).

- [ ] **Step 6: Ejecutar toda la suite del proyecto para descartar regresiones en otros consumidores de `CashService`/`PlatformAccessPolicy`**

Run: `cd stelfaro-platform-api && php artisan test`
Expected: todos los tests pasan (en particular `CashierWebAccessTest`, `CashierInventoryAccessTest` y cualquier test que dependa de `PlatformAccessPolicy::canOperateTenant`, que no cambió).

- [ ] **Step 7: Commit**

```bash
cd stelfaro-platform-api
git add app/Services/PlatformAccessPolicy.php \
        app/Http/Controllers/Api/V1/Platform/CashRegisterController.php \
        tests/Feature/CashRegisterTest.php
git commit -m "$(cat <<'EOF'
feat(cash): el cajero solo opera la caja de su sucursal asignada

Nuevo PlatformAccessPolicy::canOperateCashRegister() aplicado en
open/close/storeMovement/reverse. El listado de cajas (overview) y la
apertura automática sin sucursal explícita también quedan acotados a
la sucursal de la asignación fiscal del cajero, igual que ya ocurre
con la emisión de DTE.
EOF
)"
```

---

## Task 3: Endpoint de consolidado de cajas por sucursal

**Files:**
- Modify: `stelfaro-platform-api/app/Models/CashSession.php` (relación `openedBy`)
- Modify: `stelfaro-platform-api/app/Services/Cash/CashService.php` (método `consolidated`)
- Modify: `stelfaro-platform-api/app/Http/Controllers/Api/V1/Platform/CashRegisterController.php` (método `consolidated`)
- Modify: `stelfaro-platform-api/routes/api.php:165` (nueva ruta)
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php`

**Interfaces:**
- Consumes: `PlatformAccessPolicy::canViewCashConsolidated()` (Task 2).
- Produces: `CashService::consolidated(Tenant $tenant): \Illuminate\Support\Collection` — cada elemento es `array{branch_id:int, branch_code:?string, branch_name:?string, register_id:int, status:'open'|'closed', opened_by:?string, opened_at:?string, balance:?float}`. Endpoint `GET /api/v1/platform/tenants/{tenant}/cash/consolidated` → `{"data": [...], "has_multiple_branches": bool}`.

- [ ] **Step 1: Escribir los tests que deben fallar primero**

Agregar a `tests/Feature/CashRegisterTest.php`, antes de `member()`:

```php
    public function test_owner_sees_consolidated_cash_status_by_branch(): void
    {
        [$user, $tenant] = $this->member();
        $matriz = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $matriz->id, 'opened_by' => $user->id, 'opening_balance' => 50, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);
        CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 2, 'core_sucursal_code' => 'S002', 'core_sucursal_name' => 'Sucursal Centro', 'name' => 'Caja Centro', 'status' => 'active']);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/consolidated")
            ->assertOk()
            ->assertJsonPath('has_multiple_branches', true)
            ->assertJsonPath('data.0.branch_name', 'Casa matriz')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.balance', 50)
            ->assertJsonPath('data.0.opened_by', $user->name)
            ->assertJsonPath('data.1.branch_name', 'Sucursal Centro')
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.balance', null);
    }

    public function test_cashier_cannot_view_the_consolidated_cash_status(): void
    {
        [$cashier, $tenant] = $this->cashierMember(assignedSucursalId: 1);

        $this->actingAs($cashier)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/consolidated")->assertForbidden();
    }
```

- [ ] **Step 2: Ejecutar los tests nuevos y confirmar que fallan**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: FAIL — `Route [GET consolidated] not defined` o 404, ambos tests nuevos fallan.

- [ ] **Step 3: Agregar la relación `openedBy` a `CashSession`**

En `app/Models/CashSession.php`, agregar el import `use Illuminate\Database\Eloquent\Relations\BelongsTo;` ya está presente; agregar el método (junto a `register()`):

```php
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
```

- [ ] **Step 4: Agregar `CashService::consolidated()`**

En `app/Services/Cash/CashService.php`, agregar el import:

```php
use Illuminate\Support\Collection;
```

Agregar el método, junto a `sessionTotals()`:

```php
    /** @return Collection<int, array{branch_id:int, branch_code:?string, branch_name:?string, register_id:int, status:string, opened_by:?string, opened_at:?string, balance:?float}> */
    public function consolidated(Tenant $tenant): Collection
    {
        return CashRegister::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['sessions' => fn ($query) => $query->where('status', 'open')->latest('opened_at')->limit(1)->with('openedBy')])
            ->orderBy('core_sucursal_name')
            ->orderBy('name')
            ->get()
            ->map(function (CashRegister $register): array {
                $session = $register->sessions->first();
                if ($session) {
                    return [
                        'branch_id' => $register->core_sucursal_id,
                        'branch_code' => $register->core_sucursal_code,
                        'branch_name' => $register->core_sucursal_name,
                        'register_id' => $register->id,
                        'status' => 'open',
                        'opened_by' => $session->openedBy?->name,
                        'opened_at' => $session->opened_at?->toISOString(),
                        'balance' => $this->sessionTotals($session)['expected'],
                    ];
                }

                $lastClosed = CashSession::query()->where('cash_register_id', $register->id)->where('status', 'closed')->latest('closed_at')->first();

                return [
                    'branch_id' => $register->core_sucursal_id,
                    'branch_code' => $register->core_sucursal_code,
                    'branch_name' => $register->core_sucursal_name,
                    'register_id' => $register->id,
                    'status' => 'closed',
                    'opened_by' => null,
                    'opened_at' => null,
                    'balance' => $lastClosed?->declared_balance !== null ? (float) $lastClosed->declared_balance : null,
                ];
            });
    }
```

- [ ] **Step 5: Agregar el método `consolidated()` al controlador y la ruta**

En `app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`, agregar (junto a `overview()`):

```php
    public function consolidated(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($policy->canViewCashConsolidated($request->user(), $tenant), 403);
        $branches = $cash->consolidated($tenant);

        return response()->json([
            'data' => $branches->values(),
            'has_multiple_branches' => $branches->count() > 1,
        ]);
    }
```

En `routes/api.php`, dentro del grupo `platform/tenants/{tenant}/cash` (línea 165), agregar la ruta justo después de `overview`:

```php
                Route::get('/', [CashRegisterController::class, 'overview']);
                Route::get('consolidated', [CashRegisterController::class, 'consolidated']);
```

- [ ] **Step 6: Ejecutar la suite de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: `19 passed`

- [ ] **Step 7: Ejecutar toda la suite del proyecto**

Run: `cd stelfaro-platform-api && php artisan test`
Expected: todos los tests pasan.

- [ ] **Step 8: Commit**

```bash
cd stelfaro-platform-api
git add app/Models/CashSession.php \
        app/Services/Cash/CashService.php \
        app/Http/Controllers/Api/V1/Platform/CashRegisterController.php \
        routes/api.php \
        tests/Feature/CashRegisterTest.php
git commit -m "$(cat <<'EOF'
feat(cash): endpoint de consolidado de cajas por sucursal

GET .../cash/consolidated devuelve estado (abierta/cerrada), quién la
abrió y saldo actual por sucursal. Solo owner/company_admin (y roles
globales) pueden verlo.
EOF
)"
```

---

## Task 4: Cliente API frontend — tipo y método para el consolidado

**Files:**
- Modify: `stelfaro-platform/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: `PlatformClient.cashRegistersConsolidated(tenantId: number): Promise<PlatformCashConsolidated>`; tipos `PlatformCashConsolidatedBranch`, `PlatformCashConsolidated`.

- [ ] **Step 1: Agregar los tipos**

En `stelfaro-platform/packages/api-client/src/index.ts`, justo después del cierre de `export type PlatformCashOverview = { ... };` (línea ~725), agregar:

```ts
export type PlatformCashConsolidatedBranch = {
  branch_id: number;
  branch_code: string | null;
  branch_name: string | null;
  register_id: number;
  status: "open" | "closed";
  opened_by: string | null;
  opened_at: string | null;
  balance: number | null;
};
export type PlatformCashConsolidated = {
  data: PlatformCashConsolidatedBranch[];
  has_multiple_branches: boolean;
};
```

- [ ] **Step 2: Agregar el método al cliente**

Justo después del método `reconcileCashExpense(...)` (línea ~3366), agregar:

```ts
  cashRegistersConsolidated(
    tenantId: number,
  ): Promise<PlatformCashConsolidated> {
    return this.http
      .get(`platform/tenants/${tenantId}/cash/consolidated`)
      .json();
  }
```

- [ ] **Step 3: Verificar que el paquete compila dentro del build del proyecto**

Run: `cd stelfaro-platform-api && npm run build`
Expected: el build termina sin errores (esbuild/Vite falla ante errores de sintaxis TypeScript en `.ts`; los errores de tipo pueden no bloquear el build, pero un error de sintaxis sí).

- [ ] **Step 4: Commit**

```bash
cd stelfaro-platform
git add packages/api-client/src/index.ts
git commit -m "feat(api-client): agregar cashRegistersConsolidated"
```

---

## Task 5: Tarjeta de consolidado en el dashboard

**Files:**
- Create: `stelfaro-platform/packages/billing/src/components/CashConsolidatedCard.vue`
- Modify: `stelfaro-platform/packages/billing/src/pages/BillingDashboardPage.vue`

**Interfaces:**
- Consumes: `PlatformClient.cashRegistersConsolidated(tenantId)` (Task 4); prop `platform: PlatformClient` ya construido en `BillingDashboardPage.vue:9`.
- Produces: componente `CashConsolidatedCard` con props `{ platform: PlatformClient; tenantId: number }` y evento `update:visible: (value: boolean) => void` — mismo contrato que `SucursalActivityCard.vue`.

- [ ] **Step 1: Crear `CashConsolidatedCard.vue`**

```vue
<script setup lang="ts">
import { ref, watch } from 'vue';
import { LockKeyhole, RefreshCw, Store } from 'lucide-vue-next';
import type { PlatformCashConsolidatedBranch, PlatformClient } from '@stelfaro/api-client';

const props = defineProps<{
  platform: PlatformClient;
  tenantId: number;
}>();

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
}>();

const branches = ref<PlatformCashConsolidatedBranch[]>([]);
const loading = ref(false);
const error = ref('');

const money = (value: number | null) =>
  new Intl.NumberFormat('es-SV', { style: 'currency', currency: 'USD' }).format(value ?? 0);

async function load() {
  if (!props.tenantId) return;
  loading.value = true;
  error.value = '';
  try {
    const result = await props.platform.cashRegistersConsolidated(props.tenantId);
    branches.value = result.data;
    emit('update:visible', result.has_multiple_branches);
  } catch {
    branches.value = [];
    emit('update:visible', false);
  } finally {
    loading.value = false;
  }
}

watch(() => props.tenantId, () => void load(), { immediate: true });

defineExpose({ load });
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
    <div class="flex items-center justify-between border-b border-line px-4 py-3">
      <div class="flex items-center gap-2">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-primary-soft text-primary">
          <Store class="h-4 w-4" />
        </span>
        <div>
          <h2 class="text-sm font-bold uppercase tracking-wide text-muted">Cajas por sucursal</h2>
          <p class="text-xs text-muted">Estado y saldo actual</p>
        </div>
      </div>
      <button
        type="button"
        class="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-line bg-surface text-muted transition hover:bg-surface-muted"
        :disabled="loading"
        aria-label="Actualizar"
        @click="load"
      >
        <RefreshCw class="h-4 w-4" :class="loading ? 'animate-spin' : ''" />
      </button>
    </div>

    <p v-if="error" class="px-4 py-4 text-sm text-danger">{{ error }}</p>
    <p v-else-if="loading && !branches.length" class="px-4 py-6 text-center text-sm text-muted">Cargando…</p>

    <ul v-else class="divide-y divide-line" :class="loading ? 'opacity-60' : ''">
      <li v-for="branch in branches" :key="branch.branch_id" class="flex items-center justify-between gap-3 px-4 py-3.5">
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold text-text">{{ branch.branch_name }}</p>
          <p class="mt-0.5 text-xs text-muted">
            {{ branch.status === 'open' ? `Abierta por ${branch.opened_by || '—'}` : 'Cerrada' }}
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <span
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="branch.status === 'open' ? 'bg-success-soft text-success' : 'bg-surface-muted text-muted'"
          >
            <LockKeyhole v-if="branch.status !== 'open'" class="h-3 w-3" />
            {{ branch.status === 'open' ? 'Abierta' : 'Cerrada' }}
          </span>
          <strong class="text-sm font-bold tabular-nums text-text">{{ money(branch.balance) }}</strong>
        </div>
      </li>
    </ul>
  </section>
</template>
```

Guardar en `stelfaro-platform/packages/billing/src/components/CashConsolidatedCard.vue`.

- [ ] **Step 2: Montarlo en `BillingDashboardPage.vue`**

En `stelfaro-platform/packages/billing/src/pages/BillingDashboardPage.vue`, agregar el import junto al de `SucursalActivityCard` (línea 6):

```ts
import CashConsolidatedCard from '../components/CashConsolidatedCard.vue';
```

Agregar el ref junto a `sucursalActivityVisible` (línea 12):

```ts
const cashConsolidatedVisible = ref(false);
```

En el template, justo después del bloque `<SucursalActivityCard ... />` (líneas 384-390), agregar:

```html
    <CashConsolidatedCard
      v-if="tenantId"
      v-show="cashConsolidatedVisible"
      :platform="platform"
      :tenant-id="tenantId"
      @update:visible="cashConsolidatedVisible = $event"
    />
```

`tenantId` y `platform` ya existen como prop y computed respectivamente en este archivo (líneas 8 y 10) — no requieren ningún cambio adicional.

- [ ] **Step 3: Verificar que el build pasa**

Run: `cd stelfaro-platform-api && npm run build`
Expected: build sin errores.

- [ ] **Step 4: Levantar el entorno de desarrollo y verificar visualmente**

Run: `cd stelfaro-platform-api && npm run dev` (dejarlo corriendo) y abrir `dev.stelfaro.com` (o el host configurado) con una cuenta `owner`/`company_admin` de un tenant con más de una sucursal activa en `cash_registers`. Confirmar que la tarjeta "Cajas por sucursal" aparece en el dashboard con el estado correcto por sucursal, y que desaparece (o nunca aparece) si el tenant tiene una sola sucursal o si la cuenta activa es un rol distinto de owner/company_admin (la llamada devuelve 403 y la tarjeta se autooculta).

- [ ] **Step 5: Commit**

```bash
cd stelfaro-platform
git add packages/billing/src/components/CashConsolidatedCard.vue \
        packages/billing/src/pages/BillingDashboardPage.vue
git commit -m "feat(dashboard): tarjeta de consolidado de cajas por sucursal"
```

---

## Task 6: Ocultar el selector de sucursal/caja para el rol cajero

**Files:**
- Modify: `stelfaro-platform/packages/billing/src/pages/BillingAppPage.vue:383-391`
- Modify: `stelfaro-platform/packages/billing/src/pages/CashPage.vue`

**Interfaces:**
- Consumes: `effectiveFiscalRole` (ya calculado en `BillingAppPage.vue:279-282`, valor `'cashier'` para el rol cajero — mismo string que `PlatformRoles::FISCAL_CASHIER` en el backend).
- Produces: `CashPage` gana la prop opcional `fiscalRole?: string | null`.

- [ ] **Step 1: Pasar `fiscalRole` al montar `CashPage`**

En `stelfaro-platform/packages/billing/src/pages/BillingAppPage.vue`, reemplazar el bloque (líneas 383-391):

```js
  if (props.module === "cash") {
    return {
      platformBaseUrl: props.platformBaseUrl,
      authToken: props.authToken,
      tenantId: Number(props.platformSession?.tenant?.id || 0),
      workshopEnabled: props.app.id === "taller",
      company: activeCompany.value,
    };
  }
```

por:

```js
  if (props.module === "cash") {
    return {
      platformBaseUrl: props.platformBaseUrl,
      authToken: props.authToken,
      tenantId: Number(props.platformSession?.tenant?.id || 0),
      workshopEnabled: props.app.id === "taller",
      company: activeCompany.value,
      fiscalRole: effectiveFiscalRole.value,
    };
  }
```

- [ ] **Step 2: Aceptar la prop y ocultar el selector en `CashPage.vue`**

En `stelfaro-platform/packages/billing/src/pages/CashPage.vue`, reemplazar la línea de `defineProps`/`withDefaults` (línea 9):

```ts
const props = withDefaults(defineProps<{ platformBaseUrl?: string; authToken?: string|null; tenantId: number; workshopEnabled?: boolean; company?: BillingEmpresa|null }>(), { platformBaseUrl: '/api/v1', authToken: null, workshopEnabled: false, company: null });
```

por:

```ts
const props = withDefaults(defineProps<{ platformBaseUrl?: string; authToken?: string|null; tenantId: number; workshopEnabled?: boolean; company?: BillingEmpresa|null; fiscalRole?: string|null }>(), { platformBaseUrl: '/api/v1', authToken: null, workshopEnabled: false, company: null, fiscalRole: null });
```

En el template (línea 57), reemplazar:

```html
<UiSelect v-if="registerOptions.length" v-model="selectedRegisterId" class="min-w-56" label="Sucursal / caja" hide-label :options="registerOptions" />
```

por:

```html
<UiSelect v-if="registerOptions.length && fiscalRole !== 'cashier'" v-model="selectedRegisterId" class="min-w-56" label="Sucursal / caja" hide-label :options="registerOptions" />
```

(el resto del componente no cambia: `loadCash()` ya auto-selecciona `result.registers[0]` cuando `selectedRegisterId` está vacío, y gracias a la Task 2 ese primer registro que devuelve `overview()` para un cajero es siempre el de su propia sucursal).

- [ ] **Step 3: Verificar que el build pasa**

Run: `cd stelfaro-platform-api && npm run build`
Expected: build sin errores.

- [ ] **Step 4: Verificar visualmente con una cuenta cajero**

Con el entorno de desarrollo corriendo (`npm run dev`), entrar como un usuario con rol `billing_user` (cajero) que tenga una asignación fiscal activa, ir a "Caja" y confirmar que **no** aparece el selector "Sucursal / caja", que la pantalla muestra directamente el estado de su propia caja, y que abrir/cerrar caja y registrar movimientos sigue funcionando con normalidad. Confirmar también, con una cuenta `owner`/`company_admin` de un tenant multi-sucursal, que el selector **sí** sigue apareciendo y sigue permitiendo cambiar de sucursal.

- [ ] **Step 5: Commit**

```bash
cd stelfaro-platform
git add packages/billing/src/pages/BillingAppPage.vue \
        packages/billing/src/pages/CashPage.vue
git commit -m "feat(cash): ocultar el selector de sucursal/caja para el rol cajero"
```

---

## Self-Review

**Cobertura del spec:**
- Sección 1 (esquema + invariante 1-caja-por-sucursal) → Task 1.
- Sección 2 (cajero atado a su sucursal, incluyendo el diseño extensible a otros roles) → Task 2 (`canOperateCashRegister` resuelve por `match` de rol, agregar `seller` después es una línea).
- Sección 3 (endpoint de consolidado) → Task 3.
- Sección 4 (widget de dashboard + ajustes de `CashPage.vue`) → Tasks 4, 5 y 6.
- Migración de datos existentes ("no requiere fusión") → confirmado en Task 1, Step 1/3 (verificado el 2026-08-30 contra la BD real: 2 filas, ambas con `core_sucursal_id` único).

**Hallazgo no cubierto por el spec original, incorporado durante la planeación:** los tests existentes de `CashRegisterTest.php` abrían sesión sin `core_sucursal_id`, dependiendo del fallback silencioso de `resolveMainBranch()` que la Task 1 elimina — cubierto en Task 1, Step 6. También se detectó que `overview()` no acotaba el listado de cajas a la sucursal del cajero (el selector oculto en Task 6 habría quedado apuntando a una caja arbitraria) — cubierto en Task 2, Step 4, y que `open()` sin sucursal explícita resolvía la sucursal matriz en vez de la del cajero — cubierto por `branchForOpening()` en el mismo step.

**Placeholders:** ninguno — cada step trae el código completo a escribir.

**Consistencia de tipos:** `PlatformCashConsolidatedBranch`/`PlatformCashConsolidated` (Task 4) coinciden campo a campo con la respuesta de `CashService::consolidated()` + `CashRegisterController::consolidated()` (Task 3): `branch_id`, `branch_code`, `branch_name`, `register_id`, `status`, `opened_by`, `opened_at`, `balance`, y el sobre `{ data, has_multiple_branches }`. `CashConsolidatedCard.vue` (Task 5) consume exactamente esos nombres. `canOperateCashRegister`/`canViewCashConsolidated`/`activeMembershipFor` (Task 2) se usan en Task 3 con la misma firma con la que se definieron.

**Alcance:** cada task deja el sistema en un estado funcionando y testeado — se puede parar después de cualquier task sin dejar nada roto (Task 1 sola ya es un endurecimiento de esquema correcto y probado; Task 2 sola ya cierra el hueco de permisos; Tasks 3-6 son aditivas).
