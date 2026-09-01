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
        {--branch-id= : ID de Casa Matriz en dte-core}
        {--branch-code=M001 : Código de Casa Matriz}
        {--branch-name=Casa matriz : Nombre de Casa Matriz}
        {--replace : Sustituir el inventario actual}
        {--dry-run : Validar sin escribir}
        {--commit : Confirmar escritura para paquetes de Alefactura}';

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

        $isAlefactura = ($payload['legacy_source'] ?? null) === 'alefactura';
        if ($isAlefactura) {
            $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
            if (($source['environment'] ?? null) !== 'production') {
                $this->error('Los paquetes de Alefactura deben provenir del ambiente production.');

                return self::FAILURE;
            }
            if (($source['tenant_slug'] ?? null) !== $tenant->slug) {
                $this->error('El tenant de origen no coincide con el tenant de destino.');

                return self::FAILURE;
            }
            if (! $this->option('dry-run') && ! $this->option('commit')) {
                $this->error('La importación de Alefactura requiere --dry-run o --commit.');

                return self::FAILURE;
            }
        }

        $payload['target_branch'] = [
            'id' => (int) $this->option('branch-id'),
            'code' => trim((string) $this->option('branch-code')),
            'name' => trim((string) $this->option('branch-name')),
        ];

        try {
            $summary = $importer->analyze($payload);
            if ($this->option('dry-run')) {
                $this->table(['Dato', 'Valor'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values());
                $this->info('Ensayo finalizado sin escribir datos.');

                return self::SUCCESS;
            }

            if ($this->option('replace') || $isAlefactura) {
                $backup = $importer->backup($tenant);
                $path = 'backups/inventory-tenant-'.$tenant->id.'-'.now()->format('Ymd-His').'.json';
                $written = Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if (! $written) {
                    throw new InvalidArgumentException('No se pudo crear el respaldo; la importación fue cancelada.');
                }
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
