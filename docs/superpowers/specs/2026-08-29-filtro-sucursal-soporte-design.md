# Filtro de sucursal para soporte (company_admin / owner) — Diseño

**Fecha:** 2026-08-29
**Estado:** Aprobado, listo para implementación
**Repos afectados:** `dte-core`, `stelfaro-platform` (`packages/api-client`, `packages/billing`)
**Rama de trabajo en cada repo:** `integration`
**Relacionado:** [2026-08-29-permisos-cajero-v2-config-inventario-design.md](2026-08-29-permisos-cajero-v2-config-inventario-design.md) — la "consola de soporte" que quedó como follow-up; esto la resuelve reutilizando Comprobantes / Respuestas / Eventos MH.

---

## 1. Contexto y problema

Un `company_admin` / `owner` de tenant **ya ve todos los DTE de su empresa** en
Comprobantes, Respuestas y al buscar candidatos de invalidación en Eventos MH
(el scope por punto de venta sólo aplica al cajero vía
`CashierFiscalAccess`). Lo que falta es poder **acotar por sucursal** para dar
soporte: encontrar el DTE de una sucursal concreta y actuar (reenviar correo,
ver PDF/JSON, invalidar, reportar contingencia).

Las tres páginas listan DTE con el mismo endpoint core `GET dte/drafts`
(`CoreDteClient.documents()`), así que un solo filtro `sucursal_id` las cubre.
`dte_documents` ya tiene `sucursal_id` y `punto_venta_id`.

## 2. Objetivos

1. Selector de **Sucursal** en Comprobantes, Respuestas y Eventos MH que acota
   el listado de DTE por `sucursal_id`.
2. Visible sólo para roles de tenant `owner` y `company_admin`, y sólo si la
   empresa tiene más de una sucursal.
3. Reutilizar las acciones por documento que ya existen — sin acciones nuevas.

## 3. No-objetivos

- Filtro por punto de venta (la sucursal alcanza).
- Que `admin_fiscal` / `billing_admin` vean el filtro.
- Filtro de sucursal en el **listado de eventos** (`mh/events`):
  `mh_fiscal_events` no tiene `sucursal_id`; requiere migración. Follow-up.
- Cambiar el scope de datos del backend: el `company_admin` ya ve todo; esto es
  sólo un filtro de UI + un `where` opcional.
- Nueva página / "consola": se reutilizan Comprobantes / Respuestas / Eventos MH.

## 4. Backend — `dte-core`

### 4.1 `DteDraftController@index`

Nuevo filtro opcional, junto a los demás (`estado`, `tipo_dte`, `from`…):

```php
if ($request->filled('sucursal_id')) {
    $query->where('sucursal_id', (int) $request->input('sucursal_id'));
}
```

No necesita autorización extra: `CashierFiscalAccess::scopeDocuments` sigue
restringiendo al cajero a su punto de venta (un `sucursal_id` extra sólo
acotaría más), y el resto de roles ya ve el universo completo de su empresa.
El `where` se aplica **después** de `scopeDocuments` y del filtro `empresa_id`.

### 4.2 Tests (`tests/Feature`)

- `dte/drafts?sucursal_id=X` de un `company_admin` devuelve sólo DTE de esa
  sucursal.
- Sin `sucursal_id`: mismo resultado que hoy.
- `sucursal_id` de otra empresa combinado con `empresa_id` propio: no filtra
  cruzado (devuelve vacío, no 500).

## 5. `packages/api-client`

- `documents()` params: añadir `sucursal_id?: number`.
- `mhEvents()` params: **sin cambios** (el listado de eventos no se filtra por
  sucursal en v1).
- `compactParams` ya omite `undefined`, no hace falta nada más.

## 6. Frontend — `packages/billing`

### 6.1 `support/sucursalScope.ts` (util nuevo)

```ts
export function canScopeBySucursal(tenantRole: string | null | undefined): boolean {
  return tenantRole === 'owner' || tenantRole === 'company_admin';
}
```

### 6.2 `components/SucursalScopeFilter.vue` (componente compartido, ~70 líneas)

- Props: `tenantId: number`, `platformBaseUrl?: string`, `modelValue: number | null`.
- Emits: `update:modelValue`.
- `onMounted`: `new PlatformClient(platformBaseUrl).tenantFiscalScope(tenantId)`;
  guarda `sucursales` (`{ id, codigo, nombre }`).
