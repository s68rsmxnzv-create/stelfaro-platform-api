# Rol Cajero y Vista POS — Diseño

**Fecha:** 2026-08-28
**Estado:** Parcialmente implementado — ver nota de 2026-08-29
**Repos afectados:** `dte-core`, `stelfaro-platform-api`, `stelfaro-platform` (`packages/billing`)
**Rama de trabajo en cada repo:** `integration`

---

## Nota de revisión — 2026-08-29

Tras probar la primera versión se decidió **posponer la vista POS** y el
**flujo de avisos a supervisor** (WhatsApp + campanita). Motivos: la v1 del POS
no cubre Factura con cliente identificado (sólo consumidor final directo), no
tiene tests de componente y necesita QA de impresión en hardware real.

**Lo que SÍ quedó implementado (endurecimiento del rol cajero):**

- `dte-core`: rol fiscal `cashier` + `CashierFiscalAccess` (3 capas: tipo de
  DTE 01/03, punto de venta asignado, scope de documentos) + `billing.role:`
  en rutas → 403 real en API.
- `stelfaro-platform-api`: `PlatformRoles::FISCAL_CASHIER`, mapeo
  `billing_user → cashier`, comando `cashier:review-billing-users`, redirección
  del cajero del dashboard fiscal al facturador.
- `packages/billing`: el cajero usa el facturador tradicional con módulos
  restringidos (`moduleAccess`), panel de asignación de caja para el
  `company_admin`, y unificación del label "Cajero".

**Pendiente para retomar el POS (spec futuro):** rediseñar el paso de cobro
para FC con/sin cliente, quitar `@ts-nocheck` y tipar, tests de componente,
impresión con feedback de error, QA en hardware de caja, y decidir de nuevo el
flujo de avisos a supervisor. Gating web (Inertia) por rol como tercera capa
quedó fuera; hoy el endurecimiento son dos capas: API + UI.

---

## 1. Contexto y problema

Al crear usuarios aparecen los roles **Cajero** y **Vendedor**. Hoy son
funcionalmente idénticos y casi sin diferencia frente a un `company_admin`:

- **Nombres internos:** "Cajero" = `billing_user`, "Vendedor" = `seller`
  (labels en `CompanyUsersPanel.vue`, `GlobalUsersPage.vue`,
  `Invitations/Accept.vue`). Inconsistencia: `TenantRequestsPanel.vue` y
  `UserProfilePanel.vue` muestran `billing_user` como "Facturación".
- **Plataforma (`PlatformAccessPolicy`):** `seller` y `billing_user` reciben
  trato idéntico. `PlatformRoles::fiscalRoleForTenantRole()` manda ambos al
  `default => billing_user`.
- **Motor fiscal (`dte-core`):** el rol `seller` no existe. Los roles fiscales
  son `super_admin, admin_fiscal, company_admin, billing_user, viewer`. La
  autorización real vive en `dte-core/routes/api.php` con el middleware
  `billing.role:`. Mapeo actual de rol de tenant → rol fiscal:
  - `owner, company_admin, billing_admin` → `company_admin`
  - `viewer, accountant` → `viewer`
  - `billing_user, seller` → `billing_user`
- **Frontend (`packages/billing`):** casi no hay gating por rol. `userRole` se
  lee en `BillingAppNav.vue` pero no se usa. Solo se restringe
  `canManageBillingStation` y el panel de solicitudes a `company_admin`/`owner`.
- El tramo `billing_user` en `dte-core` puede emitir DTE, gestionar clientes y
  correlativos **y también** eventos MH e invalidaciones.

**Alcance de este spec:** afinar únicamente el rol **Cajero**. El rol
**Vendedor** (`seller`) se aborda en un spec posterior.

## 2. Objetivos

1. Que la UI refleje los permisos del cajero (ocultar / deshabilitar).
2. Que aunque el cajero conozca las rutas, no tenga acceso: bloqueo en API
   (`dte-core`), en rutas web (Inertia) y en UI.
