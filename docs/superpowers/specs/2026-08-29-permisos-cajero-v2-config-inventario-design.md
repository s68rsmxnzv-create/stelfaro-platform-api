# Permisos Cajero v2 — Configuración e Inventario — Diseño

**Fecha:** 2026-08-29
**Estado:** Implementado (2026-08-29) — `stelfaro-platform-api@1c3d19c`, `stelfaro-platform@451519a`
**Repos afectados:** `stelfaro-platform-api`, `stelfaro-platform` (`packages/billing`)
**Rama de trabajo en cada repo:** `integration`
**Depende de:** [2026-08-28-rol-cajero-y-vista-pos-design.md](2026-08-28-rol-cajero-y-vista-pos-design.md) (endurecimiento base + nota de revisión 2026-08-29)

---

## 1. Contexto y problema

Tras revertir la vista POS, el cajero (`billing_user` de tenant → rol fiscal
`cashier`) usa el facturador tradicional con módulos acotados por
`moduleAccess` (`billing`, `customers`, `catalog`, `inventory`, `artifacts`).
Eso resolvió el nivel de **módulo**, pero dos módulos permitidos exponen de más:

- **Configuración** (`BillingCompanySettingsPage`, 12 secciones): el cajero
  entra a la página completa, incluyendo Solicitudes, Suscripción, Caja,
  Auditoría, Cajeros y Formato del ticket.
- **Inventario** (`InventoryPage`, ~1300 líneas, 12 pestañas): el cajero ve
  todo, incluyendo **costos** (`reference_cost`, `cost_source`,
  `stock_value`, `inventory_value`, reporte de margen) y todas las acciones de
  escritura (ajustes, conteos, transferencias, compras, proveedores).

El gating actual (`moduleAccess`) es sólo a nivel de módulo. Además, el costo
se filtra por la **API**: los endpoints de inventario se autorizan con
`PlatformAccessPolicy::canViewTenantCatalog` (cualquier miembro activo), así
que esconder el costo sólo en el frontend no basta — el cajero lo vería en la
respuesta de red.

## 2. Objetivos

1. El cajero en **Configuración** sólo ve: Perfil de usuario, Seguridad,
   Conexión e impresora, Centro de descargas, Soporte.
2. El cajero en **Inventario** ve una vista **dedicada de solo lectura** de
   existencias por sucursal, **sin ningún dato de costo** y sin acciones.
3. El bloqueo de costos es real: la API devuelve 403 o strippea el costo para
   el rol de tenant `billing_user` (y `seller`, `viewer`), no sólo la UII.
4. Mecanismo de gating por sección declarativo y en un solo lugar, sin montar
   un sistema de capacidades genérico (sólo `cashier` está restringido hoy).

## 3. No-objetivos (fuera de alcance)

- Consola de soporte para `company_admin` (spec futuro).
- Gating de items de nav en `BillingAppNav` (follow-up del spec anterior).
- Gating web (Inertia) por rol como tercera capa (follow-up).
- POS v2.
- Limitar la vista de existencias a la sucursal asignada del cajero: por ahora
  ve todas las sucursales del tenant.
- Kardex / movimientos y alertas de stock mínimo para el cajero: sólo stock por
  sucursal en esta versión.
- Cambios en `moduleAccess.cashier` a nivel de módulo: sigue siendo
  `['billing', 'customers', 'catalog', 'inventory', 'artifacts']`.

## 4. Decisiones de diseño

### 4.1 Mecanismo de gating por sección (Enfoque A)

`packages/billing/src/moduleAccess.ts` crece con un segundo mapa:

```ts
// Secciones (pestañas / vistas internas) permitidas por rol dentro de un módulo.
// Un módulo ausente del mapa de un rol = sin restricción de sección.
const sectionAccess: Record<string, Record<string, readonly string[]>> = {
  cashier: {
    settings: ['profile', 'security', 'printer', 'downloads', 'support'],
  },
};

export function allowedSections(
  role: string | null | undefined,
  module: string,
): readonly string[] | null {
  if (!role) return null;
  return sectionAccess[role]?.[module] ?? null; // null = sin restricción
}
```

Alternativas descartadas:
- **B — checks de rol dispersos** en cada página: frágil, difícil de auditar.
- **C — capacidades enviadas desde el backend** en la sesión de plataforma:
  robusto pero desproporcionado para un solo rol restringido.

### 4.2 Inventario: vista dedicada, no `InventoryPage` acotada

Cuando `module === 'inventory'` y el rol fiscal efectivo es `cashier`,
`BillingAppPage` monta **`StockLookupPage.vue`** (nuevo) en lugar de
`InventoryPage`. Aislar en vez de acotar: imposible que un bug de gating
filtre un dato de costo, y `InventoryPage` (con `@ts-nocheck`) no se toca.

