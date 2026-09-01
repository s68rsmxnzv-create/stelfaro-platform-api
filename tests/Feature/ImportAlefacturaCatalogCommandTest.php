<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportAlefacturaCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_dry_run_or_commit_and_preserves_legacy_metadata(): void
    {
        Storage::fake('local');
        $tenant = Tenant::query()->create(['slug' => 'laboratorio-clinico-axel', 'name' => 'Laboratorio Clínico Axel', 'status' => 'active']);
        $path = $this->package('laboratorio-clinico-axel');
        $arguments = [
            'tenant' => $tenant->id,
            '--source' => $path,
            '--branch-id' => 10,
            '--branch-code' => 'M001',
            '--branch-name' => 'Casa matriz',
        ];

        try {
            $this->artisan('platform:inventory:import-legacy', $arguments)
                ->expectsOutputToContain('requiere --dry-run o --commit')
                ->assertFailed();

            $this->artisan('platform:inventory:import-legacy', [...$arguments, '--dry-run' => true])
                ->expectsOutputToContain('Ensayo finalizado')
                ->assertSuccessful();
            $this->assertDatabaseCount('catalog_items', 0);

            $this->artisan('platform:inventory:import-legacy', [...$arguments, '--commit' => true])
                ->expectsOutputToContain('Respaldo creado')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $category = CatalogCategory::query()->sole();
        $this->assertSame('alefactura', $category->legacy_reference['source']);
        $this->assertSame('laboratorio-clinico-axel', $category->legacy_reference['tenant_id']);

        $item = CatalogItem::query()->sole();
        $this->assertSame('ART-000001', $item->sku);
        $this->assertSame('service', $item->item_type);
        $this->assertSame('15.50', $item->base_price);
        $this->assertTrue($item->base_price_includes_tax);
        $this->assertFalse($item->controls_inventory);
        $this->assertSame('alefactura', $item->metadata['legacy_source']);
        $this->assertTrue($item->metadata['legacy_is_quick_access']);
        $this->assertSame('LAB-1', $item->metadata['legacy_item_code']);
        $this->assertCount(1, Storage::disk('local')->allFiles('backups'));
    }

    public function test_it_rejects_a_package_for_another_tenant(): void
    {
        $tenant = Tenant::query()->create(['slug' => 'mas-frio', 'name' => 'MAS FRIO', 'status' => 'active']);
        $path = $this->package('laboratorio-clinico-axel');

        try {
            $this->artisan('platform:inventory:import-legacy', [
                'tenant' => $tenant->id,
                '--source' => $path,
                '--branch-id' => 10,
                '--dry-run' => true,
            ])->expectsOutputToContain('no coincide')->assertFailed();
        } finally {
            @unlink($path);
        }
    }

    private function package(string $slug): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/alefactura-catalog-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'legacy_source' => 'alefactura',
            'legacy_tenant_id' => $slug,
            'source' => ['application' => 'alefactura', 'tenant_slug' => $slug, 'environment' => 'production'],
            'categories' => [[
                'id' => 5, 'nombre' => 'Servicios', 'tipo' => 'servicio', 'activo' => true,
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]],
            'suppliers' => [],
            'items' => [[
                'id' => 8, 'categoria_item_id' => 5, 'proveedor_id' => null, 'codigo' => 'ART-000001',
                'nombre' => 'Servicio de laboratorio', 'descripcion' => 'Examen', 'tipo' => 'servicio',
                'unidad_medida' => '59', 'unidad_personalizada' => null, 'unidades_por_empaque' => 1,
                'afecto_igv' => true, 'controla_stock' => false, 'precio_venta' => 15.50,
                'precio_venta_incluye_iva' => true, 'precio_compra' => 0, 'stock_actual' => 0,
                'stock_minimo' => 0, 'activo' => true, 'legacy_item_code' => 'LAB-1',
                'legacy_is_quick_access' => true, 'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]],
            'purchases' => [], 'purchase_lines' => [], 'lots' => [], 'movements' => [],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
