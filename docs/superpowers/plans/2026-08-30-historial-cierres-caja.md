# Historial de Cierres de Caja Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar una pestaña "Historial" en Caja con los cierres pasados por sucursal, y un carrusel en la tarjeta del dashboard con el estado en vivo + últimos 5 cierres de cada sucursal.

**Architecture:** Backend en `stelfaro-platform-api`: un método compartido de scoping (`PlatformAccessPolicy::allowedCashRegisterIds()`) reemplaza el cálculo duplicado que ya existía en `overview()`, un nuevo endpoint paginado `GET /cash/history`, y `CashService::consolidated()` gana un campo `recent_closures` por sucursal. Frontend en `stelfaro-platform`: nuevos tipos/método en `@stelfaro/api-client`, una pestaña nueva en `CashPage.vue`, y `CashConsolidatedCard.vue` pasa de lista vertical a carrusel horizontal (CSS `scroll-snap`, sin librería nueva).

**Tech Stack:** Laravel 11 + PostgreSQL, PHPUnit; Vue 3 `<script setup>` + TypeScript, Tailwind.

**Spec:** `docs/superpowers/specs/2026-08-30-historial-cierres-caja-design.md`

## Global Constraints

- El aviso de cierre sin confirmar (`closed_unverified`) YA EXISTE (`CashAutomationService::notify()`) — este plan no lo toca.
- No se agrega ninguna librería de carrusel — CSS `scroll-snap-type` + botones, mismo patrón de flechas que ya usa `SucursalActivityCard.vue`.
- `reconcileExpense()` sigue sin chequeo de sucursal (decisión ya tomada en el spec anterior) — no aplica aquí, no se toca.
- Solo `billing_user` (cajero) queda acotado a su sucursal en `allowedCashRegisterIds()`; el resto de roles (owner/company_admin/billing_admin/seller) no tiene restricción — mismo criterio que el resto del feature de caja.
- `packages/billing` no tiene test suite propio; `apps/billing-demo` sí (`pnpm typecheck && pnpm test`, confirmado en la revisión final del feature anterior) — es la verificación automática disponible para las tareas de frontend, además de `npm run build` en `stelfaro-platform-api`.
- Todo el trabajo va sobre la rama `integration` de cada repo.

---

## Task 1: `allowedCashRegisterIds()` compartido + refactor de `overview()` + relación `closedBy`

**Files:**
- Modify: `stelfaro-platform-api/app/Services/PlatformAccessPolicy.php`
- Modify: `stelfaro-platform-api/app/Http/Controllers/Api/V1/Platform/CashRegisterController.php:26-71`
- Modify: `stelfaro-platform-api/app/Models/CashSession.php`
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php`

**Interfaces:**
- Produces: `PlatformAccessPolicy::allowedCashRegisterIds(?User $user, Tenant|int $tenant): ?\Illuminate\Support\Collection` — `null` = sin restricción (todas las cajas del tenant); una `Collection<int>` de IDs de `cash_registers` si la membresía es `billing_user`. Lo consume la Task 2.
- Produces: `CashSession::closedBy(): BelongsTo` — igual patrón que `openedBy()` ya existente. Lo consume la Task 2.

- [ ] **Step 1: Escribir el test de regresión que debe seguir pasando igual tras el refactor**

El comportamiento de `overview()` para un cajero ya está cubierto por
`test_cashier_only_sees_the_cash_register_of_their_assigned_branch_in_the_overview`
(existente en `tests/Feature/CashRegisterTest.php`). No hace falta un test nuevo para
este step — el refactor debe dejarlo pasando exactamente igual. Ejecútalo primero para
confirmar la línea base:

Run: `cd stelfaro-platform-api && php artisan test --filter=test_cashier_only_sees_the_cash_register_of_their_assigned_branch_in_the_overview`
Expected: `1 passed`

- [ ] **Step 2: Agregar `allowedCashRegisterIds()` a `PlatformAccessPolicy`**

Agregar el import al inicio de `app/Services/PlatformAccessPolicy.php` (junto a los demás `use App\Models\...`):

```php
use Illuminate\Support\Collection;
```

Agregar el método, justo después de `canOperateCashRegister()`:

```php
    /**
     * IDs de cash_registers que esta membresía puede ver/operar, o null si no hay
     * restricción (todas las cajas del tenant). Hoy solo el cajero (billing_user)
     * queda acotado a la(s) sucursal(es) de su asignación fiscal activa.
     */
    public function allowedCashRegisterIds(?User $user, Tenant|int $tenant): ?Collection
    {
        if ($this->hasGlobalAdminRole($user)) {
            return null;
        }

        $membership = $this->activeMembershipFor($user, $tenant);
        if ($membership === null) {
            return collect();
        }

        if ($membership->role !== PlatformRoles::BILLING_USER) {
            return null;
        }

        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $branchIds = $membership->fiscalAssignments()->where('status', 'active')->pluck('core_sucursal_id');

        return CashRegister::query()->where('tenant_id', $tenantId)->whereIn('core_sucursal_id', $branchIds)->pluck('id');
    }
