# Historial de cierres de caja + aviso de cierre sin confirmar

**Fecha:** 2026-08-30
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto

Tras implementar "1 caja por sucursal" y el consolidado en vivo del dashboard
(spec `2026-08-30-consolidacion-cajas-por-sucursal-design.md`), quedaron dos huecos:

1. El aviso de que una sucursal tiene un cierre automático sin confirmar
   (`closed_unverified`) — **investigado y ya existe**: `CashAutomationService::autoCutoff()`
   ya crea un `InternalNotification` (categoría `cash`, título "Caja pendiente de conteo")
   para **todos** los miembros activos del tenant, incluyendo `owner`/`company_admin`. No
   requiere cambio de código. El motivo por el que no se había visto en dev es que el
   scheduler de Laravel (`Schedule::call(...)->everyMinute()`) no tiene entrada de cron en
   este entorno — se soluciona corriendo `php artisan schedule:work` o agregando el cron,
   no es parte de este spec.
2. **No existe historial de cierres de caja** — `CashService::consolidated()` solo mira el
   último cierre por sucursal; no hay una vista para repasar cierres pasados con su
   diferencia de arqueo.

Este spec cubre únicamente el punto 2.

## Objetivo

- Una pestaña "Historial" dentro de Caja (`CashPage.vue`) con la lista de cierres pasados
  (fecha, quién abrió/cerró, saldo esperado, saldo declarado, diferencia), filtrable por
  rango de fechas y paginada.
- El cajero ve el historial de su propia sucursal (igual que ya ve el resto de Caja); los
  roles administrativos ven todas las sucursales o la que seleccionen.
- La tarjeta "Cajas por sucursal" del dashboard gana un carrusel: cada slide es una
  sucursal, mostrando su estado en vivo (igual que hoy) más una mini-lista de sus últimos
  5 cierres, con navegación por flechas y swipe táctil.

## Fuera de alcance

- Exportar a Excel/PDF, gráficos, comparativas entre sucursales — puede venir después si
  hace falta.
- Cambiar el mecanismo de notificación de `closed_unverified` — ya existe y funciona.
- Tocar el scheduler/cron de dev — es un tema operativo aparte, no de código.

## Diseño

### 1. Backend: endpoint de historial + recientes embebidos en el consolidado

- Nuevo método `PlatformAccessPolicy::allowedCashRegisterIds(?User $user, Tenant|int $tenant): ?Collection`:
  extrae el criterio "qué cajas puede ver/operar esta membresía" que hoy vive duplicado
  inline en `CashRegisterController::overview()`. Devuelve `null` si no hay restricción
  (roles administrativos y globales), o la colección de IDs de `cash_registers` permitidos
  si la membresía es `billing_user` (cajero), derivados de sus `fiscalAssignments` activas.
  `overview()` se refactoriza para usar este método en vez de su cálculo inline — reduce la
  duplicación que la revisión final del feature anterior ya había señalado como pendiente,
  acotado a los dos sitios que genuinamente construían la misma lista (no se toca
  `branchForOpening()` ni `canOperateCashRegister()`, que resuelven un problema distinto:
  una sucursal preferente o una comprobación puntual, no una lista).
- Nuevo método `CashService::history(Tenant $tenant, ?Collection $allowedRegisterIds, array $filters): LengthAwarePaginator` —
  consulta `CashSession` con `status` en (`closed`, `closed_unverified`), `with(['register', 'openedBy', 'closedBy'])`,
  filtrado opcionalmente por `cash_register_id`, `date_from`/`date_to` (sobre `business_date`),
  ordenado por `business_date` descendente, paginado. Requiere agregar la relación
  `closedBy(): BelongsTo` a `CashSession` (mismo patrón que `openedBy()`, ya existente).
- Nuevo endpoint `GET /cash/history` en `CashRegisterController`, dentro del mismo grupo de
  rutas `platform/tenants/{tenant}/cash`. Autorización: `canViewTenantCatalog` (igual que
  `overview()`) + el mismo acotamiento de `allowedCashRegisterIds()` que `overview()` ya
  aplica (si el cajero pide un `cash_register_id` fuera de su alcance, 403). Respuesta:
  lista de `{id, business_date, register: {id, branch_id, branch_name}, opened_by, closed_by,
  opening_balance, expected_balance, declared_balance, difference, status}` + `meta` de
  paginación (mismo shape que ya usa `overview()`).
