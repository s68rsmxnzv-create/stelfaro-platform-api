# Formalización de "1 caja por sucursal" y consolidado gerencial

**Fecha:** 2026-08-30
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto

El módulo de caja (`cash_registers`, `cash_sessions`, `cash_movements`, `cash_expenses`, vive en
`stelfaro-platform-api`) ya soporta parcialmente el concepto de sucursal a través de una columna
desnormalizada `core_sucursal_id` en `cash_registers`, que referencia la sucursal real modelada en
el microservicio externo `dte-core` (`Empresa → Sucursal → PuntoVenta → Correlativo`). Sin embargo:

- `core_sucursal_id` es nullable y no forma parte real de la identidad de la caja: hoy es posible
  crear más de una caja para la misma sucursal simplemente usando un `name` distinto
  (`CashService::defaultRegister()` hace `firstOrCreate` con clave `[tenant_id, core_sucursal_id, name]`).
- El control de acceso de `CashRegisterController` solo exige `PlatformAccessPolicy::canOperateTenant()`
  (rol de tenant), sin relacionar la caja con la sucursal que el usuario tiene asignada. Esto contrasta
  con la emisión de DTE, donde un cajero (`billing_user`) ya está atado a una sucursal + punto de venta
  fijos vía `user_fiscal_assignments`, con 3 capas de permisos implementadas
  (ver spec `2026-08-28-rol-cajero-y-vista-pos-design.md`). El endurecimiento del rol cajero de esa
  fecha no tocó el módulo `cash`.
- No existe hoy una vista que consolide el estado de todas las cajas de un tenant multi-sucursal para
  `company_admin`/`owner`.

Verificación de datos (2026-08-30): la tabla `cash_registers` tiene solo 2 filas, ambas con
`core_sucursal_id` poblado y sin duplicados por sucursal — la migración de esquema no requiere paso
de fusión de datos.

## Objetivo

1. Convertir "1 caja por sucursal" en la única forma de operar: cada sucursal tiene exactamente una
   caja activa, sin opción de cajas globales (sin sucursal) ni de múltiples cajas por sucursal.
2. Cerrar el hueco de permisos: un cajero (`billing_user`) solo puede abrir/operar la caja de la
   sucursal que tiene asignada en `user_fiscal_assignments`, igual que ya ocurre con la emisión de DTE.
3. Dar a `company_admin` y `owner` (y roles globales de plataforma) un consolidado en vivo del estado
   de todas las cajas del tenant, accesible como tarjeta en el dashboard existente.

## Fuera de alcance (no-objetivos)

- Restringir a `seller` o a otros roles a su sucursal asignada — se deja como extensión futura, el
  diseño de permisos es extensible a otros roles sin rediseño (ver Sección 2).
- Historial/reportes de cierres pasados por sucursal en el consolidado — la primera versión solo
  muestra estado en vivo + saldo actual (decisión explícita del usuario).
- Página dedicada de consolidado — es una tarjeta/widget en el dashboard existente, no una pantalla nueva.
- Tabla local `sucursales` sincronizada desde `dte-core` (Enfoque B evaluado y descartado, ver
  "Decisión de arquitectura").
- Rotación de cajeros entre varias cajas — sigue siendo "1 cajero = 1 caja" (decisión ya tomada en el
  spec de rol cajero), sin cambios aquí.

## Decisión de arquitectura: sin tabla local de sucursales

Se evaluaron dos enfoques para formalizar la relación caja↔sucursal:

- **Enfoque A (elegido):** mantener `core_sucursal_id` como el único vínculo, sin tabla local, pero
  endureciendo su uso: columna `NOT NULL`, índice único parcial en Postgres para garantizar una sola
  caja activa por sucursal, y falla explícita (en vez de fallback silencioso) cuando no se puede
  resolver una sucursal. Es la extensión mínima del patrón que ya usan `workshop_orders`,
  `inventory_lots` y el resto de tablas de negocio que ya cachean `core_sucursal_id/code/name`.
- **Enfoque B (descartado):** tabla `sucursales` local sincronizada desde `dte-core` con FK real.
  Da integridad referencial real, pero introduce una segunda fuente de verdad (job de sincronización,
  manejo de eventos de alta/edición de sucursal en `dte-core`, riesgo de desincronización) que no se
  justifica con solo 2 cajas en producción y con el patrón de "consulta en vivo con try/catch" ya
  estandarizado en el resto del código (`CoreFiscalScopeClient`, `resolveMainBranch`,
  `SucursalScopeFilter`).

## Diseño

### 1. Esquema de datos e invariante "1 caja por sucursal"

- Nueva migración en `stelfaro-platform-api`:
  - `cash_registers.core_sucursal_id` pasa de nullable a `NOT NULL`.
  - Se elimina el índice único existente `(tenant_id, name)`.
  - Se agrega un índice único parcial `(tenant_id, core_sucursal_id) WHERE status = 'active'`
    (sintaxis Postgres, motor confirmado como `pgsql`). Permite retirar una caja
    (`status = 'inactive'`) y abrir una nueva para esa misma sucursal más adelante, pero nunca dos
    cajas activas simultáneas en la misma sucursal.
- `CashService::defaultRegister()`:
  - La clave de `firstOrCreate` cambia de `[tenant_id, core_sucursal_id, name]` a
    `[tenant_id, core_sucursal_id]`. El `name` deja de ser parte de la identidad de la caja.
  - Si `resolveMainBranch()` no logra resolver ninguna sucursal (dte-core no responde, o el tenant no
    tiene sucursales configuradas en dte-core), el método lanza `ValidationException` en vez de crear
    una caja con `core_sucursal_id = NULL` como hace hoy.