```

- [ ] **Step 3: Refactorizar `overview()` para usar el método nuevo**

En `app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`, dentro de `overview()`,
reemplazar:

```php
        // Un cajero (billing_user) solo puede ver el movimiento, el resumen, los cortes
        // pendientes y la sesión activa de las cajas de su(s) sucursal(es) asignada(s);
        // el resto de roles conserva la vista completa del tenant.
        $membership = $policy->activeMembershipFor($request->user(), $tenant);
        $isCashier = $membership?->role === PlatformRoles::BILLING_USER;
        $branchIds = $isCashier ? $membership->fiscalAssignments()->where('status', 'active')->pluck('core_sucursal_id') : null;
        $allowedRegisterIds = $isCashier ? $tenant->cashRegisters()->whereIn('core_sucursal_id', $branchIds)->pluck('id') : null;
```

por:

```php
        // Un cajero (billing_user) solo puede ver el movimiento, el resumen, los cortes
        // pendientes y la sesión activa de las cajas de su(s) sucursal(es) asignada(s);
        // el resto de roles conserva la vista completa del tenant.
        $membership = $policy->activeMembershipFor($request->user(), $tenant);
        $isCashier = $membership?->role === PlatformRoles::BILLING_USER;
        $allowedRegisterIds = $policy->allowedCashRegisterIds($request->user(), $tenant);
```

Y más abajo, reemplazar:

```php
        $registersQuery = $tenant->cashRegisters()->where('status', 'active')->with('setting');
        if ($branchIds !== null) {
            $registersQuery->whereIn('core_sucursal_id', $branchIds);
        }
```

por:

```php
        $registersQuery = $tenant->cashRegisters()->where('status', 'active')->with('setting');
        if ($allowedRegisterIds !== null) {
            $registersQuery->whereIn('id', $allowedRegisterIds);
        }
```

(`$branchIds` deja de usarse por completo — no queda ninguna otra referencia a esa variable en el método).

- [ ] **Step 4: Ejecutar el test de regresión y confirmar que sigue pasando igual**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: mismo conteo que antes del refactor (verifica con `git stash` + correr antes si tienes dudas, pero no debería cambiar ningún resultado — es un refactor puro, sin cambio de comportamiento).

- [ ] **Step 5: Agregar la relación `closedBy` a `CashSession`**

En `app/Models/CashSession.php`, agregar el método junto a `openedBy()`:

```php
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
```

- [ ] **Step 6: Ejecutar toda la suite de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: mismo conteo de tests que en el Step 4, todos verdes.

- [ ] **Step 7: Commit**

```bash
cd stelfaro-platform-api
git add app/Services/PlatformAccessPolicy.php \
        app/Http/Controllers/Api/V1/Platform/CashRegisterController.php \
        app/Models/CashSession.php
git commit -m "$(cat <<'EOF'
refactor(cash): extraer allowedCashRegisterIds() y agregar CashSession::closedBy()

Elimina la duplicación de "qué cajas puede ver un cajero" que ya
señalaba la revisión final del feature de sucursales. Sin cambio de
comportamiento — overview() sigue devolviendo exactamente lo mismo.
EOF
)"
```

---

## Task 2: Endpoint `GET /cash/history`

**Files:**
- Modify: `stelfaro-platform-api/app/Services/Cash/CashService.php`
- Modify: `stelfaro-platform-api/app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`
- Modify: `stelfaro-platform-api/routes/api.php:165-166`
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php`

**Interfaces:**
- Consumes: `PlatformAccessPolicy::allowedCashRegisterIds()` (Task 1).
- Produces: `CashService::history(Tenant $tenant, ?Collection $allowedRegisterIds, array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator` — cada item es un `CashSession` con `register`, `openedBy`, `closedBy` cargados. Endpoint `GET /api/v1/platform/tenants/{tenant}/cash/history` → `{"data": [...], "meta": {...}}`, cada elemento de `data` con forma
  `{id, business_date, status, register: {id, branch_id, branch_name}, opened_by, closed_by, opening_balance, expected_balance, declared_balance, difference}`.

- [ ] **Step 1: Escribir los tests que deben fallar primero**

Agregar a `tests/Feature/CashRegisterTest.php`, antes de `member()`:

