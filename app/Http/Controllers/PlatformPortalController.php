<?php

namespace App\Http\Controllers;

use App\Models\PlatformApp;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAdminAccess;
use App\Services\PlatformSessionResolver;
use App\Support\Platform\PlatformRoles;
use App\Support\Platform\PortalUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPortalController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
        private readonly CoreBillingSessionBroker $coreBillingSessionBroker,
        private readonly PlatformAdminAccess $platformAdminAccess,
    ) {}

    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            $session = $this->sessionResolver->resolve($request->user());
            $defaultApp = $session['default_app'] ?? null;

            if ($defaultApp) {
                return redirect($defaultApp['local_path']);
            }

            abort_unless($this->platformAdminAccess->allows($request->user()), 403, 'No tienes apps activas asignadas.');

            return redirect(PortalUrl::app('admin'));
        }

        return Inertia::render('Portal/Home', [
            'canLogin' => true,
        ]);
    }

    public function taller(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Taller electrónico',
                'description' => 'Recepción, diagnóstico, órdenes de trabajo y facturación electrónica conectada al core fiscal.',
            ],
            'module' => 'dashboard',
        ]);
    }

    public function tallerBilling(?string $documentSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Taller electrónico',
                'description' => 'Facturación electrónica integrada a la operación de StelFaro.',
            ],
            'module' => 'billing',
            'documentSlug' => $documentSlug ?? 'fe',
        ]);
    }

    public function tallerCatalog(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Catálogo',
                'description' => 'Productos, repuestos, servicios y mano de obra disponibles para operaciones.',
            ],
            'module' => 'catalog',
        ]);
    }

    public function tallerCustomers(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Clientes',
                'description' => 'Gestión de receptores para facturación electrónica.',
            ],
            'module' => 'customers',
        ]);
    }

    public function tallerInventory(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Inventario',
                'description' => 'Existencias, entradas, lotes, kardex, proveedores y ajustes.',
            ],
            'module' => 'inventory',
        ]);
    }

    public function tallerCash(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'taller', 'name' => 'Caja', 'description' => 'Cobros, salidas y ventas conectadas con Taller.'], 'module' => 'cash']);
    }

    public function tallerCommercialOrders(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'taller', 'name' => 'Órdenes y cotizaciones', 'description' => 'Trabajos comerciales, anticipos y cuentas por cobrar.'], 'module' => 'commercial-orders']);
    }

    public function tallerQuoteBuilder(?string $quotationId = null): Response
    {
        return $this->renderBillingModule([
            'app' => ['id' => 'taller', 'name' => 'Cotizaciones', 'description' => 'Propuestas comerciales para clientes de Taller.'],
            'module' => 'quote-builder',
            'quotationId' => $quotationId !== null ? (int) $quotationId : null,
        ]);
    }

    public function tallerFollowUps(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'taller', 'name' => 'Pendientes', 'description' => 'Notas y recordatorios operativos sin efectos financieros ni fiscales.'], 'module' => 'follow-ups']);
    }

    public function tallerLegacyHistory(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Legacy',
                'description' => 'Documentos fiscales emitidos desde la plataforma anterior, migrados como referencia de solo lectura.',
            ],
            'module' => 'legacy-history',
        ]);
    }

    public function tallerAnnexes(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Anexos',
                'description' => 'Anexos fiscales generados desde documentos aceptados.',
            ],
            'module' => 'annexes',
        ]);
    }

    public function tallerIvaBooks(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Libros de IVA',
                'description' => 'Libros contables formales preparados desde la informacion fiscal aceptada.',
            ],
            'module' => 'iva-books',
        ]);
    }

    public function tallerReception(): Response
    {
        return $this->renderBillingModule($this->workshopProps('workshop-reception'));
    }

    public function tallerDiagnosis(): Response
    {
        return $this->renderBillingModule($this->workshopProps('workshop-diagnosis'));
    }

    public function tallerOrders(): Response
    {
        return $this->renderBillingModule($this->workshopProps('workshop-orders'));
    }

    public function facturacion(): Response|RedirectResponse
    {
        // El cajero no tiene acceso al dashboard fiscal; su punto de entrada es
        // el facturador. El resto del endurecimiento vive en la API (dte-core)
        // y en el gating de UI por rol fiscal.
        if ($this->isCashier()) {
            return redirect()->to(PortalUrl::app('facturacion', '/fe'));
        }

        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Facturación',
                'description' => 'Emisión libre de DTE, clientes, productos, recepción y entrega automática por correo.',
            ],
            'module' => 'dashboard',
        ]);
    }

    private function isCashier(): bool
    {
        return request()->user()?->memberships()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('role') === PlatformRoles::BILLING_USER;
    }

    public function facturacionBilling(?string $documentSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Facturación',
                'description' => 'Emisión libre de DTE, clientes, productos, recepción y entrega automática por correo.',
            ],
            'module' => 'billing',
            'documentSlug' => $documentSlug ?? 'fe',
        ]);
    }

    public function facturacionCatalog(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Catálogo',
                'description' => 'Productos y servicios disponibles para ventas y operaciones.',
            ],
            'module' => 'catalog',
        ]);
    }

    public function facturacionCustomers(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Clientes',
                'description' => 'Gestión de receptores para consumidor final y crédito fiscal.',
            ],
            'module' => 'customers',
        ]);
    }

    public function facturacionInventory(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Inventario',
                'description' => 'Existencias, entradas, lotes, kardex, proveedores y ajustes.',
            ],
            'module' => 'inventory',
        ]);
    }

    public function facturacionCash(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'facturacion', 'name' => 'Caja', 'description' => 'Control de dinero, cobros, salidas y reporte comercial.'], 'module' => 'cash']);
    }

    public function facturacionCommercialOrders(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'facturacion', 'name' => 'Órdenes y cotizaciones', 'description' => 'Cotizaciones, trabajos por encargo, anticipos y cuentas por cobrar.'], 'module' => 'commercial-orders']);
    }

    public function facturacionQuoteBuilder(?string $quotationId = null): Response
    {
        return $this->renderBillingModule([
            'app' => ['id' => 'facturacion', 'name' => 'Cotizaciones', 'description' => 'Propuestas comerciales para tus clientes.'],
            'module' => 'quote-builder',
            'quotationId' => $quotationId !== null ? (int) $quotationId : null,
        ]);
    }

    public function facturacionFollowUps(): Response
    {
        return $this->renderBillingModule(['app' => ['id' => 'facturacion', 'name' => 'Pendientes', 'description' => 'Notas y recordatorios operativos sin efectos financieros ni fiscales.'], 'module' => 'follow-ups']);
    }

    public function facturacionLegacyHistory(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Legacy',
                'description' => 'Documentos fiscales emitidos desde la plataforma anterior, migrados como referencia de solo lectura.',
            ],
            'module' => 'legacy-history',
        ]);
    }

    public function facturacionAnnexes(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Anexos',
                'description' => 'Anexos fiscales generados desde documentos aceptados.',
            ],
            'module' => 'annexes',
        ]);
    }

    public function facturacionIvaBooks(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Libros de IVA',
                'description' => 'Libros contables formales preparados desde la informacion fiscal aceptada.',
            ],
            'module' => 'iva-books',
        ]);
    }

    public function facturacionArtifacts(?string $artifactSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Comprobantes',
                'description' => 'Consulta de documentos fiscales, PDF, JSON y artefactos emitidos.',
            ],
            'module' => 'artifacts',
            'artifactSlug' => $this->normalizeArtifactSlug($artifactSlug),
        ]);
    }

    public function facturacionAudit(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Auditoría',
                'description' => 'Actividad administrativa, documentos y eventos fiscales de la empresa.',
            ],
            'module' => 'audit',
        ]);
    }

    public function facturacionMhEvents(?string $eventSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Eventos MH',
                'description' => 'Invalidaciones, contingencias, retornos y eventos operativos conectados al core fiscal.',
            ],
            'module' => 'mh-events',
            'eventSlug' => $eventSlug ?? 'invalidacion',
        ]);
    }

    public function facturacionMhResponses(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Respuestas MH',
                'description' => 'Trazabilidad de respuestas del Ministerio de Hacienda para documentos transmitidos.',
            ],
            'module' => 'mh-responses',
        ]);
    }

    public function facturacionMhEventResponses(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Respuestas eventos MH',
                'description' => 'Trazabilidad de respuestas del Ministerio de Hacienda para eventos fiscales.',
            ],
            'module' => 'mh-event-responses',
        ]);
    }

    public function facturacionSettings(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Configuración',
                'description' => 'Panel de configuracion de empresa, fiscalidad, sucursales y correlativos.',
            ],
            'module' => 'settings',
        ]);
    }

    public function tallerArtifacts(?string $artifactSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Comprobantes',
                'description' => 'Consulta de documentos fiscales, PDF, JSON y artefactos emitidos.',
            ],
            'module' => 'artifacts',
            'artifactSlug' => $this->normalizeArtifactSlug($artifactSlug),
        ]);
    }

    public function tallerAudit(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Auditoría',
                'description' => 'Actividad administrativa, documentos y eventos fiscales de la empresa.',
            ],
            'module' => 'audit',
        ]);
    }

    public function tallerMhEvents(?string $eventSlug = null): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Eventos MH',
                'description' => 'Invalidaciones, contingencias, retornos y eventos operativos conectados al core fiscal.',
            ],
            'module' => 'mh-events',
            'eventSlug' => $eventSlug ?? 'invalidacion',
        ]);
    }

    public function tallerMhResponses(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Respuestas MH',
                'description' => 'Trazabilidad de respuestas del Ministerio de Hacienda para documentos transmitidos.',
            ],
            'module' => 'mh-responses',
        ]);
    }

    public function tallerMhEventResponses(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Respuestas eventos MH',
                'description' => 'Trazabilidad de respuestas del Ministerio de Hacienda para eventos fiscales.',
            ],
            'module' => 'mh-event-responses',
        ]);
    }

    public function tallerFiscalSettings(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Configuración',
                'description' => 'Panel de configuracion de empresa, fiscalidad, sucursales y correlativos.',
            ],
            'module' => 'settings',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableApps(): array
    {
        return PlatformApp::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (PlatformApp $app): array => [
                'id' => $app->key,
                'name' => $app->name,
                'host' => $app->host,
                'default_path' => $app->default_path,
                'local_path' => PortalUrl::app($app->key, $app->default_path ?: '/'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderBillingModule(array $props): Response
    {
        $coreSession = null;
        $coreSessionError = null;

        try {
            $coreSession = $this->coreBillingSessionBroker->openFor(request()->user());
        } catch (\RuntimeException $exception) {
            $coreSessionError = $exception->getMessage();
        }

        return Inertia::render('Apps/Taller/BillingWorkspace', [
            ...$props,
            'coreBaseUrl' => '/core-api/v1',
            'coreSession' => $coreSession,
            'coreSessionError' => $coreSessionError,
            'platformSession' => $this->sessionResolver->resolve(request()->user()),
            'canAccessPlatformAdmin' => $this->platformAdminAccess->allows(request()->user()),
            'platformAdminUrl' => PortalUrl::app('admin'),
            'appBaseUrl' => '/'.trim(config('platform.paths.'.$props['app']['id'], '/'.$props['app']['id']), '/'),
        ]);
    }

    private function normalizeArtifactSlug(?string $artifactSlug): string
    {
        return in_array($artifactSlug, ['dte', 'eventos'], true) ? $artifactSlug : 'dte';
    }

    /**
     * @param  array<int, string>  $items
     */
    private function renderOperationalPlaceholder(string $title, string $description, array $items): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Taller electrónico',
                'description' => 'Recepción, diagnóstico, órdenes de trabajo y facturación electrónica conectada al core fiscal.',
            ],
            'module' => 'operational-placeholder',
            'operationalPage' => [
                'title' => $title,
                'description' => $description,
                'items' => $items,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function workshopProps(string $module): array
    {
        return [
            'app' => [
                'id' => 'taller',
                'name' => 'Taller electrónico',
                'description' => 'Gestión de equipos, órdenes de servicio y facturación electrónica.',
            ],
            'module' => $module,
        ];
    }
}
