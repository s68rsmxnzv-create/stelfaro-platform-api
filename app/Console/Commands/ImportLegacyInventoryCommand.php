<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\LegacyInventoryImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ImportLegacyInventoryCommand extends Command
{
    protected $signature = 'platform:inventory:import-legacy
        {tenant : ID del tenant de destino}
        {--source=- : Archivo JSON o - para stdin}
        {--replace : Sustituir el inventario actual}
        {--dry-run : Validar sin escribir}';

    protected $description = 'Valida e importa catálogo, compras y existencias desde Stelfaro legado.';

    public function handle(LegacyInventoryImportService $importer): int
    {
        $tenant = Tenant::query()->find($this->argument('tenant'));
        if (! $tenant) {
            $this->error('No se encontró el tenant de destino.');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');
        $json = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload)) {
            $this->error('No fue posible leer un JSON legado válido.');

            return self::FAILURE;
        }

        try {
            $summary = $importer->analyze($payload);
            if ($this->option('dry-run')) {
                $this->table(['Dato', 'Valor'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values());
                $this->info('Ensayo finalizado sin escribir datos.');

                return self::SUCCESS;
            }

            if ($this->option('replace')) {
                $backup = $importer->backup($tenant);
                $path = 'backups/inventory-tenant-'.$tenant->id.'-'.now()->format('Ymd-His').'.json';
                Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info('Respaldo creado en storage/app/private/'.$path);
            }

            $summary = $importer->import($tenant, $payload, (bool) $this->option('replace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Dato', 'Valor'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values());
        $this->info('Inventario legado importado correctamente.');

        return self::SUCCESS;
    }
}
