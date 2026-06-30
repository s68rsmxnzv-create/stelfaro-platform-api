<?php

namespace App\Services\Inventory;

use App\Models\CatalogItem;
use App\Models\Tenant;
use Carbon\Carbon;
use InvalidArgumentException;

class InventoryPurchaseTaxService
{
    private const TAX_RATE = 0.13;
    private const F07_QRST_REQUIRED_FROM = '2024-02-01';
    private const F07_PROFILES = [
        'sales_expense' => ['q' => 1, 'r' => 2, 't' => 1],
        'administrative_expense' => ['q' => 1, 'r' => 2, 't' => 2],
        'financial_expense' => ['q' => 1, 'r' => 2, 't' => 3],
        'import_cost' => ['q' => 1, 'r' => 1, 't' => 4],
        'internal_cost' => ['q' => 1, 'r' => 1, 't' => 5],
        'manufacturing_overhead' => ['q' => 1, 'r' => 1, 't' => 6],
        'labor_cost' => ['q' => 1, 'r' => 1, 't' => 7],
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizePurchase(Tenant $tenant, array $data): array
    {
        $documentType = strtolower(trim((string) ($data['document_type'] ?? '')));
        $documentMode = str_starts_with($documentType, 'dte_') ? 'dte' : (trim((string) ($data['document_mode'] ?? 'manual')) ?: 'manual');
        $isFiscal = $this->hasFiscalCredit($documentType);
        $isConsumable = (bool) ($data['is_consumable'] ?? false);
        $taxRate = $isFiscal ? self::TAX_RATE : 0.0;
        $purchaseDate = (string) $data['purchase_date'];

        $lines = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $quantityTotal = 0.0;

        foreach ($data['lines'] as $line) {
            /** @var CatalogItem $item */
            $item = CatalogItem::query()
                ->where('tenant_id', $tenant->id)
                ->findOrFail((int) $line['catalog_item_id']);

            $quantity = $this->positive((float) $line['quantity'], 'La cantidad de compra debe ser mayor que cero.');
            $inputUnitCost = round((float) $line['unit_cost'], 4);
            if ($inputUnitCost < 0) {
                throw new InvalidArgumentException('El costo unitario no puede ser negativo.');
            }

            $priceIncludesTax = $isFiscal && (bool) ($line['price_includes_tax'] ?? false);
            if ($priceIncludesTax) {
                $baseUnit = round($inputUnitCost / (1 + $taxRate), 4);
                $taxUnit = round($inputUnitCost - $baseUnit, 4);
                $totalUnit = round($inputUnitCost, 4);
            } else {
                $baseUnit = round($inputUnitCost, 4);
                $taxUnit = round($baseUnit * $taxRate, 4);
                $totalUnit = round($baseUnit + $taxUnit, 4);
            }

            $noInventory = $isConsumable
                || (bool) ($line['no_inventory'] ?? false)
                || ! (bool) $item->controls_inventory
                || in_array((string) $item->item_type, ['service', 'labor'], true);
            $inventoryQuantity = $noInventory ? 0.0 : $this->inventoryQuantity($item, $quantity);
            $inventoryUnitCost = $this->inventoryUnitCost($item, $baseUnit);
            $lineSubtotal = array_key_exists('subtotal', $line) && $line['subtotal'] !== null
                ? round((float) $line['subtotal'], 2)
                : round($quantity * $baseUnit, 2);
            $lineTax = round($lineSubtotal * $taxRate, 2);

            $lines[] = [
                'catalog_item_id' => $item->id,
                'item' => $item,
                'description_snapshot' => trim((string) ($line['description'] ?? $item->name)) ?: $item->name,
                'unit_code' => trim((string) ($line['unit_code'] ?? $item->unit_code ?? '59')) ?: '59',
                'unit_name' => trim((string) ($line['unit_name'] ?? $item->unit_name ?? '')) ?: null,
                'quantity' => $quantity,
                'input_unit_cost' => $inputUnitCost,
                'unit_cost' => $inventoryUnitCost,
                'base_unit_cost' => $baseUnit,
                'tax_unit_amount' => $taxUnit,
                'tax_rate' => $taxRate,
                'total_unit_amount' => $totalUnit,
                'tax_amount' => $lineTax,
                'line_total' => round($lineSubtotal + $lineTax, 2),
                'price_includes_tax' => $priceIncludesTax,
                'no_inventory' => $noInventory,
                'controls_inventory_snapshot' => (bool) $item->controls_inventory,
                'inventory_quantity' => $inventoryQuantity,
            ];

            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
            $quantityTotal += $quantity;
        }

        $taxTotal = $documentMode === 'dte' && array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null
            ? round((float) $data['tax_amount'], 2)
            : round($taxTotal, 2);
        $taxPerceived = $this->taxPerceived((float) $subtotal, $data);
        $fovial = max(0.0, round((float) ($data['fovial_per_unit'] ?? 0), 4));
        $cotrans = max(0.0, round((float) ($data['cotrans_per_unit'] ?? 0), 4));
        $otherNonTaxable = ((bool) ($data['apply_fuel_charges'] ?? ($fovial > 0 || $cotrans > 0)))
            ? round($quantityTotal * ($fovial + $cotrans), 2)
            : 0.0;
        $f07 = $this->f07Fields($purchaseDate, $data);
        $total = round($subtotal + $taxTotal + $taxPerceived + $otherNonTaxable, 2);

        $documentTotal = array_key_exists('document_total', $data) && $data['document_total'] !== null
            ? round((float) $data['document_total'], 2)
            : null;
        if ($documentMode === 'dte' && $documentTotal !== null && abs($total - $documentTotal) > 0.02) {
            throw new InvalidArgumentException('El total del documento DTE no coincide con la suma total de líneas.');
        }

        return [
            'document_type' => $documentType ?: null,
            'document_mode' => $documentMode,
            'payment_condition' => $this->paymentCondition($data['payment_condition'] ?? null),
            'document_total' => $documentTotal,
            'is_consumable' => $isConsumable,
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxTotal, 2),
            'tax_perceived' => $taxPerceived,
            'fovial_per_unit' => $fovial,
            'cotrans_per_unit' => $cotrans,
            'other_non_taxable_total' => $otherNonTaxable,
            'total' => $total,
            ...$f07,
            'lines' => $lines,
        ];
    }

