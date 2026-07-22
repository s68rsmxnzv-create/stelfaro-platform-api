<?php

namespace App\Services\Cash;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\FiscalSyncOperation;
use App\Models\SalesOrderPayment;
use App\Models\Tenant;
use App\Models\WorkshopOrderPayment;
use Illuminate\Validation\ValidationException;

class CashService
{
    public function activeSession(Tenant $tenant, ?int $userId = null, ?int $registerId = null, ?int $branchId = null): ?CashSession
    {
        return CashSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->when($registerId, fn ($query) => $query->where('cash_register_id', $registerId))
            ->when($branchId && ! $registerId, fn ($query) => $query->whereHas('register', fn ($register) => $register->where('core_sucursal_id', $branchId)))
            ->when($userId && ! $registerId, fn ($query) => $query->orderByRaw('CASE WHEN opened_by = ? THEN 0 ELSE 1 END', [$userId]))
            ->latest('opened_at')
            ->first();
    }

    public function recordWorkshopPayment(Tenant $tenant, WorkshopOrderPayment $payment): CashMovement
    {
        $direction = $payment->kind === 'refund' ? 'out' : 'in';
        $order = $payment->order;
        $session = $payment->method === 'cash'
            ? $this->activeSession($tenant, $payment->received_by, null, $order?->core_sucursal_id)
            : null;

        if ($payment->method === 'cash' && ! $session) {
            throw ValidationException::withMessages([
                'method' => 'Abre una caja antes de registrar un movimiento en efectivo.',
            ]);
        }

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'workshop-payment:'.$payment->id],
            [
                'cash_register_id' => $session?->cash_register_id,
                'cash_session_id' => $session?->id,
                'workshop_order_id' => $payment->workshop_order_id,
                'direction' => $direction,
                'kind' => $direction === 'in' ? 'workshop_payment' : 'workshop_refund',
                'method' => $payment->method,
                'amount' => $payment->amount,
                'description' => $direction === 'in' ? 'Cobro de orden de taller' : 'Devolución de orden de taller',
                'reference' => $payment->reference,
                'source_type' => 'workshop_order_payment',
                'source_id' => (string) $payment->id,
                'metadata' => ['notes' => $payment->notes],
                'created_by' => $payment->received_by,
                'occurred_at' => $payment->received_at,
            ],
        );
    }

    public function recordSalesOrderPayment(Tenant $tenant, SalesOrderPayment $payment): CashMovement
    {
        $order = $payment->order;
        $session = $payment->method === 'cash'
            ? $this->activeSession($tenant, $payment->received_by, null, $order?->core_sucursal_id)
            : null;

        if ($payment->method === 'cash' && ! $session) {
            throw ValidationException::withMessages(['method' => 'Abre una caja antes de registrar un cobro en efectivo.']);
        }

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'sales-order-payment:'.$payment->id],
            ['cash_register_id' => $session?->cash_register_id, 'cash_session_id' => $session?->id, 'sales_order_id' => $payment->sales_order_id, 'direction' => 'in', 'kind' => 'sale_collection', 'method' => $payment->method, 'amount' => $payment->amount, 'description' => 'Cobro de orden de venta', 'reference' => $payment->reference, 'source_type' => 'sales_order_payment', 'source_id' => (string) $payment->id, 'metadata' => ['notes' => $payment->notes], 'created_by' => $payment->received_by, 'occurred_at' => $payment->received_at],
        );
    }

    public function ensureCashSession(Tenant $tenant, ?int $userId, ?int $branchId = null): CashSession
    {
        $session = $this->activeSession($tenant, $userId, null, $branchId);
        if (! $session) {
            throw ValidationException::withMessages(['cash' => 'Abre una caja antes de emitir una venta cobrada en efectivo.']);
        }

        return $session;
    }

    public function recordDteCashPayment(Tenant $tenant, FiscalSyncOperation $operation, string $documentId, string $number): ?CashMovement
    {
        if (filled($operation->payload['workshop_order_id'] ?? null) || filled($operation->payload['sales_order_id'] ?? null)) {
            return null;
        }
        $amount = round((float) data_get($operation->payload, 'sale.metadata.cash_amount', 0), 2);
        if ($amount <= 0) {
            return null;
        }
        $branchId = data_get($operation->payload, 'sale.core_sucursal_id');
        $session = $this->ensureCashSession($tenant, $operation->created_by, $branchId ? (int) $branchId : null);

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'dte-cash:'.$operation->id],
            ['cash_register_id' => $session->cash_register_id, 'cash_session_id' => $session->id, 'direction' => 'in', 'kind' => 'sale_collection', 'method' => 'cash', 'amount' => $amount, 'description' => 'Cobro de DTE '.$number, 'reference' => $number, 'source_type' => 'dte', 'source_id' => $documentId, 'metadata' => ['fiscal_sync_operation_id' => $operation->id], 'created_by' => $operation->created_by, 'occurred_at' => now()],
        );
    }

    /** @return array{inflows:float,outflows:float,expected:float} */
    public function sessionTotals(CashSession $session): array
    {
        $base = CashMovement::query()
            ->where('cash_session_id', $session->id)
            ->where('method', 'cash')
            ->whereNull('reversed_at');
        $inflows = (float) (clone $base)->where('direction', 'in')->sum('amount');
        $outflows = (float) (clone $base)->where('direction', 'out')->sum('amount');

        return [
            'inflows' => round($inflows, 2),
            'outflows' => round($outflows, 2),
            'expected' => round((float) $session->opening_balance + $inflows - $outflows, 2),
        ];
    }

    public function defaultRegister(Tenant $tenant, array $branch = []): CashRegister
    {
        $branchId = isset($branch['core_sucursal_id']) ? (int) $branch['core_sucursal_id'] : null;

        return CashRegister::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'core_sucursal_id' => $branchId,
            'name' => trim((string) ($branch['name'] ?? 'Caja principal')),
        ], [
            'core_sucursal_code' => $branch['core_sucursal_code'] ?? null,
            'core_sucursal_name' => $branch['core_sucursal_name'] ?? null,
            'status' => 'active',
        ]);
    }
}
