<?php

namespace App\Http\Controllers;

use App\Models\PlatformApp;
use App\Services\PlatformSessionResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPortalController extends Controller
{
    public function __construct(
        private readonly PlatformSessionResolver $sessionResolver,
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
        return Inertia::render('Apps/Taller/Dashboard', [
            'app' => [
                'id' => 'taller',
                'name' => 'Taller electrónico',
                'description' => 'Recepción, diagnóstico, órdenes de trabajo y facturación electrónica conectada al core fiscal.',
            ],
        ]);
    }

    public function facturacion(): Response
    {
        return Inertia::render('Apps/Facturacion/Dashboard', [
            'app' => [
                'id' => 'facturacion',
                'name' => 'Facturación',
                'description' => 'Emisión libre de DTE, clientes, productos, recepción y entrega automática por correo.',
            ],
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
}