    private function hasFiscalCredit(string $documentType): bool
    {
        return in_array($documentType, ['ccf', 'dte_ccf', 'fcf', 'dte_fcf', 'fse'], true);
    }

    private function paymentCondition(mixed $value): string
    {
        $text = strtolower(trim((string) $value));

        return in_array($text, ['credit', 'credito'], true) ? 'credit' : 'cash';
    }

    private function taxPerceived(float $subtotal, array $data): float
    {
        if (! (bool) ($data['apply_tax_perceived'] ?? false)) {
            return 0.0;
        }

        if (array_key_exists('tax_perceived_amount', $data) && $data['tax_perceived_amount'] !== null && $data['tax_perceived_amount'] !== '') {
            return round(max(0.0, (float) $data['tax_perceived_amount']), 2);
        }

        $mode = strtolower(trim((string) ($data['tax_perceived_mode'] ?? 'auto')));
        $rate = $mode === 'manual' ? (float) ($data['tax_perceived_rate'] ?? 0) / 100 : 0.01;

        return round($subtotal * max(0.0, $rate), 2);
    }

    /**
     * @return array<string, int|null>
     */
    private function f07Fields(string $date, array $data): array
    {
        $purchaseDate = Carbon::parse($date)->startOfDay();
        if ($purchaseDate->lt(Carbon::parse(self::F07_QRST_REQUIRED_FROM)->startOfDay())) {
            return [
                'f07_operation_type' => 0,
                'f07_classification' => 0,
                'f07_sector' => 0,
                'f07_cost_expense_type' => 0,
            ];
        }

        $profile = strtolower(trim((string) ($data['fiscal_profile'] ?? '')));
        $sector = isset($data['fiscal_sector']) ? (int) $data['fiscal_sector'] : null;
        $mapping = self::F07_PROFILES[$profile] ?? null;

        return [
            'f07_operation_type' => $mapping ? (int) $mapping['q'] : null,
            'f07_classification' => $mapping ? (int) $mapping['r'] : null,
            'f07_sector' => in_array($sector, [1, 2, 3, 4], true) ? $sector : null,
            'f07_cost_expense_type' => $mapping ? (int) $mapping['t'] : null,
        ];
    }

    private function inventoryQuantity(CatalogItem $item, float $quantity): float
    {
        $units = max((int) ($item->units_per_package ?: 1), 1);
        if (in_array((string) $item->unit_code, ['99', 'other'], true) && $units > 1) {
            return round($quantity * $units, 3);
        }

        return round($quantity, 3);
    }

    private function inventoryUnitCost(CatalogItem $item, float $baseUnitCost): float
    {
        $units = max((int) ($item->units_per_package ?: 1), 1);
        if (in_array((string) $item->unit_code, ['99', 'other'], true) && $units > 1) {
            return round($baseUnitCost / $units, 4);
        }

        return round($baseUnitCost, 4);
    }

    private function positive(float $value, string $message): float
    {
        $quantity = round($value, 3);
        if ($quantity <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $quantity;
    }
}
