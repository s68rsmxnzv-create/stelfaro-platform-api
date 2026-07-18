<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\LegacyInventoryImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ClearTenantInventoryCommand extends Command
{
    protected $signature = 'platform:inventory:clear
        {tenant : ID del tenant}
        {--force : Confirmar la limpieza completa}';

    protected $description = 'Respalda y limpia catálogo, compras y operaciones de inventario de un tenant.';

    public function handle(LegacyInventoryImportService $inventory): int
    {
        $tenant = Tenant::query()->find($this->argument('tenant'));
        if (! $tenant) {
            $this->error('No se encontró el tenant.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->error('La limpieza requiere --force.');

            return self::FAILURE;
        }

        $backup = $inventory->backup($tenant);
        $path = 'backups/inventory-tenant-'.$tenant->id.'-before-clear-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $counts = $inventory->clear($tenant);

        $this->info('Respaldo creado en storage/app/private/'.$path);
        $this->table(['Tabla', 'Eliminados'], collect($counts)->filter()->map(fn ($count, $table) => [$table, $count])->values());
        $this->info('Inventario limpiado correctamente.');

        return self::SUCCESS;
    }
}