```php
    public function test_history_lists_closed_sessions_with_pagination_and_date_filter(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'closed_by' => $user->id, 'opening_balance' => 50, 'expected_balance' => 80, 'declared_balance' => 80, 'difference' => 0, 'business_date' => '2026-08-10', 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 40, 'expected_balance' => 95, 'declared_balance' => null, 'difference' => null, 'business_date' => '2026-08-20', 'opening_source' => 'manual', 'count_status' => 'pending_count', 'status' => 'closed_unverified', 'opened_at' => now(), 'closed_at' => now()]);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'business_date' => today(), 'opening_source' => 'manual', 'count_status' => 'pending', 'status' => 'open', 'opened_at' => now()]);

        $response = $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history")
            ->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.business_date', '2026-08-20')
            ->assertJsonPath('data.0.status', 'closed_unverified')
            ->assertJsonPath('data.0.declared_balance', null)
            ->assertJsonPath('data.1.business_date', '2026-08-10')
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.declared_balance', 80)
            ->assertJsonPath('data.1.difference', 0)
            ->assertJsonPath('data.1.opened_by', $user->name)
            ->assertJsonPath('data.1.closed_by', $user->name);

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history?date_from=2026-08-15")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_date', '2026-08-20');
    }

    public function test_cashier_cannot_request_history_of_another_branch(): void
    {
        [$user, $tenant] = $this->cashierMember(assignedSucursalId: 5);
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 9, 'core_sucursal_code' => 'S009', 'core_sucursal_name' => 'Otra sucursal', 'name' => 'Caja otra sucursal', 'status' => 'active']);
        CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_balance' => 0, 'declared_balance' => 0, 'difference' => 0, 'business_date' => '2026-08-10', 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);

        $this->actingAs($user)->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history?cash_register_id={$register->id}")
            ->assertForbidden();
        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/cash/history")->assertOk()->assertJsonCount(0, 'data');
    }
```

- [ ] **Step 2: Ejecutar los tests nuevos y confirmar que fallan**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: FAIL — ruta `/cash/history` no existe (404), ambos tests nuevos fallan.

- [ ] **Step 3: Agregar `CashService::history()`**

En `app/Services/Cash/CashService.php`, agregar el método junto a `consolidated()`:

```php
    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, CashSession> */
    public function history(Tenant $tenant, ?Collection $allowedRegisterIds, array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return CashSession::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['closed', 'closed_unverified'])
            ->when($allowedRegisterIds !== null, fn ($query) => $query->whereIn('cash_register_id', $allowedRegisterIds))
            ->when($filters['cash_register_id'] ?? null, fn ($query, $registerId) => $query->where('cash_register_id', $registerId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('business_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('business_date', '<=', $date))
            ->with(['register', 'openedBy', 'closedBy'])
            ->latest('business_date')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
```

- [ ] **Step 4: Agregar el método `history()` al controlador y la ruta**

En `app/Http/Controllers/Api/V1/Platform/CashRegisterController.php`, agregar (junto a `consolidated()`):

```php
    public function history(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $allowedRegisterIds = $policy->allowedCashRegisterIds($request->user(), $tenant);
        if ($allowedRegisterIds !== null && isset($data['cash_register_id'])) {
            abort_unless($allowedRegisterIds->contains((int) $data['cash_register_id']), 403);
        }
        $sessions = $cash->history($tenant, $allowedRegisterIds, $data);

        return response()->json([
            'data' => collect($sessions->items())->map(fn (CashSession $session) => $this->historyPayload($session))->values(),
            'meta' => ['current_page' => $sessions->currentPage(), 'last_page' => $sessions->lastPage(), 'total' => $sessions->total()],
        ]);
    }

    private function historyPayload(CashSession $session): array
    {
        return [
            'id' => $session->id,
            'business_date' => $session->business_date?->toDateString(),
            'status' => $session->status,
            'register' => ['id' => $session->register->id, 'branch_id' => $session->register->core_sucursal_id, 'branch_name' => $session->register->core_sucursal_name],
            'opened_by' => $session->openedBy?->name,
            'closed_by' => $session->closedBy?->name,
            'opening_balance' => (float) $session->opening_balance,
            'expected_balance' => $session->expected_balance !== null ? (float) $session->expected_balance : null,
            'declared_balance' => $session->declared_balance !== null ? (float) $session->declared_balance : null,
            'difference' => $session->difference !== null ? (float) $session->difference : null,
        ];
    }
```

En `routes/api.php`, dentro del grupo `platform/tenants/{tenant}/cash`, agregar la ruta justo
después de `consolidated`:

```php
                Route::get('consolidated', [CashRegisterController::class, 'consolidated']);
                Route::get('history', [CashRegisterController::class, 'history']);
```

- [ ] **Step 5: Ejecutar la suite de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: todos los tests anteriores + los 2 nuevos pasan.