- `CashService::consolidated()` gana un campo nuevo por sucursal: `recent_closures` — los
  últimos 5 registros de `CashSession` con `status` en (`closed`, `closed_unverified`) para
  ese `cash_register_id`, ordenados por `business_date` descendente, con el mismo shape
  reducido `{id, business_date, declared_balance, difference, status}` (sin `opened_by`/
  `closed_by`, no hace falta en el carrusel). Evita que el carrusel del dashboard necesite
  una llamada adicional por sucursal.

### 2. Pestaña "Historial" en `CashPage.vue`

- `tab` pasa de `ref<'cash'|'sales'>` a `ref<'cash'|'sales'|'history'>`, con su botón junto
  a "Caja" y "Ventas".
- Nuevo estado `history` (lista + meta de paginación) y `historyFilters` (`date_from`,
  `date_to`), cargado vía `client.cashHistory(tenantId, { cash_register_id, date_from,
  date_to, page })` — usa `selectedRegisterId` igual que las demás pestañas (para el
  cajero llega vacío/su propia caja porque el backend ya la acota).
- Tabla: fecha, sucursal (columna oculta si solo hay una caja visible), abrió/cerró, saldo
  esperado, saldo declarado (o badge "Sin confirmar" si `status = closed_unverified`),
  diferencia coloreada (verde si 0, ámbar/rojo si no) — mismo criterio visual que ya usa el
  modal de cierre (`closingDifference`).
- Filtro de fecha: dos `UiInput type="date"` simples, recarga al cambiar.
- Paginación simple con botones anterior/siguiente sobre `meta.current_page`/`last_page`.

### 3. Carrusel en `CashConsolidatedCard.vue`

- La tarjeta pasa de una lista vertical de sucursales a un carrusel horizontal con
  `scroll-snap-type: x mandatory` (CSS puro, sin librería nueva) — un slide por sucursal,
  cada uno con: el bloque de estado en vivo que ya existe hoy (nombre, badge
  abierta/cerrada, saldo), y debajo una mini-lista de `recent_closures` (fecha corta +
  diferencia, sin badge de estado para no sobrecargar).
- Navegación con flechas `ChevronLeft`/`ChevronRight` (mismo ícono y posición que ya usa
  `SucursalActivityCard.vue` para navegar periodos) que hacen `scrollTo` sobre el
  contenedor; puntos indicadores de posición cuando hay más de una sucursal.
- Click en un cierre de la mini-lista navega a `/caja?tab=history&sucursal=<branch_id>`.
  `CashPage.vue` lee esos query params al montar (patrón ya existente: hoy ya lee `?tab=sales`)
  para preseleccionar la pestaña "Historial" y la sucursal correspondiente.
- Si el tenant tiene una sola sucursal, la tarjeta completa sigue ocultándose (sin cambios
  en esa condición).

## Testing

- Backend: tests de feature para `GET /cash/history` — paginación, filtro por fecha, 403 si
  un cajero pide `cash_register_id` de otra sucursal, y que devuelve tanto `closed` como
  `closed_unverified`. Test de `allowedCashRegisterIds()` a través del comportamiento ya
  cubierto de `overview()` (no debe cambiar su resultado tras el refactor — regresión).
  Test de que `consolidated()` incluye `recent_closures` con el orden y tope de 5 esperado.
- Frontend: sin suite de tests para `packages/billing` (confirmado en el spec anterior);
  verificación por build (`npm run build` en `stelfaro-platform-api`) + `pnpm typecheck`/
  `pnpm test` en `stelfaro-platform` (el `apps/billing-demo` sí tiene ambos, confirmado en
  la revisión final del feature anterior). Verificación visual manual del carrusel y la
  pestaña de historial queda para el usuario, igual que con el feature anterior.