- Render: si `sucursales.length <= 1` → no renderiza nada (`v-if`). Si no →
  `UiSelect` con opciones `[{ value: '', label: 'Todas las sucursales' },
  ...sucursales.map(s => ({ value: String(s.id), label: `${s.codigo} · ${s.nombre}` }))]`.
- Convierte `'' ⇄ null` en el `v-model`.
- Falla en silencio si el scope no carga (no rompe la página; simplemente no
  muestra el filtro).

### 6.3 `DteArtifactsPage.vue`

- Props nuevas: `platformSession?`, `platformBaseUrl?` (con defaults).
- `tenantId` y `tenantRole` derivados de `platformSession`.
- Si `canScopeBySucursal(tenantRole)` → montar `SucursalScopeFilter` en la
  barra de filtros (junto a búsqueda / tipo), `v-model="sucursalId"`.
- `sucursalId = ref<number | null>(null)`; al cambiar → recargar
  (`dtePage = 1; loadDocuments()`).
- `loadDocuments()`: pasar `sucursal_id: sucursalId.value ?? undefined` a
  `client.documents(...)`.
- La pestaña **eventos** (`loadEvents` → `mhEvents`) no usa el filtro.

### 6.4 `MhResponsesPage.vue`

- Igual que 6.3: props platform nuevas, filtro condicional, `sucursal_id` en
  `client.documents(...)` de `loadDocuments()`.

### 6.5 `MhEventsPage.vue`

- Ya recibe `platformSession` / `platformBaseUrl`.
- Añadir `SucursalScopeFilter` cuando `canScopeBySucursal(tenantRole)` en la
  zona de búsqueda de DTE origen (invalidación) y de candidatos de retorno.
- Pasar `sucursal_id` a `loadDocuments()` y `loadReturnCandidates()`.

### 6.6 `BillingAppPage.vue`

- Branch de props del módulo `artifacts`: añadir `platformSession:
  props.platformSession`, `platformBaseUrl: props.platformBaseUrl`.
- Branch `mh-responses`: idem (hoy sólo pasa `baseProps`).
- `mh-events` ya las pasa.

### 6.7 `packages/billing/src/index.ts`

- Export de `SucursalScopeFilter` y `canScopeBySucursal` (por si el harness
  `billing-demo` los prueba / usa).

### 6.8 Tests (`apps/billing-demo/tests`)

- `sucursalScope.test.ts`: `canScopeBySucursal` → `true` para `owner` /
  `company_admin`; `false` para `billing_admin`, `billing_user`, `viewer`,
  `null`.

## 7. Flujo

```
company_admin abre Comprobantes
  → DteArtifactsPage: canScopeBySucursal('company_admin') = true
  → SucursalScopeFilter carga tenantFiscalScope → 3 sucursales
  → admin elige "S02 · Sucursal Norte"
  → loadDocuments({ ..., sucursal_id: 42 })
  → GET dte/drafts?sucursal_id=42
     → scopeDocuments (no-op para admin) + where empresa_id + where sucursal_id
  → lista sólo DTE de esa sucursal
  → admin abre un DTE → reenviar correo / ver PDF / (en Eventos MH) invalidar
```

## 8. Manejo de errores

- `tenantFiscalScope` falla → `SucursalScopeFilter` no se muestra; las páginas
  siguen funcionando sin filtro.
- Empresa con 0-1 sucursal → filtro oculto.
- `sucursal_id` inválido / de otra empresa → el `where` no matchea nada →
  lista vacía (no error).

## 9. Plan de implementación (orden)

1. dte-core: filtro + tests.
2. api-client: `sucursal_id` en `documents()`.
3. frontend: `sucursalScope.ts`, `SucursalScopeFilter.vue`, wiring en las 3
   páginas + `BillingAppPage`, tests.
4. Build de `stelfaro-platform-api` (`npm run build`).

## 10. Riesgos

- `DteArtifactsPage` y `MhResponsesPage` no reciben hoy props de plataforma;
  añadirlas es mecánico pero hay que pasar por `BillingAppPage` en los dos
  puntos de montaje (desktop y móvil).
- `tenantFiscalScope` devuelve las sucursales del **tenant**, no filtradas por
  empresa activa. Si un tenant tuviera varias empresas fiscales, el filtro
  listaría sucursales de todas. Hoy el modelo es 1 tenant ≈ 1 empresa fiscal;
  si eso cambia, el filtro necesitará el `empresa_id` activo. Anotado, no
  bloqueante.
