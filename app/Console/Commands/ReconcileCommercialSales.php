<?php

namespace App\Console\Commands;

use App\Models\InventorySale;
use App\Services\FiscalSyncCoreClient;
use Illuminate\Console\Command;

class ReconcileCommercialSales extends Command
{
    protected $signature = 'commercial-sales:reconcile-fiscal {--tenant=}';

    protected $description = 'Reconcilia neto e IVA de ventas históricas con el JSON fiscal conservado';

    public function handle(FiscalSyncCoreClient $core): int
    {
        $updated = 0;
        $missing = 0;
        $query = InventorySale::query()
            ->whereIn('fiscal_document_type', ['01', '03', '05', '06'])
            ->when($this->option('tenant'), fn ($builder) => $builder->where('tenant_id', (int) $this->option('tenant')))
            ->orderBy('id');

        $query->each(function (InventorySale $sale) use ($core, &$updated, &$missing): void {
            $documentId = (string) (($sale->metadata ?? [])['core_dte_document_id'] ?? ($sale->source_type === 'dte' ? $sale->source_id : ''));
            if ($documentId === '') {
                $missing++;

                return;
            }
            $document = $core->dte($documentId);
            $payload = is_array($document['dte_json'] ?? null) ? $document['dte_json'] : (is_array($document['payload'] ?? null) ? $document['payload'] : []);
            $summary = is_array($payload['resumen'] ?? null) ? $payload['resumen'] : [];
            if ($summary === []) {
                $missing++;

                return;
            }

            $tax = $this->tax($summary);
            $gross = 0.0;
            if ($sale->fiscal_document_type === '01') {
                $gross = round((float) ($summary['montoTotalOperacion'] ?? $summary['totalPagar'] ?? 0), 2);
                $net = round(max(0, $gross - $tax), 2);
            } else {
                $net = round(array_sum(array_map(
                    fn (string $key): float => is_numeric($summary[$key] ?? null) ? (float) $summary[$key] : 0,
                    ['totalGravada', 'totalExenta', 'totalNoSuj', 'totalNoGravado'],
                )), 2);
                $gross = round($net + $tax, 2);
            }
            if ($net <= 0 && is_numeric($summary['totalPagar'] ?? null)) {
                $net = round(max(0, (float) $summary['totalPagar'] - $tax), 2);
                $gross = round($net + $tax, 2);
            }
            $sale->forceFill([
                'net_amount' => $net,
                'tax_amount' => $tax,
                'total_amount' => $gross,
            ])->save();
            $updated++;
        });

        $this->info("Ventas reconciliadas: {$updated}; sin JSON fiscal: {$missing}.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function tax(array $summary): float
    {
        if (is_numeric($summary['totalIva'] ?? null)) {
            return round((float) $summary['totalIva'], 2);
        }
        $tributes = is_array($summary['tributos'] ?? null) ? $summary['tributos'] : [];
        foreach ($tributes as $tribute) {
            if (is_array($tribute) && (string) ($tribute['codigo'] ?? '') === '20') {
                return round((float) ($tribute['valor'] ?? 0), 2);
            }
        }

        return 0;
    }
}