- [ ] **Step 6: Ejecutar toda la suite del proyecto**

Run: `cd stelfaro-platform-api && php artisan test`
Expected: mismo conteo de fallas preexistentes que antes de este task (verifica contra el
conteo actual antes de empezar — no debe aumentar).

- [ ] **Step 7: Commit**

```bash
cd stelfaro-platform-api
git add app/Services/Cash/CashService.php \
        app/Http/Controllers/Api/V1/Platform/CashRegisterController.php \
        routes/api.php \
        tests/Feature/CashRegisterTest.php
git commit -m "feat(cash): endpoint de historial de cierres paginado por sucursal"
```

---

## Task 3: `recent_closures` en el consolidado

**Files:**
- Modify: `stelfaro-platform-api/app/Services/Cash/CashService.php:203-241`
- Test: `stelfaro-platform-api/tests/Feature/CashRegisterTest.php`

**Interfaces:**
- Produces: `CashService::consolidated()` — cada elemento de la colección gana la clave
  `recent_closures: array<{id:int, business_date:?string, declared_balance:?float, difference:?float, status:string}>`
  con hasta 5 entradas, más recientes primero.

- [ ] **Step 1: Escribir el test que debe fallar primero**

Agregar a `tests/Feature/CashRegisterTest.php`, antes de `member()`:

```php
    public function test_consolidated_includes_up_to_five_recent_closures_per_branch(): void
    {
        [$user, $tenant] = $this->member();
        $register = CashRegister::query()->create(['tenant_id' => $tenant->id, 'core_sucursal_id' => 1, 'core_sucursal_code' => 'M001', 'core_sucursal_name' => 'Casa matriz', 'name' => 'Caja matriz', 'status' => 'active']);
        foreach (range(1, 6) as $day) {
            CashSession::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'closed_by' => $user->id, 'opening_balance' => 10, 'expected_balance' => 20, 'declared_balance' => 20, 'difference' => 0, 'business_date' => sprintf('2026-08-%02d', $day), 'opening_source' => 'manual', 'count_status' => 'counted', 'status' => 'closed', 'opened_at' => now(), 'closed_at' => now()]);
        }

        $consolidated = app(\App\Services\Cash\CashService::class)->consolidated($tenant);

        $this->assertCount(5, $consolidated->first()['recent_closures']);
        $this->assertSame('2026-08-06', $consolidated->first()['recent_closures'][0]['business_date']);
    }
```

- [ ] **Step 2: Ejecutar el test y confirmar que falla**

Run: `cd stelfaro-platform-api && php artisan test --filter=test_consolidated_includes_up_to_five_recent_closures_per_branch`
Expected: FAIL — `recent_closures` no existe en el resultado (assertCount sobre `null` o clave inexistente).

- [ ] **Step 3: Extender `CashService::consolidated()`**

Reemplazar el método completo (líneas 203-241 de `app/Services/Cash/CashService.php`):

```php
    /** @return Collection<int, array{branch_id:int, branch_code:?string, branch_name:?string, register_id:int, status:string, opened_by:?string, opened_at:?string, balance:?float, recent_closures:array}> */
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
                $recentClosures = CashSession::query()
                    ->where('cash_register_id', $register->id)
                    ->whereIn('status', ['closed', 'closed_unverified'])
                    ->latest('business_date')
                    ->limit(5)
                    ->get(['id', 'business_date', 'declared_balance', 'difference', 'status'])
                    ->map(fn (CashSession $closure) => [
                        'id' => $closure->id,
                        'business_date' => $closure->business_date?->toDateString(),
                        'declared_balance' => $closure->declared_balance !== null ? (float) $closure->declared_balance : null,
                        'difference' => $closure->difference !== null ? (float) $closure->difference : null,
                        'status' => $closure->status,
                    ])
                    ->values();

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
                        'recent_closures' => $recentClosures,
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
                    'recent_closures' => $recentClosures,
                ];
            });
    }
```

- [ ] **Step 4: Ejecutar el test y confirmar que pasa**

Run: `cd stelfaro-platform-api && php artisan test --filter=test_consolidated_includes_up_to_five_recent_closures_per_branch`
Expected: `1 passed`

- [ ] **Step 5: Ejecutar toda la suite de caja**

Run: `cd stelfaro-platform-api && php artisan test --filter=CashRegisterTest`
Expected: todos los tests pasan (incluido `test_owner_sees_consolidated_cash_status_by_branch`, que no debería romperse — solo se agrega una clave nueva al array, no se quita ninguna).

- [ ] **Step 6: Commit**

