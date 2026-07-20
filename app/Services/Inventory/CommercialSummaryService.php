<?php

namespace App\Services\Inventory;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CommercialSummaryService
{
    /** @return array{sales_today:float,sales_net_today:float,sales_tax_today:float,sales_month:float,sales_net_month:float,sales_tax_month:float} */
    public function totals(Tenant $tenant): array
    {
        $sales = DB::table('inventory_sales')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active');
        $today = (clone $sales)->whereDate('sale_date', today());
        $month = (clone $sales)->whereDate('sale_date', '>=', now()->startOfMonth());

        return [
            'sales_today' => $this->signedSum($today, 'total_amount'),
            'sales_net_today' => $this->signedSum($today, 'net_amount'),
            'sales_tax_today' => $this->signedSum($today, 'tax_amount'),
            'sales_month' => $this->signedSum($month, 'total_amount'),
            'sales_net_month' => $this->signedSum($month, 'net_amount'),
            'sales_tax_month' => $this->signedSum($month, 'tax_amount'),
        ];
    }

    private function signedSum(Builder $query, string $column): float
    {
        return round((float) (clone $query)->selectRaw("COALESCE(SUM({$column} * reporting_sign), 0) as aggregate")->value('aggregate'), 2);
    }
}
