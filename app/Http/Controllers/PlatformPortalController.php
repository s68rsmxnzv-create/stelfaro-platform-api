<?php

namespace App\Http\Controllers;

use App\Models\PlatformApp;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAdminAccess;
use App\Services\PlatformSessionResolver;
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

    public function home(Request $request): Response
    {
        return Inertia::render('Portal/Home', [
            'session' => $request->user()
                ? $this->sessionResolver->resolve($request->user())
                : null,
            'availableApps' => $this->availableApps(),
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
                'description' => 'Facturación electrónica reutilizada desde el monorepo Stelfaro Platform.',
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

    public function tallerReception(): Response
    {
        return $this->renderOperationalPlaceholder(
            'Recepción',
            'Ingreso de equipos, datos del cliente, condicion inicial y prioridad de atencion.',
            ['Ingreso de cliente', 'Equipo recibido', 'Ticket de recepcion']
        );
    }

    public function tallerDiagnosis(): Response
    {
        return $this->renderOperationalPlaceholder(
            'Diagnóstico',
            'Revision tecnica, hallazgos, presupuesto y aprobacion antes de reparar.',
            ['Revision tecnica', 'Presupuesto', 'Aprobacion del cliente']
        );
    }

    public function tallerOrders(): Response
    {
        return $this->renderOperationalPlaceholder(
            'Órdenes',
            'Seguimiento de ordenes de trabajo, estados, repuestos, mano de obra y cierre.',
            ['Ordenes abiertas', 'Trabajo en proceso', 'Entrega y facturacion']
        );
    }

    public function facturacion(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Facturación',
                'description' => 'Emisión libre de DTE, clientes, productos, recepción y entrega automática por correo.',
            ],
            'module' => 'billing',
            'documentSlug' => 'fe',
        ]);
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

    public function facturacionArtifacts(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'facturacion',
                'name' => 'Comprobantes',
                'description' => 'Consulta de documentos fiscales, PDF, JSON y artefactos emitidos.',
            ],
            'module' => 'artifacts',
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

    public function tallerArtifacts(): Response
    {
        return $this->renderBillingModule([
            'app' => [
                'id' => 'taller',
                'name' => 'Comprobantes',
                'description' => 'Consulta de documentos fiscales, PDF, JSON y artefactos emitidos.',
            ],
            'module' => 'artifacts',
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
                'local_path' => match ($app->key) {
                    'taller' => 'https://'.config('platform.hosts.taller'),
                    'facturacion' => 'https://'.config('platform.hosts.facturacion'),
                    default => 'https://'.config('platform.hosts.platform'),
                },
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
            'platformAdminUrl' => 'https://'.config('platform.hosts.admin'),
        ]);
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
}
