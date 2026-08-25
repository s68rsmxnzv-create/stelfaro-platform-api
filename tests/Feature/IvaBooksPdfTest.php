<?php

namespace Tests\Feature;

use App\Models\InventoryPurchase;
use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class IvaBooksPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_all_iva_books_from_existing_sources(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'platform.portal.host' => 'dev.stelfaro.com',
        ]);

        [$user, $tenant] = $this->userWithFacturacionTenant();

        InventoryPurchase::query()->create([
            'tenant_id' => $tenant->id,
            'purchase_number' => 1,
            'document_type' => '03',
            'document_number' => 'COMPRA-001',
            'purchase_date' => '2026-08-10',
            'subtotal' => 100,
            'tax_amount' => 13,
            'total' => 113,
            'fiscal_annex_eligible' => true,
            'f07_operation_type' => 1,
            'f07_classification' => 1,
            'f07_sector' => 2,
            'f07_cost_expense_type' => 3,
            'supplier_snapshot' => [
                'name' => 'Proveedor Demo',
                'nrc' => '7654321',
                'tax_id' => '06140101001010',
            ],
            'status' => 'received',
        ]);

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'core-token']),
            'https://core.test/api/v1/billing/context' => Http::response([
                'empresas' => [[
                    'id' => 77,
                    'razon_social' => 'Empresa Demo S.A. de C.V.',
                    'nombre_comercial' => 'Empresa Demo',
                    'nit' => '06140101001010',
                    'nrc' => '1234567',
                ]],
            ]),
            'https://core.test/api/v1/dte/annexes/sales*' => Http::response([
                'data' => [
                    'ventas_contribuyente' => [
                        'official_rows' => [[
                            '03/08/2026',
                            '4',
                            '03',
                            'DTE-03-00000001',
                            'SELLO',
                            'CODIGO',
                            '',
                            '1234567',
                            'Cliente Demo',
                            '0.00',
                            '0.00',
                            '200.00',
                            '26.00',
                            '0.00',
                            '0.00',
                            '226.00',
                        ]],
                    ],
                    'ventas_consumidor_final' => [
                        'official_rows' => [[
                            '05/08/2026',
                            '4',
                            '01',
                            '',
                            '',
                            '',
                            '',
                            'DTE-01-00000001',
                            'DTE-01-00000003',
                            '',
                            '0.00',
                            '0.00',
                            '0.00',
                            '150.00',
                            '0.00',
                            '0.00',
                            '0.00',
                            '0.00',
                            '150.00',
                        ]],
                    ],
                ],
            ]),
        ]);

        $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
        $renderer->shouldReceive('render')
            ->once()
            ->with(
                Mockery::on(fn (string $html): bool => str_contains($html, 'Empresa Demo S.A. de C.V.')
                    && str_contains($html, 'Libro de Iva Ventas Contribuyentes')
                    && str_contains($html, 'Libro de Iva Ventas Consumidor Final')
                    && str_contains($html, 'Libro de Iva Compras')
                    && str_contains($html, 'Cliente Demo')
                    && str_contains($html, 'Proveedor Demo')),
                Mockery::on(fn (array $options): bool => ($options['landscape'] ?? false) === true)
            )
            ->andReturn('%PDF-iva-books');
        $this->app->instance(BrowsershotPdfRenderer::class, $renderer);

        $this->actingAs($user)
            ->get('https://dev.stelfaro.com/facturacion/libros-iva/pdf?book=all&month=8&year=2026')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-iva-books', false);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://core.test/api/v1/dte/annexes/sales?empresa_id=77&from=2026-08-01&to=2026-08-31');
    }

    public function test_it_accepts_zero_padded_month(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.internal_token' => 'internal-secret',
            'platform.portal.host' => 'dev.stelfaro.com',
        ]);

        [$user, $tenant] = $this->userWithFacturacionTenant();

        Http::fake([
            'https://core.test/api/v1/internal/auth/billing-session' => Http::response(['token' => 'core-token']),
            'https://core.test/api/v1/billing/context' => Http::response([
                'empresas' => [[
                    'id' => 77,
                    'razon_social' => 'Empresa Demo S.A. de C.V.',
                    'nombre_comercial' => 'Empresa Demo',
                    'nit' => '06140101001010',
                    'nrc' => '1234567',
                ]],
            ]),
            'https://core.test/api/v1/dte/annexes/sales*' => Http::response(['data' => []]),
        ]);

        $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn('%PDF-iva-books');
        $this->app->instance(BrowsershotPdfRenderer::class, $renderer);

        // El frontend siempre envía el mes con cero a la izquierda (padStart), por
        // ejemplo "08". filter_var('08', FILTER_VALIDATE_INT) es false, así que la
        // regla `integer` rechazaba este valor y Laravel redirigía con back() en
        // lugar de servir el PDF.
        $this->actingAs($user)
            ->get('https://dev.stelfaro.com/facturacion/libros-iva/pdf?book=all&month=08&year=2026')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithFacturacionTenant(): array
    {
        $app = PlatformApp::query()->create([
            'key' => 'facturacion',
            'name' => 'Facturación',
            'host' => 'dev.stelfaro.com',
            'default_path' => '/',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'slug' => 'empresa-demo',
            'name' => 'Empresa Demo',
            'primary_app_id' => $app->id,
            'metadata' => ['core_empresa_id' => 77],
        ]);
        $tenant->appAccesses()->create([
            'platform_app_id' => $app->id,
            'status' => 'active',
            'is_default' => true,
        ]);
        $user = User::factory()->create();
        $user->memberships()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $tenant];
    }
}