3. Nueva **vista POS** para el cajero, distinta de la vista de facturación:
   alimentada por catálogo/inventario, con clientes (ver/crear/editar), sin
   eventos, sin respuestas MH, con búsqueda y reenvío de comprobantes, y con
   todos los accesos necesarios para facturar sin fricción.
4. Flujo para que el cajero avise a un supervisor cuando se equivoca
   (WhatsApp + campanita con hilo ligero y estado).

## 3. No-objetivos (fuera de alcance)

- Rol `seller` / Vendedor (spec futuro).
- Refactor de `BillingWorkspace.vue` (spec propio, urgente pero separado).
- Que el cajero invalide comprobantes o emita notas de crédito/débito.
- Chat interno en tiempo real (spec futuro con Laravel Reverb).
- Rotación de cajeros entre varias cajas (evolución posterior; por ahora
  un cajero = una caja).
- Notas "escribiendo…", presencia, adjuntos en el hilo de avisos.

## 4. Decisiones de diseño

### 4.1 Modelo de roles (Enfoque 1: rol fiscal nuevo `cashier`)

- El rol de tenant sigue siendo `billing_user`, con label unificado
  **"Cajero"** en toda la UI (se corrige `TenantRequestsPanel.vue` y
  `UserProfilePanel.vue`).
- Se agrega el rol fiscal **`cashier`** a `dte-core`, como el tramo más
  restrictivo.
- `PlatformRoles::fiscalRoleForTenantRole()` — nueva rama:
  `billing_user` → `cashier`. `seller` permanece en `billing_user` hasta su
  propia fase. Resto sin cambios.
- `PlatformRoles`: nueva constante `FISCAL_CASHIER = 'cashier'`.
- No destructivo para el "operador de facturación completo": ese rol es
  `billing_admin` (→ fiscal `company_admin`), que no cambia.

**Migración de datos:** antes de activar el nuevo mapeo, revisar los
`billing_user` existentes. Decisión tomada: `facturacion@stelfaro.com` queda
como **cajero** (se usará para las pruebas). El resto se confirma con el
usuario mediante un comando de revisión (listar holders de `billing_user` por
tenant, con su último acceso).

### 4.2 Matriz de capacidades de `cashier`

| Capacidad | Cashier | Notas |
|---|---|---|
| Emitir FC (01) y CCF (03) | ✅ | Solo estos dos tipos |
| Preview / borradores de 01 y 03 | ✅ | |
| Reservar correlativo | ✅ | |
| Ver / crear / editar clientes | ✅ | Sin `destroy` |
| Catálogo + disponibilidad de inventario (lectura) | ✅ | Alimenta el POS |
| Buscar y reenviar comprobante | ✅ | Solo de **su** punto de venta; reenvío = re-entrega de correo, sin re-timbrar |
| Eventos MH (invalidación, contingencia, anexos de invalidación) | ❌ | 403 en API + oculto en UI |
| Notas de crédito/débito, remisión, sujeto excluido, exportación, FSE | ❌ | |
| Respuestas MH / acuses / recepción | ❌ | |
| Libros de ventas / anexos fiscales | ❌ | |
| Empresa, sucursales, puntos de venta, settings, certificados | ❌ | Ya bloqueado |
| Dashboard fiscal / métricas globales | ❌ | El POS tiene su propio resumen de caja |

### 4.3 Asociación cajero ↔ caja (Enfoque A)

- El `company_admin` pre-asigna al cajero una **sucursal + punto de venta
  fijos**. El cajero no elige nada en el POS.
- La infraestructura **ya existe**: `membership->fiscalAssignments()` con
  `core_sucursal_id`, `core_punto_venta_id`, `is_default`, `status`;
  `CoreBillingSessionBroker::openFor()` ya inyecta `sucursales`,
  `puntos_venta`, `default_sucursal_id`, `default_punto_venta_id` en la sesión
  fiscal.