### 4.3 Backend: permiso de costo explícito + endpoint sin costo

`PlatformAccessPolicy::canViewInventoryCosts(?User, Tenant|int): bool` — espejo
de `canViewTenantAudit`: `true` para global admin y tenant roles
`owner` / `company_admin` / `billing_admin`; `false` para el resto
(`billing_user`, `seller`, `viewer`).

El stock por sucursal se obtiene de `InventoryLot` (`tenant_id`,
`catalog_item_id`, `core_sucursal_id`, `available_quantity`). Los nombres de
sucursal se resuelven con el mismo resolver de scope fiscal que ya usa el
portal (`PlatformSessionResolver` / `CoreBillingSessionBroker` exponen
`sucursales` con `id`, `codigo`, `nombre`); si el endpoint no tiene acceso
directo, devuelve `sucursal_id` + `codigo` y el front resuelve el nombre desde
la sesión.

## 5. Backend — `stelfaro-platform-api`

### 5.1 `PlatformAccessPolicy`

- Nuevo método `canViewInventoryCosts(?User $user, Tenant|int $tenant): bool`.

### 5.2 `InventoryReportController`

- `margin()` → `abort_unless($policy->canViewInventoryCosts($user, $tenant), 403)`.
- `sales()` y `kardex()` → si exponen costo unitario/margen, mismo 403; si no,
  sin cambio (verificar en implementación qué campos devuelven).
- `summary()` → sigue con `canViewTenantCatalog`, pero cuando
  `!canViewInventoryCosts`: omitir `inventory_value` y el campo `stock_value`
  de cada entrada de `stock_by_item` (dejar `catalog_item_id`,
  `stock_quantity`).
- `stockAlerts()` → verificar que no incluya costo; si lo incluye, strippear
  igual.

### 5.3 `CatalogItemController@index` / `payload()`

- `payload()` recibe (o el `index` post-procesa) un flag `includeCosts`.
  Cuando `false`: `reference_cost => null`, `cost_source => 'none'` (o quitar
  las claves). El `index` calcula el flag con `canViewInventoryCosts`.
- `store` / `update` / `bulkUpdatePrices` / `destroy` ya exigen
  `canManageTenantCatalog` (owner/company_admin/billing_admin) — sin cambio.

### 5.4 Endpoint nuevo

```
GET  platform/tenants/{tenant}/inventory/stock-by-branch
     (grupo web+auth existente; gate: canViewTenantCatalog)
resp { data: { items: [ {
         catalog_item_id, name, sku, unit_name, total,
         by_branch: [ { sucursal_id, codigo, nombre, quantity } ]
       } ] } }
```

- Controlador: método nuevo en `InventoryReportController` (`stockByBranch`) o
  un `InventoryStockController` pequeño.
- Query: `InventoryLot` agrupado por `catalog_item_id, core_sucursal_id`,
  `SUM(available_quantity)`; join a `catalog_items` para `name/sku/unit_name`;
  sólo `controls_inventory = true`, `status = 'active'`. Sin `unit_cost`.
- Ruta declarada junto a los demás `inventory/reports/*` en `routes/api.php`.

### 5.5 Tests (`tests/Feature`)

- `CashierInventoryAccessTest`:
  - cajero → `GET inventory/reports/margin` = 403.
  - cajero → `GET catalog/items` = 200 y ningún item trae `reference_cost` no
    nulo ni `cost_source` distinto de `none`.
  - cajero → `GET inventory/reports/summary` = 200 sin `inventory_value` ni
    `stock_value`.
  - cajero → `GET inventory/stock-by-branch` = 200 con `by_branch` poblado y
    sin claves de costo.
  - `company_admin` → `margin` = 200 y `catalog/items` con `reference_cost`.

## 6. Frontend — `packages/billing`

### 6.1 `moduleAccess.ts`

- Añadir `sectionAccess` + `allowedSections()` (ver 4.1). Exportar
  `allowedSections` desde `packages/billing/src/index.ts` junto a
  `canAccessBillingModule` / `moduleAccess`.

### 6.2 `StockLookupPage.vue` (nuevo, `<script setup lang="ts">` tipado)

- Props: `platformBaseUrl`, `platformSession`, `appBaseUrl`, `dashboardUrl`.
- Carga `GET inventory/stock-by-branch` vía `PlatformClient` (método nuevo
  `inventoryStockByBranch(tenantId)` en `@stelfaro/api-client`, con su tipo
  `PlatformInventoryStockByBranch`).
- UI: encabezado con `BillingSectionLayout` (o el layout de sección estándar),
  `UiSearchInput` para filtrar por nombre/SKU, `UiDataTable` con columna
  Producto + una columna por sucursal + Total. Estado de carga y vacío.