```bash
cd stelfaro-platform-api
git add app/Services/Cash/CashService.php tests/Feature/CashRegisterTest.php
git commit -m "feat(cash): incluir los últimos 5 cierres por sucursal en el consolidado"
```

---

## Task 4: Cliente API frontend — tipos y método para historial + `recent_closures`

**Files:**
- Modify: `stelfaro-platform/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: `PlatformClient.cashHistory(tenantId: number, params?): Promise<PlatformCashHistory>`;
  tipos `PlatformCashClosure`, `PlatformCashHistoryEntry`, `PlatformCashHistory`; el tipo
  `PlatformCashConsolidatedBranch` (Task 4 del feature anterior) gana el campo
  `recent_closures: PlatformCashClosure[]`.

- [ ] **Step 1: Agregar el tipo `PlatformCashClosure` y extender `PlatformCashConsolidatedBranch`**

Localizar (fue agregado en el feature anterior, justo después de `PlatformCashOverview`):

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

Reemplazar por:

```ts
export type PlatformCashClosure = {
  id: number;
  business_date: string | null;
  declared_balance: number | null;
  difference: number | null;
  status: "closed" | "closed_unverified";
};
export type PlatformCashConsolidatedBranch = {
  branch_id: number;
  branch_code: string | null;
  branch_name: string | null;
  register_id: number;
  status: "open" | "closed";
  opened_by: string | null;
  opened_at: string | null;
  balance: number | null;
  recent_closures: PlatformCashClosure[];
};
export type PlatformCashConsolidated = {
  data: PlatformCashConsolidatedBranch[];
  has_multiple_branches: boolean;
};
export type PlatformCashHistoryEntry = {
  id: number;
  business_date: string | null;
  status: "closed" | "closed_unverified";
  register: { id: number; branch_id: number | null; branch_name: string | null };
  opened_by: string | null;
  closed_by: string | null;
  opening_balance: number;
  expected_balance: number | null;
  declared_balance: number | null;
  difference: number | null;
};
export type PlatformCashHistory = {
  data: PlatformCashHistoryEntry[];
  meta: { current_page: number; last_page: number; total: number };
};
```

- [ ] **Step 2: Agregar el método `cashHistory` al cliente**

Justo después del método `cashRegistersConsolidated(...)` (agregado en el feature anterior), agregar:

```ts
  cashHistory(
    tenantId: number,
    params: {
      cash_register_id?: number;
      date_from?: string;
      date_to?: string;
      page?: number;
      per_page?: number;
    } = {},
  ): Promise<PlatformCashHistory> {
    return this.http
      .get(`platform/tenants/${tenantId}/cash/history`, {
        searchParams: compactParams(params),
      })
      .json();
  }