- Falta: exponer la gestión de esas asignaciones al `company_admin` (UI) y que
  el POS use `default_punto_venta_id`. Si el cajero no tiene asignación activa,
  el POS muestra un estado "pide a tu administrador que te asigne una caja".

### 4.4 Documentos y scope en el POS

- Emisión: **solo FC (01) y CCF (03)**. El CCF exige cliente con NIT/NRC.
- Búsqueda/reenvío de comprobantes: **solo los del punto de venta asignado**
  (Opción A).

## 5. Backend

### 5.1 `dte-core`

1. **Enums:** agregar `cashier` a la validación de `role` y de
   `empresas[].role` en `InternalBillingSessionController`.
2. **Nuevo tramo de rutas** con
   `billing.role:super_admin,admin_fiscal,company_admin,billing_user,cashier`
   para lo que el cajero sí usa:
   - `billing/context`, `billing/catalogs`,
     `billing/companies/{empresa}/thermal-logo`
   - `billing/correlativos/preview`, `billing/correlativos/reserve`
   - `billing/customers` (index, show, check-document, store, update) — **no**
     `destroy`
   - `dte/metadata/{tipoDte}`, `dte/preview`, `dte/emitir`, `dte/issue`,
     `dte/issue-progress`, `dte/issue-requests/{issueRequest}`
   - `dte/drafts` (store, show, history, artifacts: graphic/pdf/client-json/thermal),
     `dte/drafts/{draft}/ready-to-sign|sign|send`,
     `dte/drafts/{draft}/resend-email`, `dte/drafts/{draft}/email-delivery`
   - `dte/drafts` (index) y `dte/drafts/{draft}` (show) — con **scope de punto
     de venta** (ver punto 4)
3. **Restricción de tipo de DTE:** en `DteEmissionController`,
   `DteIssueController`, `DtePreviewController`, `DteDraftController@store` —
   si el rol fiscal es `cashier` y `tipoDte` ∉ {`01`, `03`} → 403. Implementar
   como guard reutilizable (form request rule o método en un trait/servicio de
   autorización fiscal).
4. **Scope de punto de venta para `cashier`:** en `DteDraftController@index`
   y `@show`, y en cualquier acceso a un draft/documento por parte de un
   `cashier`, filtrar por los `puntos_venta` de la sesión fiscal. Documento de
   otro punto de venta → 404. La sesión ya trae `puntos_venta` /
   `default_punto_venta_id`.
5. **Rutas que quedan fuera del tramo `cashier`** (siguen exigiendo
   `billing_user` o superior): todas las `mh/events/*`, `dte/annexes/*`,
   `dte/dashboard-summary`, `dte/dashboard/sucursales`, `dte/outbox/*`,
   `billing/customers` destroy, y todo el tramo `company_admin`
   (empresa/sucursales/puntos de venta/settings/certificados/calendarios).
6. Tests (ver sección 8).

### 5.2 `stelfaro-platform-api`

1. `PlatformRoles::FISCAL_CASHIER = 'cashier'`.
2. `PlatformRoles::fiscalRoleForTenantRole()` — rama `billing_user` →
   `cashier` antes del `default`.
3. `PlatformAccessPolicy` — sin cambios de fondo (`canOperateTenant()` ya
   incluye `billing_user`). El endurecimiento real vive en `dte-core` y en las
   rutas web.
4. **Endpoint nuevo** `GET /api/v1/tenants/{tenant}/supervisors`
   - Devuelve miembros activos con rol `owner` / `company_admin` /
     `billing_admin`: `user_id`, `name`, `phone` (E.164 si está disponible).
   - Autorizado a cualquier miembro activo del tenant.
