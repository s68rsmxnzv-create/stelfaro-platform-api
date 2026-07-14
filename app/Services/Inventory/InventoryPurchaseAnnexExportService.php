<?php

namespace App\Services\Inventory;

use App\Models\InventoryPurchase;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InventoryPurchaseAnnexExportService
{
    public const HEADERS = [
        'Fecha de emision',
        'Clase de documento',
        'Tipo de documento',
        'Numero de documento',
        'NRC proveedor',
        'Nombre proveedor',
        'Compras internas exentas/no sujetas',
        'Internaciones exentas/no sujetas',
        'Importaciones exentas/no sujetas',
        'Compras internas gravadas',
        'Internaciones gravadas bienes',
        'Importaciones gravadas bienes',
        'Importaciones gravadas servicios',
        'Credito fiscal',
        'Total compras',
        'DUI proveedor',
        'Tipo de operacion',
        'Clasificacion',
        'Sector',
        'Tipo costo/gasto',
        'Anexo',
    ];

    /**
     * @return array{
     *     data: array{compras: array{official_rows: array<int, array<int, string>>, preview: array<int, array<string, mixed>>, issues: array<int, string>}},
     *     headers: array{compras: array<int, string>},
     *     meta: array{counts: array{compras: int}, period: array{from: ?string, to: ?string}}
     * }
     */
    public function build(Tenant $tenant, ?string $from = null, ?string $to = null): array
    {
        $purchases = $this->purchases($tenant, $from, $to);
        $rows = [];
        $preview = [];
        $issues = [];

        foreach ($purchases as $purchase) {
            [$row, $itemPreview, $itemIssues] = $this->mapPurchase($purchase);
            $rows[] = $row;
            $preview[] = $itemPreview;
            array_push($issues, ...$itemIssues);
        }

        return [
            'data' => [
                'compras' => [
                    'official_rows' => $rows,
                    'preview' => $preview,
                    'issues' => array_values(array_unique($issues)),
                ],
            ],
            'headers' => [
                'compras' => self::HEADERS,
            ],
            'meta' => [
                'counts' => [
                    'compras' => count($rows),
                ],
                'period' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ],
        ];
    }

    public function csv(Tenant $tenant, ?string $from = null, ?string $to = null): string
    {
        $payload = $this->build($tenant, $from, $to);
        $csv = collect($payload['data']['compras']['official_rows'])
            ->map(fn (array $row): string => implode(';', array_map([$this, 'csvCell'], $row)))
            ->implode("\r\n")."\r\n";

        return iconv('UTF-8', 'Windows-1252//TRANSLIT', $csv) ?: $csv;
    }

    /**
     * @return Collection<int, InventoryPurchase>
     */
    private function purchases(Tenant $tenant, ?string $from, ?string $to): Collection
    {
        return InventoryPurchase::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['voided', 'cancelled', 'canceled', 'deleted'])
            ->with(['supplier:id,name,tax_id,nrc', 'lines:id,inventory_purchase_id,description_snapshot,quantity,line_total'])
            ->when($from, fn ($query) => $query->whereDate('purchase_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('purchase_date', '<=', $to))
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0: array<int, string>, 1: array<string, mixed>, 2: array<int, string>}
     */
    private function mapPurchase(InventoryPurchase $purchase): array
    {
        $supplierSnapshot = is_array($purchase->supplier_snapshot) ? $purchase->supplier_snapshot : [];
        $metadata = is_array($purchase->import_metadata) ? $purchase->import_metadata : [];
        $supplier = $purchase->supplier;
        $documentType = $this->documentType($purchase->document_type, $metadata);
        $documentNumber = $this->normalizeAlnum($this->documentNumber($purchase, $metadata));
        $supplierName = trim((string) ($supplier?->name ?? ($supplierSnapshot['name'] ?? '')));
        $supplierNrc = $this->normalizeDigits((string) ($supplier?->nrc ?? ($supplierSnapshot['nrc'] ?? '')));
        $supplierTaxId = $this->normalizeDigits((string) ($supplier?->tax_id ?? ($supplierSnapshot['tax_id'] ?? '')));
        $supplierDui = strlen($supplierTaxId) === 9 ? $supplierTaxId : '';
        $supplierDoc = $supplierDui !== '' ? '' : ($supplierNrc !== '' ? $supplierNrc : $supplierTaxId);
        $subtotal = (float) $purchase->subtotal;
        $tax = (float) $purchase->tax_amount;
        $nonTaxable = (float) $purchase->other_non_taxable_total;
        $total = (float) $purchase->total;
        $class = in_array($documentType, ['12', '13'], true) ? '3' : '4';

        $internalExempt = $nonTaxable;
        $internalTaxed = $subtotal;
        $importExempt = 0.0;
        $importTaxedGoods = 0.0;
        $importTaxedServices = 0.0;

        if ($documentType === '12') {
            $internalExempt = 0.0;
            $internalTaxed = 0.0;
            $importExempt = $nonTaxable;
            $importTaxedGoods = $subtotal;
        } elseif ($documentType === '13') {
            $internalExempt = 0.0;
            $internalTaxed = 0.0;
            $importExempt = $nonTaxable;
            $importTaxedServices = $subtotal;
        }

        [$q, $r, $s, $t] = $this->qrst($purchase);
        $issues = [];
        $label = $documentNumber !== '' ? $documentNumber : 'compra '.$purchase->id;

        if ($documentType === '') {
            $issues[] = "Compra {$label}: falta tipo de documento.";
        }
        if ($documentNumber === '') {
            $issues[] = "Compra {$label}: falta numero de documento.";
        }
        if ($supplierName === '') {
            $issues[] = "Compra {$label}: falta nombre del proveedor.";
        }
        if ($supplierDoc === '' && $supplierDui === '') {
            $issues[] = "Compra {$label}: falta NRC, NIT o DUI del proveedor.";
        }
        if ($q === '0' || $r === '0' || $s === '0' || $t === '0') {
            $issues[] = "Compra {$label}: falta clasificacion F07.";
        }

        $row = [
            $purchase->purchase_date ? $purchase->purchase_date->format('d/m/Y') : '',
            $class,
            $documentType,
            $documentNumber,
            $supplierDoc,
            $supplierName,
            $this->num($internalExempt),
            $this->num(0),
            $this->num($importExempt),
            $this->num($internalTaxed),
            $this->num(0),
            $this->num($importTaxedGoods),
            $this->num($importTaxedServices),
            $this->num($tax),
            $this->num($total),
            $supplierDui,
            $q,
            $r,
            $s,
            $t,
            '3',
        ];

        return [$row, [
            'purchase_id' => $purchase->id,
            'fecha_emision' => $purchase->purchase_date?->toDateString(),
            'tipo_dte' => $documentType,
            'numero_documento' => $documentNumber,
            'proveedor_nombre' => $supplierName,
            'total_pagar' => $total,
            'q' => $q,
            'r' => $r,
            's' => $s,
            't' => $t,
            'items' => $purchase->lines->take(40)->map(fn ($line): array => [
                'descripcion' => $line->description_snapshot,
                'cantidad' => $this->num((float) $line->quantity),
                'monto' => $this->num((float) $line->line_total),
            ])->values()->all(),
        ], $issues];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function qrst(InventoryPurchase $purchase): array
    {
        $date = $purchase->purchase_date instanceof Carbon ? $purchase->purchase_date : null;
        if ($date && $date->lt(Carbon::create(2024, 2, 1))) {
            return ['0', '0', '0', '0'];
        }

        return [
            $this->singleDigitCode($purchase->f07_operation_type),
            $this->singleDigitCode($purchase->f07_classification),
            $this->singleDigitCode($purchase->f07_sector),
            $this->singleDigitCode($purchase->f07_cost_expense_type),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function documentType(?string $type, array $metadata): string
    {
        $metadataType = str_pad((string) ($metadata['tipo_dte'] ?? ''), 2, '0', STR_PAD_LEFT);
        if (in_array($metadataType, ['01', '03', '05', '06', '11', '12', '13'], true)) {
            return $metadataType;
        }

        return match (strtolower(trim((string) $type))) {
            'dte_fcf', 'fcf' => '01',
            'dte_ccf', 'ccf' => '03',
            'fse' => '11',
            'nota_envio' => '04',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function documentNumber(InventoryPurchase $purchase, array $metadata): string
    {
        return (string) ($metadata['codigoGeneracion']
            ?? $metadata['codigo_generacion']
            ?? $metadata['numero_control']
            ?? $purchase->document_number
            ?? '');
    }

    private function singleDigitCode(mixed $value): string
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $value) ?? '';

        return $clean === '' ? '0' : substr($clean, 0, 1);
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeAlnum(string $value): string
    {
        return preg_replace('/[^A-Z0-9-]/', '', strtoupper($value)) ?? '';
    }

    private function num(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) $value;
        if (str_contains($cell, ';') || str_contains($cell, '"') || str_contains($cell, "\n") || str_contains($cell, "\r")) {
            return '"'.str_replace('"', '""', $cell).'"';
        }

        return $cell;
    }
}