```

- [ ] **Step 3: Verificar que el paquete compila dentro del build del proyecto**

Run: `cd stelfaro-platform-api && npm run build`
Expected: `✓ built in ...`, sin errores.

- [ ] **Step 4: Commit**

```bash
cd stelfaro-platform
git add packages/api-client/src/index.ts
git commit -m "feat(api-client): agregar cashHistory y recent_closures"
```

---

## Task 5: Pestaña "Historial" en `CashPage.vue`

**Files:**
- Modify: `stelfaro-platform/packages/billing/src/pages/CashPage.vue`

**Interfaces:**
- Consumes: `PlatformClient.cashHistory(tenantId, params)` (Task 4).

- [ ] **Step 1: Ampliar el tipo de `tab` y agregar el botón de la pestaña**

En `<script setup>`, reemplazar:

```ts
const tab = ref<'cash'|'sales'>('cash'); const loading = ref(false); const overview = ref<PlatformCashOverview|null>(null); const toasts = ref<any[]>([]);
```

por:

```ts
const tab = ref<'cash'|'sales'|'history'>('cash'); const loading = ref(false); const overview = ref<PlatformCashOverview|null>(null); const toasts = ref<any[]>([]);
const history = ref<PlatformCashHistory|null>(null); const historyPage = ref(1); const historyFilters = reactive({ date_from: '', date_to: '' });
```

Agregar `PlatformCashHistory` al import de tipos en la línea 2 (junto a `PlatformCashOverview`):

```ts
import { PlatformClient, type BillingEmpresa, type PlatformCashHistory, type PlatformCashOverview, type PlatformInventoryPurchase, type PlatformInventorySupplier, type WorkshopOrder } from '@stelfaro/api-client';
```

En el template, en la barra de botones junto a "Caja" y "Ventas" (línea ~57), agregar el
botón nuevo después de `Ventas`:

```html
<UiButton variant="secondary" @click="tab = 'history'"><CalendarDays class="h-4 w-4" />Historial</UiButton>
```

Agregar `CalendarDays` al import de `lucide-vue-next` (línea 4):

```ts
import { ArrowDownLeft, ArrowUpRight, Banknote, CalendarDays, CalendarRange, LockKeyhole, Plus, RefreshCw } from 'lucide-vue-next';
```

- [ ] **Step 2: Agregar `loadHistory()` y conectar el botón de refrescar**

Agregar la función junto a `loadCash()`:

```ts
async function loadHistory() {
  loading.value = true;
  try {
    history.value = await client.value.cashHistory(props.tenantId, {
      cash_register_id: selectedRegisterId.value ? Number(selectedRegisterId.value) : undefined,
      date_from: historyFilters.date_from || undefined,
      date_to: historyFilters.date_to || undefined,
      page: historyPage.value,
    });
  } catch (error) {
    notify('No pudimos cargar el historial', errorMessage(error), 'error');
  } finally {
    loading.value = false;
  }
}
function goHistoryPage(page: number) { historyPage.value = page; void loadHistory(); }
```

Reemplazar el botón de refrescar (línea ~57) que hoy dice:

```html
<UiButton variant="ghost" :disabled="loading" aria-label="Actualizar" @click="tab === 'cash' ? loadCash() : reportPanel?.reload()"><RefreshCw class="h-4 w-4" :class="loading ? 'animate-spin' : ''" /></UiButton>
```

por:

```html
<UiButton variant="ghost" :disabled="loading" aria-label="Actualizar" @click="tab === 'cash' ? loadCash() : tab === 'sales' ? reportPanel?.reload() : loadHistory()"><RefreshCw class="h-4 w-4" :class="loading ? 'animate-spin' : ''" /></UiButton>
```

Agregar un `watch` para recargar el historial al cambiar de pestaña o de caja seleccionada,
junto al `watch(selectedRegisterId, ...)` ya existente:

```ts
watch(tab, (value) => { if (value === 'history' && !history.value) void loadHistory(); });
watch(selectedRegisterId, () => { if (tab.value === 'history') { historyPage.value = 1; void loadHistory(); } });
```

- [ ] **Step 3: Agregar la sección del template para la pestaña Historial**

Justo después del `<template v-if="tab === 'cash'">...</template>` (antes de la línea
`<CommercialSalesReportPanel v-else ...`), insertar:

```html
    <template v-else-if="tab === 'history'">
      <section class="flex flex-wrap items-end gap-3">
        <UiInput v-model="historyFilters.date_from" type="date" label="Desde" @update:model-value="goHistoryPage(1)" />
        <UiInput v-model="historyFilters.date_to" type="date" label="Hasta" @update:model-value="goHistoryPage(1)" />
      </section>
      <UiCard class="overflow-hidden p-0">
        <div v-if="history?.data.length" class="divide-y divide-line">
          <div v-for="entry in history.data" :key="entry.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-text">{{ entry.business_date }} · {{ entry.register.branch_name }}</p>
              <p class="mt-0.5 text-xs text-muted">Abrió {{ entry.opened_by || '—' }} · Cerró {{ entry.closed_by || 'Sin confirmar' }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm text-muted">Esperado {{ money(entry.expected_balance || 0) }}</p>
              <p v-if="entry.status === 'closed_unverified'" class="text-sm font-semibold text-warning">Sin confirmar</p>
              <p v-else class="text-sm font-bold" :class="Math.abs(entry.difference || 0) < 0.01 ? 'text-success' : 'text-warning'">Diferencia {{ money(entry.difference || 0) }}</p>
            </div>
          </div>
        </div>
        <p v-else class="px-5 py-12 text-center text-sm text-muted">No hay cierres registrados en este período.</p>
      </UiCard>
      <div v-if="history && history.meta.last_page > 1" class="flex items-center justify-center gap-2">
        <UiButton variant="ghost" size="sm" :disabled="history.meta.current_page <= 1" @click="goHistoryPage(history.meta.current_page - 1)">Anterior</UiButton>
        <span class="text-sm text-muted">Página {{ history.meta.current_page }} de {{ history.meta.last_page }}</span>
        <UiButton variant="ghost" size="sm" :disabled="history.meta.current_page >= history.meta.last_page" @click="goHistoryPage(history.meta.current_page + 1)">Siguiente</UiButton>
      </div>
    </template>
```

- [ ] **Step 4: Leer los parámetros de query para el deep-link desde el dashboard**

En `onMounted`, reemplazar:

```ts
onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'sales') tab.value = 'sales';
  void Promise.all([loadCash(), loadRelations()]);
});
```

por:

```ts
onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'sales') tab.value = 'sales';
  if (params.get('tab') === 'history') tab.value = 'history';
  const registerParam = params.get('cash_register_id');
  if (registerParam) selectedRegisterId.value = registerParam;
  void Promise.all([loadCash(), loadRelations()]);
  if (tab.value === 'history') void loadHistory();
});
```

- [ ] **Step 5: Verificar que el build pasa**

Run: `cd stelfaro-platform-api && npm run build`
Expected: `✓ built in ...`, sin errores.

- [ ] **Step 6: Verificar typecheck del monorepo frontend**

Run: `cd stelfaro-platform && pnpm --filter @stelfaro/billing-demo typecheck`
Expected: sin errores (el `tsconfig.json` de `apps/billing-demo` incluye `packages/**/*.vue`, así que valida `CashPage.vue` en modo estricto).

- [ ] **Step 7: Commit**

```bash
cd stelfaro-platform
git add packages/billing/src/pages/CashPage.vue
git commit -m "feat(cash): pestaña de historial de cierres en Caja"
```

---

## Task 6: Carrusel en `CashConsolidatedCard.vue`

**Files:**
- Modify: `stelfaro-platform/packages/billing/src/components/CashConsolidatedCard.vue`
- Modify: `stelfaro-platform/packages/billing/src/pages/BillingDashboardPage.vue`

**Interfaces:**
- Consumes: `PlatformCashConsolidatedBranch.recent_closures` (Task 4).
- Produces: `CashConsolidatedCard` gana la prop `appBaseUrl: string` (para construir el link a Caja).

- [ ] **Step 1: Reescribir `CashConsolidatedCard.vue` con el carrusel**

Reemplazar el archivo completo:

```vue
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, LockKeyhole, RefreshCw, Store } from 'lucide-vue-next';
import type { PlatformCashConsolidatedBranch, PlatformClient } from '@stelfaro/api-client';