### 2. Control de acceso: cajero atado a su sucursal asignada

- Nuevo método en `PlatformAccessPolicy`:

  ```php
  public function canOperateCashRegister(?User $user, Tenant|int $tenant, CashRegister $register): bool
  ```

  - Devuelve `true` si el usuario tiene rol global admin, o si su membresía de tenant es `owner`,
    `company_admin`, `billing_admin` o `seller` (mismo universo que hoy permite `canOperateTenant`,
    menos el caso cajero que se trata aparte). `seller` queda sin restricción de sucursal por ahora
    — es una decisión explícita, no un olvido.
  - Si la membresía es `billing_user` (rol "cajero"): busca la `UserFiscalAssignment` activa asociada
    a esa membresía (`membership_id`) y exige `assignment->core_sucursal_id === $register->core_sucursal_id`.
    Sin asignación o sin coincidencia → `false`.
  - El chequeo se resuelve por rol de membresía en un único punto, así que extenderlo a otros roles
    (p. ej. `seller` en el futuro) es agregar el rol al lado "restringido" del `match`, sin tocar el
    controlador.

- Nuevo método `PlatformAccessPolicy::canViewCashConsolidated(?User $user, Tenant|int $tenant): bool`:
  mismo criterio que `canViewInventoryCosts` (`hasGlobalAdminRole` o membresía `owner`/`company_admin`).
  Cajero, vendedor, billing_admin y viewer quedan fuera.

- `CashRegisterController` aplica `canOperateCashRegister` en los puntos donde ya hay o puede resolverse
  una caja concreta:
  - `open()`: sobre el `$register` resuelto (por `cash_register_id` explícito o por `defaultRegister()`),
    antes de crear la sesión.
  - `close()`: sobre `$cashSession->register`.
  - `storeMovement()`: sobre el registro resuelto vía `activeSession()` o `cash_register_id` explícito.
  - `reverse()`: sobre `$cashMovement->cashRegister` (o relación equivalente).
  - `reconcileExpense()` **no** se toca — no está atado a una caja específica en el momento de la
    conciliación, sigue protegido solo por `canOperateTenant`.

### 3. Endpoint de consolidado (backend)

- Nuevo método `CashService::consolidated(Tenant $tenant): Collection`. Para cada caja **activa** del
  tenant: sucursal (`core_sucursal_id/code/name`), estado (`open`/`closed` según si tiene una
  `CashSession` con `status = 'open'` hoy), quién la abrió y cuándo, `opening_balance`, y si está
  abierta: `sessionTotals()` (reutiliza la lógica ya existente de inflows/outflows/expected). Si no
  hay sesión abierta hoy, se reporta el saldo declarado del último cierre disponible.
- Nuevo endpoint `GET /api/v1/platform/tenants/{tenant}/cash-registers/consolidated` en
  `CashRegisterController`, protegido con `abort_unless($policy->canViewCashConsolidated(...), 403)`.
- Sin paginación — el número de sucursales por tenant es pequeño (hay límite de sucursales por plan
  en `BillingSettingsPage.vue`).

### 4. Frontend: widget en el dashboard y ajustes en CashPage

- Nuevo componente `CashConsolidatedCard.vue` en
  `stelfaro-platform/packages/billing/src/components/`, siguiendo el mismo patrón que
  `SucursalActivityCard.vue`: recibe `platform: PlatformClient` y `tenantId`, carga al montar, y
  emite `update:visible` en `false` cuando el tenant tiene una sola sucursal o cuando el rol actual no
  puede verlo (`canViewCashConsolidated`).
- Se monta en `BillingDashboardPage.vue`, junto a `SucursalActivityCard`, con la misma convención de
  `v-if="empresaId"` + `v-show="visible"`.
- Cada fila: nombre de sucursal, badge abierta/cerrada, saldo esperado actual (si está abierta) o
  saldo declarado del último cierre (si está cerrada), quién la tiene abierta. Click en una fila
  navega a `/caja?sucursal=<id>`.
- `CashPage.vue`: para rol cajero, el selector "Sucursal / caja" deja de mostrarse — se usa
  automáticamente la caja de su sucursal asignada. Para roles admin, el selector se mantiene, pero
  ahora refleja que cada sucursal tiene como máximo una caja (ya no hay ambigüedad de "cuál caja de
  esta sucursal").
- `@stelfaro/api-client`: se agrega el tipo `CashConsolidatedBranch` (o similar) y el método
  `platform.cashRegistersConsolidated(tenantId)`.

## Testing

- Backend: tests de feature para `open`/`close`/`storeMovement`/`reverse` verificando 403 cuando un
  cajero intenta operar la caja de otra sucursal, y éxito cuando coincide con su asignación. Test de
  la migración/índice único parcial (dos intentos de `defaultRegister()` para la misma sucursal deben
  devolver la misma caja, no crear dos). Test del endpoint de consolidado con cada rol (200 para
  owner/company_admin, 403 para cajero/vendedor/viewer).
- Frontend: no se agregan tests nuevos de componente si el proyecto no tiene suite establecida para
  `packages/billing` — verificar patrón existente antes de decidir en el plan de implementación.

## Migración de datos existentes

No se requiere paso de fusión: las 2 cajas existentes ya tienen `core_sucursal_id` único por
sucursal. La migración solo necesita el `NOT NULL` + índice único parcial.
