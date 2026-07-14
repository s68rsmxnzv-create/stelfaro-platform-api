<?php

namespace App\Services\Inventory;

use App\Models\CatalogItem;
use App\Models\InventorySupplier;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryPurchaseImportService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, array $payload): array
    {
        $dte = $this->extractDtePayload($payload);
        if (! $dte) {
            throw new InvalidArgumentException('El JSON no contiene un DTE valido con cuerpoDocumento.');
        }

        $identification = Arr::get($dte, 'identificacion', []);
        $summary = Arr::get($dte, 'resumen', []);
        $issuer = Arr::get($dte, 'emisor', []);
        $body = Arr::get($dte, 'cuerpoDocumento', []);
        if (! is_array($body) || $body === []) {
            throw new InvalidArgumentException('El JSON no contiene lineas de compra.');
        }

        $supplier = $this->matchSupplier($tenant, (array) $issuer);
        $fuel = $this->fuelCharges($dte);
        $taxPerceived = $this->taxPerceived($dte);
        $regularTax = $this->regularTax($dte);

        return [
            'document' => [
                'document_type' => $this->documentType((string) Arr::get($identification, 'tipoDte', '03')),
                'document_mode' => 'dte',
                'document_number' => trim((string) (Arr::get($identification, 'codigoGeneracion') ?: Arr::get($identification, 'numeroControl'))),
                'purchase_date' => trim((string) Arr::get($identification, 'fecEmi')) ?: now()->toDateString(),
                'payment_condition' => ((int) Arr::get($summary, 'condicionOperacion', 1)) === 2 ? 'credit' : 'cash',
                'subtotal' => $this->summarySubtotal((array) $summary),
                'tax_amount' => $regularTax,
                'document_total' => round((float) (Arr::get($summary, 'totalPagar') ?? Arr::get($summary, 'montoTotalOperacion') ?? 0), 2),
                'apply_tax_perceived' => $taxPerceived > 0,
                'tax_perceived_mode' => $taxPerceived > 0 ? 'dte' : 'auto',
                'tax_perceived_rate' => 1,
                'tax_perceived_amount' => $taxPerceived,
                'apply_fuel_charges' => $fuel !== null,
                'fovial_per_unit' => $fuel['fovial_per_unit'] ?? 0,
                'cotrans_per_unit' => $fuel['cotrans_per_unit'] ?? 0,
            ],
            'supplier' => [
                'matched' => $supplier,
                'from_json' => [
                    'name' => trim((string) (Arr::get($issuer, 'nombre') ?: Arr::get($issuer, 'nombreComercial'))),
                    'tax_id' => trim((string) Arr::get($issuer, 'nit')),
                    'nrc' => trim((string) Arr::get($issuer, 'nrc')),
                    'phone' => trim((string) (Arr::get($issuer, 'telefono') ?: '')),
                    'email' => trim((string) (Arr::get($issuer, 'correo') ?: '')),
                    'address' => $this->addressFromIssuer((array) $issuer),
                ],
            ],
            'lines' => collect($body)
                ->filter(fn ($line): bool => is_array($line) && (float) Arr::get($line, 'cantidad', 0) > 0)
                ->map(fn (array $line): array => $this->linePreview($tenant, $line))
                ->values()
                ->all(),
            'import_metadata' => [
                'source' => 'supplier_dte_json',
                'payload_hash' => hash('sha256', json_encode($dte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                'tipo_dte' => Arr::get($identification, 'tipoDte'),
                'codigoGeneracion' => Arr::get($identification, 'codigoGeneracion'),
                'numero_control' => Arr::get($identification, 'numeroControl'),
                'tributes' => $this->tributesSummary((array) Arr::get($dte, 'resumen.tributos', [])),
                'dte_json' => $dte,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractDtePayload(array $payload): ?array
    {
        $candidates = [
            $payload,
            Arr::get($payload, 'dte.json'),
            Arr::get($payload, 'dteJson'),
            Arr::get($payload, 'json'),
            Arr::get($payload, 'payload'),
            Arr::get($payload, 'body.dteJson'),
            Arr::get($payload, 'body.json'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && is_array(Arr::get($candidate, 'cuerpoDocumento')) && Arr::get($candidate, 'cuerpoDocumento') !== []) {
                return $candidate;
            }
        }

        return null;
    }

    private function matchSupplier(Tenant $tenant, array $issuer): ?array
    {
        $ids = array_filter([
            $this->normalizeTaxId(Arr::get($issuer, 'nit')),
            $this->normalizeTaxId(Arr::get($issuer, 'nrc')),
        ]);
        if ($ids === []) {
            return null;
        }

        $supplier = InventorySupplier::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->first(fn (InventorySupplier $supplier): bool => in_array($this->normalizeTaxId($supplier->tax_id), $ids, true)
                || in_array($this->normalizeTaxId($supplier->nrc), $ids, true));

        return $supplier ? [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'tax_id' => $supplier->tax_id,
            'nrc' => $supplier->nrc,
        ] : null;
    }

    private function linePreview(Tenant $tenant, array $line): array
    {
        $rawDescription = (string) (Arr::get($line, 'descripcion') ?: 'Linea DTE');
        $description = $this->cleanLineDescription($rawDescription);
        $supplierCode = $this->lineCode($line, $rawDescription);
        $item = $this->matchItem($tenant, $description);

        return [
            'description' => $description,
            'quantity' => round((float) Arr::get($line, 'cantidad', 0), 3),
            'unit_cost' => round($this->unitCost($line), 4),
            'subtotal' => round($this->lineSubtotal($line), 2),
            'unit_code' => (string) (Arr::get($line, 'uniMedida') ?: '59'),
            'supplier_code' => $supplierCode,
            'no_inventory' => $this->inferNoInventory($description) || ($item && ! $item->controls_inventory),
            'matched_catalog_item' => $item ? [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'controls_inventory' => (bool) $item->controls_inventory,
            ] : null,
        ];
    }

    private function matchItem(Tenant $tenant, string $description): ?CatalogItem
    {
        $needle = $this->normalizeText($description);
        if ($needle === '') {
            return null;
        }

        $items = CatalogItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get();

        $exact = $items->first(fn (CatalogItem $item): bool => $this->normalizeText($item->name) === $needle);
        if ($exact) {
            return $exact;
        }

        return null;
    }

    private function unitCost(array $line): float
    {
        $quantity = (float) Arr::get($line, 'cantidad', 0);
        if ($quantity <= 0) {
            return 0.0;
        }

        $net = $this->lineSubtotal($line);
        if ($net > 0) {
            return $net / $quantity;
        }

        $price = (float) Arr::get($line, 'precioUni', 0);
        $discount = max(0.0, (float) Arr::get($line, 'montoDescu', 0));

        return $price > 0 ? max(($price * $quantity) - $discount, 0) / $quantity : 0.0;
    }

    private function lineSubtotal(array $line): float
    {
        return (float) Arr::get($line, 'ventaGravada', 0)
            + (float) Arr::get($line, 'ventaExenta', 0)
            + (float) Arr::get($line, 'ventaNoSuj', 0)
            + (float) Arr::get($line, 'compra', 0);
    }

    private function summarySubtotal(array $summary): float
    {
        $subtotal = (float) Arr::get($summary, 'totalGravada', 0)
            + (float) Arr::get($summary, 'totalExenta', 0)
            + (float) Arr::get($summary, 'totalNoSuj', 0);

        return round($subtotal > 0 ? $subtotal : (float) Arr::get($summary, 'subTotalVentas', 0), 2);
    }

    private function fuelCharges(array $dte): ?array
    {
        $body = Arr::get($dte, 'cuerpoDocumento', []);
        $gallons = collect(is_array($body) ? $body : [])->sum(fn ($line): float => $this->gallons($line));
        if ($gallons <= 0) {
            return null;
        }

        $fovial = 0.0;
        $cotrans = 0.0;
        foreach ((array) Arr::get($dte, 'resumen.tributos', []) as $tax) {
            $code = strtoupper(trim((string) Arr::get($tax, 'codigo')));
            $description = $this->normalizeText((string) Arr::get($tax, 'descripcion'));
            $value = (float) Arr::get($tax, 'valor', 0);
            if ($code === 'C8' || str_contains($description, 'FOVIAL')) {
                $fovial += $value;
            }
            if ($code === '59' || str_contains($description, 'COTRANS')) {
                $cotrans += $value;
            }
        }

        if ($fovial <= 0 && $cotrans <= 0) {
            return null;
        }

        return [
            'gallons' => round($gallons, 3),
            'fovial_per_unit' => round($fovial / $gallons, 4),
            'cotrans_per_unit' => round($cotrans / $gallons, 4),
        ];
    }

    private function taxPerceived(array $dte): float
    {
        $summaryAmount = (float) Arr::get($dte, 'resumen.ivaPerci1', 0);
        if ($summaryAmount > 0) {
            return round($summaryAmount, 2);
        }

        $total = 0.0;
        foreach ((array) Arr::get($dte, 'resumen.tributos', []) as $tax) {
            $description = $this->normalizeText((string) Arr::get($tax, 'descripcion'));
            $value = (float) Arr::get($tax, 'valor', 0);

            if ($value > 0 && str_contains($description, 'IVA PERCIB')) {
                $total += $value;
            }
        }

        return round($total, 2);
    }

    private function regularTax(array $dte): float
    {
        $summaryAmount = (float) Arr::get($dte, 'resumen.totalIva', 0);
        if ($summaryAmount > 0) {
            return round($summaryAmount, 2);
        }

        $total = 0.0;
        foreach ((array) Arr::get($dte, 'resumen.tributos', []) as $tax) {
            $code = strtoupper(trim((string) Arr::get($tax, 'codigo')));
            $description = $this->normalizeText((string) Arr::get($tax, 'descripcion'));
            $value = (float) Arr::get($tax, 'valor', 0);

            if ($value <= 0 || str_contains($description, 'PERCIB') || str_contains($description, 'RETEN')) {
                continue;
            }

            if ($code === '20' || str_contains($description, 'VALOR AGREGADO') || str_contains($description, 'IVA 13')) {
                $total += $value;
            }
        }

        return round($total, 2);
    }

    /**
     * @param  array<int, mixed>  $tributes
     * @return array<int, array{codigo:string|null,descripcion:string|null,valor:float}>
     */
    private function tributesSummary(array $tributes): array
    {
        return collect($tributes)
            ->filter(fn ($tax): bool => is_array($tax))
            ->map(fn (array $tax): array => [
                'codigo' => Arr::get($tax, 'codigo'),
                'descripcion' => Arr::get($tax, 'descripcion'),
                'valor' => round((float) Arr::get($tax, 'valor', 0), 2),
            ])
            ->values()
            ->all();
    }

    private function gallons(mixed $line): float
    {
        if (! is_array($line)) {
            return 0.0;
        }

        $quantity = (float) Arr::get($line, 'cantidad', 0);
        $unit = strtolower(trim((string) Arr::get($line, 'uniMedida')));
        $description = $this->normalizeText((string) Arr::get($line, 'descripcion'));

        return $quantity > 0 && ($unit === '22' || $unit === 'galon' || str_contains($description, 'GALON') || str_contains($description, 'COMBUSTIBLE'))
            ? $quantity
            : 0.0;
    }

    private function documentType(string $type): string
    {
        return match (trim($type)) {
            '01' => 'dte_fcf',
            '03' => 'dte_ccf',
            default => 'dte_ccf',
        };
    }

    private function inferNoInventory(string $description): bool
    {
        $text = $this->normalizeText($description);

        return collect(['ENVIO', 'FLETE', 'SERVICIO', 'TRANSPORTE', 'MANEJO', 'CARGO'])
            ->contains(fn (string $token): bool => str_contains($text, $token));
    }

    private function cleanLineDescription(string $description): string
    {
        $text = trim($description);
        if ($text === '') {
            return 'Linea DTE';
        }

        if (str_contains($text, '|')) {
            [$prefix, $rest] = array_map('trim', explode('|', $text, 2));
            if ($rest !== '' && $this->looksLikeSupplierCode($prefix)) {
                return $rest;
            }
        }

        return $text;
    }

    private function lineCode(array $line, string $rawDescription): ?string
    {
        foreach (['codigo', 'codProducto', 'codigoProducto', 'codigoItem', 'codItem', 'sku'] as $key) {
            $value = trim((string) Arr::get($line, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        $text = trim($rawDescription);
        if (! str_contains($text, '|')) {
            return null;
        }

        [$prefix] = array_map('trim', explode('|', $text, 2));

        return $this->looksLikeSupplierCode($prefix) ? $prefix : null;
    }

    private function looksLikeSupplierCode(string $value): bool
    {
        $code = strtoupper(trim($value));
        if ($code === '' || strlen($code) > 40) {
            return false;
        }

        return preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code) === 1
            && preg_match('/\d/', $code) === 1;
    }

    private function addressFromIssuer(array $issuer): ?string
    {
        $address = Arr::get($issuer, 'direccion');
        if (! is_array($address)) {
            return null;
        }

        return trim((string) Arr::get($address, 'complemento')) ?: null;
    }

    private function normalizeTaxId(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '';
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii($value))));
    }

}