5. **Recurso nuevo: hilo de aviso de caja** (`cashier_alert_thread`)
   - Tabla nueva `cashier_alert_threads`: `id`, `tenant_id`, `opened_by_user_id`,
     `core_document_id` (nullable), `core_document_ref` (código de generación /
     número, para mostrar), `reason`, `status`
     (`open` | `acknowledged` | `resolved`), `resolved_by_user_id` (nullable),
     `resolved_at` (nullable), timestamps.
   - Tabla nueva `cashier_alert_messages`: `id`, `thread_id`, `author_user_id`,
     `body`, `created_at`.
   - `POST /api/v1/tenants/{tenant}/cashier-alerts` — body:
     `{ core_document_id?, core_document_ref?, reason, supervisor_user_ids: [] }`.
     Crea el hilo (estado `open`), el primer mensaje, y un `InternalNotification`
     por cada supervisor:
     - `category: 'cashier_alert'`
     - `title`: "Aviso de caja — <nombre cajero>"
     - `message`: motivo + contexto (empresa, punto de venta, comprobante)
     - `action_url`: ruta al hilo / comprobante en el app completo
     - Solo un miembro con rol `billing_user` del tenant puede llamarlo.
     - `supervisor_user_ids` se valida contra la lista real de supervisores del
       tenant.
   - `GET /api/v1/tenants/{tenant}/cashier-alerts` — lista los hilos visibles
     para el solicitante (el cajero ve los suyos; los supervisores ven los del
     tenant dirigidos a ellos).
   - `GET /api/v1/tenants/{tenant}/cashier-alerts/{thread}` — detalle + mensajes.
   - `POST .../{thread}/messages` — respuesta corta. Cajero autor y supervisores
     destinatarios pueden escribir.
   - `POST .../{thread}/acknowledge` — supervisor destinatario; `open` →
     `acknowledged`.
   - `POST .../{thread}/resolve` — solo un supervisor destinatario; marca
     `resolved`, `resolved_by_user_id`, `resolved_at`. El cajero autor **no**
     puede resolver.
6. **Rutas web (Inertia, `PlatformPortalController` / `routes/web.php`):**
   - Nueva ruta `GET /facturacion/pos` →
     `PlatformPortalController::facturacionPos()` →
     `renderBillingModule(['module' => 'pos'])`.
   - Landing: si el rol de tenant es `billing_user`, `GET /facturacion`
     redirige a `/facturacion/pos`.
   - Guard de rol en las rutas web no permitidas para `billing_user`
     (`/facturacion/eventos-mh`, `/respuestas-mh`, `/respuestas-eventos-mh`,
     `/anexos`, `/libros-iva`, `/configuracion`, `/auditoria`, `/{documentSlug}`
     de facturación completa, `/comprobantes` completo salvo el necesario para
     el POS): redirect a `/facturacion/pos` o 403.
7. **Comando de migración/revisión** `php artisan cashier:review-billing-users`
   — lista holders de `billing_user` por tenant con último acceso, para
   confirmar cuáles quedan como cajero.

## 6. Frontend (`stelfaro-platform`, `packages/billing`)

### 6.1 Vista POS

- Componente nuevo `packages/billing/src/pages/PosWorkspace.vue`, exportado en
  `index.ts` y añadido al switch de `module` en `BillingAppPage.vue`. **No**
  toca `BillingWorkspace.vue`.
- Construido sobre el shell móvil existente (`components/mobile/`): tab bar
  inferior, hoja inferior para el cobro, orientado a táctil.
- Pantalla:
  - **Buscador de productos** (nombre / código) desde `billing/catalogs` +
    badge de existencias desde el resumen de inventario
    (`PlatformInventorySummary` / `stock_by_item`, ya consumido hoy en
    `BillingWorkspace.vue`).
  - **Carrito**: cantidades, descuento por línea si el punto de venta lo
    permite.
  - **Cliente**: buscar o "Consumidor final" por defecto; botones "Nuevo
    cliente" / "Editar" → hoja inferior con el form de cliente (endpoint
    `billing/customers`).
  - **Cobrar** → elegir tipo **Factura (01)** o **Crédito fiscal (03)**; CCF
    exige NIT/NRC. Reserva correlativo, emite, imprime/entrega (reusa
    `printing/automaticDtePrint`).
  - **Comprobantes** (tab): lista solo los del punto de venta asignado;
    acciones ver PDF y reenviar correo.
  - **Ayuda / avisar supervisor** (sección 7).