const props = defineProps<{
  platform: PlatformClient;
  tenantId: number;
  appBaseUrl: string;
}>();

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
}>();

const branches = ref<PlatformCashConsolidatedBranch[]>([]);
const loading = ref(false);
const error = ref('');
const activeIndex = ref(0);
const track = ref<HTMLElement | null>(null);

const base = computed(() => props.appBaseUrl.replace(/\/$/, ''));

const money = (value: number | null) =>
  new Intl.NumberFormat('es-SV', { style: 'currency', currency: 'USD' }).format(value ?? 0);
const dateShort = (value: string | null) =>
  value ? new Intl.DateTimeFormat('es-SV', { day: '2-digit', month: 'short' }).format(new Date(`${value}T00:00:00`)) : '—';
function historyHref(branch: PlatformCashConsolidatedBranch) {
  return `${base.value}/caja?tab=history&cash_register_id=${branch.register_id}`;
}

async function load() {
  if (!props.tenantId) return;
  loading.value = true;
  error.value = '';
  try {
    const result = await props.platform.cashRegistersConsolidated(props.tenantId);
    branches.value = result.data;
    activeIndex.value = 0;
    emit('update:visible', result.has_multiple_branches);
  } catch {
    branches.value = [];
    emit('update:visible', false);
  } finally {
    loading.value = false;
  }
}

