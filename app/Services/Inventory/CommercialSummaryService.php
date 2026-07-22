<?php

namespace App\Services\Inventory;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CommercialSummaryService
{
    private const PURCHASE_DOCUMENTS_WITH_FISCAL_CREDIT = ['ccf', 'dte_ccf', 'fcf', 'dte_fcf'];

    /**
     * @return array{
     *   sales_today:float,
     *   sales_net_today:float,
     *   sales_tax_today:float,
     *   sales_month:float,
     *   sales_net_month:float,
     *   sales_tax_month:float,
     *   purchase_tax_credit_month:float,
     *   estimated_tax_payable_month:float,
     *   estimated_tax_credit_balance_month:float
     * }
     */
    public function totals(Tenant $tenant): array
    {
        $sales = DB::table('inventory_sales')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active');
        $today = (clone $sales)->whereDate('sale_date', today());
        $month = (clone $sales)->whereDate('sale_date', '>=', now()->startOfMonth());
        $salesTaxMonth = $this->signedSum($month, 'tax_amount');
        $purchaseTaxCreditMonth = round((float) DB::table('inventory_purchases')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'registered')
            ->where('fiscal_annex_eligible', true)
            ->whereIn('document_type', self::PURCHASE_DOCUMENTS_WITH_FISCAL_CREDIT)
            ->whereDate('purchase_date', '>=', now()->startOfMonth())
            ->sum('tax_amount'), 2);
        $estimatedTaxBalance = round($salesTaxMonth - $purchaseTaxCreditMonth, 2);

        return [
            'sales_today' => $this->signedSum($today, 'total_amount'),
            'sales_net_today' => $this->signedSum($today, 'net_amount'),
            'sales_tax_today' => $this->signedSum($today, 'tax_amount'),
            'sales_month' => $this->signedSum($month, 'total_amount'),
            'sales_net_month' => $this->signedSum($month, 'net_amount'),
            'sales_tax_month' => $salesTaxMonth,
            'purchase_tax_credit_month' => $purchaseTaxCreditMonth,
            'estimated_tax_payable_month' => max(0, $estimatedTaxBalance),
            'estimated_tax_credit_balance_month' => abs(min(0, $estimatedTaxBalance)),
        ];
    }

    private function signedSum(Builder $query, string $column): float
    {
        return round((float) (clone $query)->selectRaw("COALESCE(SUM({$column} * reporting_sign), 0) as aggregate")->value('aggregate'), 2);
    }
}