- Punto de venta: viene de `default_punto_venta_id` de la sesión fiscal, no se
  elige. Sin asignación activa → estado "pide a tu administrador que te asigne
  una caja".

### 6.2 Gating por rol en el app existente

- `BillingAppPage.vue` recibe `user.role`. Se define un mapa `moduleAccess`
  (rol fiscal → módulos permitidos). Para `cashier`: solo `pos` (`dashboard`
  redirige a `pos`).
- `cashier` que navegue (incluso escribiendo la URL) a un módulo no permitido
  → pantalla "No tienes acceso a esta sección" + botón a `/facturacion/pos`.
  El backend además responde 403, así que no hay fuga de datos.
- `BillingAppNav.vue`: filtra los items del menú según `moduleAccess`. El
  cajero ve un nav mínimo (POS, Comprobantes, Ayuda).

### 6.3 Gestión de asignación de caja (UI para `company_admin`)

- En el panel de usuarios / miembros del tenant, al editar un miembro con rol
  `billing_user`: sección para asignarle sucursal + punto de venta
  (`fiscalAssignments`), marcando uno como default.

## 7. Flujo "avisar al supervisor"

**Disparador:** en el POS, botón "Necesito ayuda con este comprobante" (en la
lista de comprobantes, en el detalle, o genérico desde el menú de ayuda). Abre
una hoja inferior con:

- Motivo: texto corto libre + chips ("Emití mal el comprobante", "Cliente
  equivocado", "Monto incorrecto", "Otro").
- Comprobante asociado (pre-cargado si viene del detalle).
- Lista de supervisores (de `GET /tenants/{tenant}/supervisors`): cada uno con
  botón **WhatsApp** y checkbox **"Notificar en la campanita"**.

**Canal 1 — WhatsApp:** enlace
`https://wa.me/<phone_e164>?text=<mensaje>` abierto en pestaña nueva
(`target="_blank"`); funciona con WhatsApp Web y con la app de escritorio. El
mensaje incluye empresa, punto de venta, nombre del cajero, motivo, número /
código de generación del comprobante y enlace directo al comprobante en el app
completo. Sin teléfono guardado → botón deshabilitado con tooltip. Revisar si
`packages/billing/src/annexWhatsApp.ts` sirve de base o se hace un helper
hermano `supervisorWhatsApp.ts`.

**Canal 2 — Campanita (hilo ligero con estado):**
`POST /tenants/{tenant}/cashier-alerts` crea el `cashier_alert_thread` +
`InternalNotification` por supervisor elegido. `InternalNotificationsBell.vue`
ya hace polling cada 5s y ya renderiza `action_url` → aparece sin cambios de
infraestructura; solo se añade ícono/estilo para la categoría `cashier_alert`.

- Estados: `open → acknowledged → resolved`.
- Supervisor destinatario puede responder y marcar **Resuelto**.
- Cajero autor puede responder pero **no** resolver; ve el cambio de estado y
  las respuestas en el POS por el mismo polling (~5s de latencia).
- Sin presencia, sin "escribiendo…", sin adjuntos.

**Futuro (spec propio):** chat interno en tiempo real con **Laravel Reverb** +
`laravel-echo` cuando existan 2–3 casos reales de conversación interna. En esa
fase la campanita puede convertirse en el acceso al chat y el hilo
`cashier_alert` migra a conversación.

## 8. Testing

### `dte-core` (Feature / PHPUnit)

- `cashier` **puede**: `dte/emitir` con tipo `01` y `03`; `dte/preview`;
  `billing/correlativos/reserve`; `billing/customers` index/show/store/update;
  `billing/context`; `billing/catalogs`.
- `cashier` recibe **403** en: `dte/emitir` con tipo `05/06/11/14/…`; todas las
  `mh/events/*`; `dte/annexes/*`; `dte/dashboard-*`; `billing/customers`
  destroy; rutas de empresa/sucursal/settings.
- `cashier` en `dte/drafts` index/show solo ve documentos de su `punto_venta`;
  404 al pedir uno de otro punto de venta.
- Reenvío (`dte/drafts/{d}/resend-email`): permitido para un DTE de su punto de
  venta, 403 para otro.
- `InternalBillingSessionController` acepta `role: cashier`.

### `stelfaro-platform-api` (Feature)

- Tabla de mapeo: `fiscalRoleForTenantRole('billing_user') === 'cashier'`;
  `seller` sigue en `billing_user`; el resto sin cambios.
- `GET /tenants/{tenant}/supervisors`: solo owner/company_admin/billing_admin
  activos con `phone`; no-miembro → 403.
- `POST /tenants/{tenant}/cashier-alerts`: crea hilo + `InternalNotification`
  por supervisor; rechaza `supervisor_user_ids` que no son supervisores;
  solo `billing_user` puede llamarlo.
- Estados del hilo: `open → acknowledged → resolved`; solo un supervisor
  destinatario resuelve; el cajero autor puede responder, no resolver.
- Rutas web: `billing_user` pidiendo `/facturacion/eventos-mh`,
  `/respuestas-mh`, `/anexos`, `/configuracion`, `/auditoria` → redirect/403;
  `/facturacion` → redirect a `/facturacion/pos`.

### Frontend (`packages/billing`, Vitest + Testing Library)

- `BillingAppNav` con `role: cashier` renderiza solo POS / Comprobantes /
  Ayuda.
- `BillingAppPage` con `module` no permitido y `role: cashier` → pantalla "sin
  acceso" + enlace a POS.
- `PosWorkspace`: agregar producto al carrito, badge de existencias, selección
  de cliente / consumidor final, CCF exige NIT/NRC, emisión llama al endpoint
  correcto, la lista de comprobantes filtra por punto de venta.
- Helper de WhatsApp arma el `wa.me` con el mensaje esperado; botón
  deshabilitado sin teléfono.

### Manual / QA

Con `facturacion@stelfaro.com` (ya como cajero): login entra al POS; emitir FC
y CCF reales en ambiente de pruebas; reenviar un comprobante; abrir aviso a
supervisor por WhatsApp y por campanita; `stelfaro@` lo recibe, responde y lo
marca resuelto.

## 9. Orden de implementación sugerido

1. `dte-core`: rol `cashier`, tramo de rutas, guard de tipo de DTE, scope de
   punto de venta + tests.
2. `stelfaro-platform-api`: constante + mapeo `fiscalRoleForTenantRole`,
   endpoint `supervisors`, recurso `cashier-alerts` (migraciones, modelos,
   controladores), ruta web `/facturacion/pos` + guards + landing, comando de
   revisión + tests.
3. `stelfaro-platform` (`packages/billing`): `moduleAccess` + gating en
   `BillingAppPage` / `BillingAppNav`, `PosWorkspace.vue`, hoja de aviso a
   supervisor + helper WhatsApp, UI de asignación de caja + tests.
4. QA manual end-to-end con `facturacion@stelfaro.com` y `stelfaro@stelfaro.com`.

## 10. Riesgos y notas

- **Migración de `billing_user`:** activar el mapeo `billing_user → cashier`
  cambia lo que pueden hacer todos los holders actuales. Ejecutar el comando de
  revisión y confirmar con el usuario antes de desplegar. `facturacion@` →
  cajero confirmado.
- **Coordinación de despliegue entre 3 repos:** el mapeo en `stelfaro-platform-api`
  no debe activarse antes de que `dte-core` conozca el rol `cashier`, o las
  sesiones fiscales fallarían la validación de enum. Desplegar `dte-core`
  primero.
- `BillingWorkspace.vue` (>6000 líneas) no se toca aquí; su refactor es un spec
  aparte, marcado como urgente.
- El rol `seller` / Vendedor queda intencionalmente igual; su endurecimiento es
  el siguiente spec de esta serie.