function goTo(index: number) {
  if (index < 0 || index >= branches.value.length || !track.value) return;
  activeIndex.value = index;
  const slide = track.value.children[index] as HTMLElement | undefined;
  slide?.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
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
          <p class="text-xs text-muted">Estado en vivo y últimos cierres</p>
        </div>
      </div>
      <div class="flex items-center gap-1">
        <button
          v-if="branches.length > 1"
          type="button"
          class="grid h-8 w-8 place-items-center rounded-full border border-line bg-surface text-muted transition enabled:hover:bg-surface-muted disabled:opacity-40"
          :disabled="activeIndex <= 0"
          aria-label="Sucursal anterior"
          @click="goTo(activeIndex - 1)"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>
        <button
          v-if="branches.length > 1"
          type="button"
          class="grid h-8 w-8 place-items-center rounded-full border border-line bg-surface text-muted transition enabled:hover:bg-surface-muted disabled:opacity-40"
          :disabled="activeIndex >= branches.length - 1"
          aria-label="Siguiente sucursal"
          @click="goTo(activeIndex + 1)"
        >
          <ChevronRight class="h-4 w-4" />
        </button>
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
    </div>

    <p v-if="error" class="px-4 py-4 text-sm text-danger">{{ error }}</p>
    <p v-else-if="loading && !branches.length" class="px-4 py-6 text-center text-sm text-muted">Cargando…</p>

    <div v-else ref="track" class="flex snap-x snap-mandatory overflow-x-auto scroll-smooth">
      <div v-for="branch in branches" :key="branch.branch_id" class="w-full shrink-0 snap-start px-4 py-3.5">
        <div class="flex items-center justify-between gap-3">
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
        </div>

        <div v-if="branch.recent_closures.length" class="mt-3 space-y-1 border-t border-line pt-3">
          <p class="text-xs font-semibold uppercase tracking-wide text-soft">Últimos cierres</p>
          <a
            v-for="closure in branch.recent_closures"
            :key="closure.id"
            :href="historyHref(branch)"
            class="flex items-center justify-between gap-3 rounded-lg px-1 py-1.5 text-sm transition hover:bg-surface-muted"
          >
            <span class="text-muted">{{ dateShort(closure.business_date) }}</span>
            <span v-if="closure.status === 'closed_unverified'" class="font-semibold text-warning">Sin confirmar</span>
            <span v-else class="font-semibold" :class="Math.abs(closure.difference ?? 0) < 0.01 ? 'text-success' : 'text-warning'">
              {{ money(closure.difference) }}
            </span>
          </a>
        </div>
      </div>
    </div>

    <div v-if="branches.length > 1" class="flex justify-center gap-1.5 border-t border-line py-2.5">
      <button
        v-for="(branch, index) in branches"
        :key="branch.branch_id"
        type="button"
        class="h-1.5 rounded-full transition-all"
        :class="index === activeIndex ? 'w-4 bg-primary' : 'w-1.5 bg-line-strong'"
        :aria-label="`Ir a ${branch.branch_name}`"
        @click="goTo(index)"
      ></button>
    </div>
  </section>
</template>
```

- [ ] **Step 2: Pasar `appBaseUrl` desde `BillingDashboardPage.vue`**

En `stelfaro-platform/packages/billing/src/pages/BillingDashboardPage.vue`, en el bloque
donde se monta `CashConsolidatedCard` (agregado en el feature anterior), agregar la prop:

```html
    <CashConsolidatedCard
      v-if="tenantId"
      v-show="cashConsolidatedVisible"
      :platform="platform"
      :tenant-id="tenantId"
      :app-base-url="appBaseUrl"
      @update:visible="cashConsolidatedVisible = $event"
    />
```

(`appBaseUrl` ya es una prop existente de `BillingDashboardPage.vue`, usada en el `computed base` de la línea 13 — no requiere ningún cambio adicional en las props del componente).

- [ ] **Step 3: Verificar que el build pasa**

Run: `cd stelfaro-platform-api && npm run build`
Expected: `✓ built in ...`, sin errores.

- [ ] **Step 4: Verificar typecheck del monorepo frontend**

Run: `cd stelfaro-platform && pnpm --filter @stelfaro/billing-demo typecheck`
Expected: sin errores.

- [ ] **Step 5: Verificación visual manual (requiere navegador — dejar para el usuario)**

Con una cuenta owner/company_admin de un tenant con 2+ sucursales: entrar al dashboard,
confirmar que la tarjeta "Cajas por sucursal" ahora es un carrusel — las flechas y los
puntos cambian de sucursal, cada slide muestra su estado en vivo y hasta 5 cierres
recientes debajo, y hacer click en un cierre navega a Caja con la pestaña "Historial"
abierta y esa sucursal preseleccionada.

- [ ] **Step 6: Commit**

```bash
cd stelfaro-platform
git add packages/billing/src/components/CashConsolidatedCard.vue \
        packages/billing/src/pages/BillingDashboardPage.vue
git commit -m "feat(dashboard): carrusel de sucursales con últimos cierres en el consolidado"
```

---

## Self-Review

**Cobertura del spec:**
- Sección 1 (endpoint de historial + recientes embebidos) → Tasks 1, 2, 3.
- Sección 2 (pestaña Historial en CashPage) → Task 5.
- Sección 3 (carrusel del dashboard) → Task 6.
- El punto del aviso de `closed_unverified` está explícitamente fuera de alcance (ya existe) — no hay task para eso, correcto según el spec.

**Placeholders:** ninguno — cada step trae el código completo a escribir.

**Consistencia de tipos:** `PlatformCashClosure`/`PlatformCashHistoryEntry`/`PlatformCashHistory` (Task 4) coinciden campo a campo con `CashService::history()` + `CashRegisterController::historyPayload()` (Task 2) y con el `recent_closures` de `CashService::consolidated()` (Task 3). `CashConsolidatedCard.vue` (Task 6) consume `branch.recent_closures` con los mismos nombres. `allowedCashRegisterIds()` (Task 1) se usa en `overview()` (Task 1) y en `history()` (Task 2) con la misma firma.

**Alcance:** cada task deja el sistema funcionando — Task 1 es un refactor puro y ya es segura sola; Tasks 2-3 son aditivas en el backend; Tasks 4-6 son aditivas en el frontend y dependen de que 1-3 ya estén mergeadas (los tipos y el endpoint deben existir primero).