- Sin botones de acción, sin costo, sin edición.
- ~150 líneas.

### 6.3 `BillingAppPage.vue`

- Import de `StockLookupPage`.
- `selectedComponent`: cuando `hasModuleAccess` es `true`,
  `props.module === 'inventory'` y `effectiveFiscalRole.value === 'cashier'`
  → `StockLookupPage`; si no, comportamiento actual.
- `selectedComponentProps`: rama para `StockLookupPage` con
  `platformBaseUrl`, `platformSession`, `appBaseUrl`, `dashboardUrl`.

### 6.4 `BillingCompanySettingsPage.vue`

- Calcular `fiscalRole` (de `platformSession.tenant.role` vía el mismo mapeo
  `billing_user → cashier`, o recibirlo por prop desde `BillingAppPage` —
  preferido: nueva prop `fiscalRole`).
- `navItems`: filtrar por `allowedSections(fiscalRole, 'settings')` cuando no
  sea `null`.
- `activeView` inicial y `?view=` : si la vista pedida no está permitida, caer
  en la primera sección permitida (`profile`).
- El `<template>` ya hace `v-else-if` por vista; al no renderizarse los items
  de nav no permitidos, esas ramas quedan inalcanzables. Verificar que no haya
  un acceso directo por deep-link que las monte igual.

### 6.5 `BillingAppPage` → `BillingCompanySettingsPage`

- Pasar la nueva prop `:fiscal-role="effectiveFiscalRole"` donde se monta
  `BillingCompanySettingsPage` (módulos `settings` y `audit`).

### 6.6 Tests (`apps/billing-demo/tests`)

- `cashierAccess.test.ts` (ampliar): `allowedSections('cashier', 'settings')`
  = `['profile','security','printer','downloads','support']`;
  `allowedSections('company_admin', 'settings')` = `null`;
  `allowedSections('cashier', 'inventory')` = `null` (el gating de inventario
  es por componente, no por sección).

## 7. Flujo de datos

```
Cajero abre /facturacion/inventario
  → PlatformPortalController@facturacionInventory  (module: 'inventory')
  → BillingAppPage: hasModuleAccess=true, role=cashier, module=inventory
  → monta StockLookupPage
  → GET platform/tenants/{t}/inventory/stock-by-branch
     → canViewTenantCatalog OK (miembro activo)
     → InventoryLot agrupado, sin unit_cost
  → tabla producto × sucursal, solo lectura

Cajero abre /facturacion/configuracion
  → module: 'audit' o 'settings' → BillingCompanySettingsPage con fiscalRole=cashier
  → navItems filtrados a [profile, security, printer, downloads, support]
  → ?view=cash → redirige internamente a 'profile'

Cajero (o su cliente) llama GET inventory/reports/margin directo
  → abort_unless(canViewInventoryCosts) → 403
```

## 8. Manejo de errores

- `stock-by-branch` sin lotes → `items: []`, la tabla muestra estado vacío.
- Sucursal sin nombre resoluble → mostrar `codigo` como fallback.
- `canViewInventoryCosts` con usuario sin membresía activa → `false` (mismo
  patrón que `canViewTenantAudit`).
- Front: si `stock-by-branch` responde 403 (no debería para cajero) →
  mensaje "No tienes acceso a existencias" sin romper la página.

## 9. Plan de implementación (orden)

1. **Backend** primero (igual que el spec anterior): `canViewInventoryCosts`,
   gating de `margin`, strip de costo en `summary` / `catalog/items`, endpoint
   `stock-by-branch`, tests.
2. **api-client**: método + tipo para `stock-by-branch`.
3. **Frontend**: `moduleAccess` (`sectionAccess`), `StockLookupPage`,
   wiring en `BillingAppPage`, filtro en `BillingCompanySettingsPage`, tests.
4. **Build** de `stelfaro-platform-api` (`npm run build`) para que
   `dev.stelfaro.com` sirva el cambio.

## 10. Riesgos

- `InventoryReportController` puede exponer costo en más endpoints de los
  listados; revisar `sales`, `kardex`, `purchaseAnnex*`, `countSheetPdf`,
  `pdf` durante la implementación y aplicar 403 o strip según corresponda.
- Otros consumidores de `catalog/items` (CatalogPage, BillingWorkspace) siguen
  necesitando el costo para roles admin: el flag `includeCosts` no debe
  romperles nada — sólo cambia el payload para roles sin permiso de costo.
- `BillingCompanySettingsPage` se usa para dos módulos (`settings`, `audit`);
  el cajero no debería llegar a `audit` (no está en su whitelist de módulos),
  pero el filtro por sección debe ser correcto igual por defensa en profundidad.
