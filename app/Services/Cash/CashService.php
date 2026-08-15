<?php

namespace App\Services\Cash;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\FiscalSyncOperation;
use App\Models\InventorySale;
use App\Models\SalesOrderPayment;
use App\Models\Tenant;
use App\Models\WorkshopOrderPayment;
use App\Services\CoreFiscalScopeClient;
use App\Services\TenantFiscalLinkResolver;
use Illuminate\Validation\ValidationException;
use Throwable;

class CashService
{
    public function __construct(
        private readonly TenantFiscalLinkResolver $fiscalLinkResolver,
        private readonly CoreFiscalScopeClient $fiscalScopeClient,
    ) {
    }

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
        [$register, $session] = $this->paymentRegisterAndSession($tenant, $payment->received_by, $order?->core_sucursal_id);

        if ($payment->method === 'cash' && ! $session) {
            throw ValidationException::withMessages([
                'method' => 'Abre una caja antes de registrar un movimiento en efectivo.',
            ]);
        }

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'workshop-payment:'.$payment->id],
            [
                'cash_register_id' => $register?->id,
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
        [$register, $session] = $this->paymentRegisterAndSession($tenant, $payment->received_by, $order?->core_sucursal_id);
        if ($payment->method === 'cash' && ! $session) {
            throw ValidationException::withMessages([
                'method' => $payment->kind === 'refund'
                    ? 'Abre una caja antes de devolver efectivo.'
                    : 'Abre una caja antes de recibir efectivo.',
            ]);
        }

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'sales-order-payment:'.$payment->id],
            [
                'cash_register_id' => $register?->id,
                'cash_session_id' => $session?->id,
                'sales_order_id' => $order?->id,
                'direction' => $payment->kind === 'refund' ? 'out' : 'in',
                'kind' => $payment->kind === 'refund' ? 'customer_refund' : 'order_payment',
                'method' => $payment->method,
                'amount' => $payment->amount,
                'description' => ($payment->kind === 'refund' ? 'Devolución de ' : 'Cobro de ').'OT-'.str_pad((string) $order?->order_number, 6, '0', STR_PAD_LEFT),
                'reference' => $payment->reference,
                'source_type' => 'sales_order_payment',
                'source_id' => (string) $payment->id,
                'metadata' => ['notes' => $payment->notes],
                'created_by' => $payment->received_by,
                'occurred_at' => $payment->received_at,
            ],
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

    public function recordDtePayments(Tenant $tenant, FiscalSyncOperation $operation, string $documentId, string $number): void
    {
        if (filled($operation->payload['workshop_order_id'] ?? null) || filled($operation->payload['sales_order_id'] ?? null)) {
            return;
        }

        $payments = collect(data_get($operation->payload, 'sale.metadata.payment_methods', []))
            ->map(fn ($payment) => [
                'method' => in_array($payment['method'] ?? null, ['cash', 'card', 'transfer', 'other'], true) ? $payment['method'] : 'other',
                'amount' => round((float) ($payment['amount'] ?? 0), 2),
                'reference' => filled($payment['reference'] ?? null) ? (string) $payment['reference'] : null,
            ])
            ->filter(fn ($payment) => $payment['amount'] > 0)
            ->groupBy('method')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'amount' => round((float) $group->sum('amount'), 2),
                'reference' => $group->pluck('reference')->filter()->first(),
            ])
            ->values();
        if ($payments->isEmpty()) {
            $cashAmount = round((float) data_get($operation->payload, 'sale.metadata.cash_amount', 0), 2);
            if ($cashAmount > 0) {
                $payments->push(['method' => 'cash', 'amount' => $cashAmount, 'reference' => null]);
            }
        }

        $branchId = data_get($operation->payload, 'sale.core_sucursal_id');
        [$register, $session] = $this->paymentRegisterAndSession($tenant, $operation->created_by, $branchId ? (int) $branchId : null);

        foreach ($payments as $payment) {
            if ($payment['method'] === 'cash' && ! $session) {
                throw ValidationException::withMessages(['cash' => 'Abre una caja antes de emitir una venta cobrada en efectivo.']);
            }
            $key = $payment['method'] === 'cash'
                ? 'dte-cash:'.$operation->id
                : 'dte-payment:'.$operation->id.':'.$payment['method'];
            CashMovement::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'idempotency_key' => $key],
                ['cash_register_id' => $register?->id, 'cash_session_id' => $session?->id, 'direction' => 'in', 'kind' => 'sale_collection', 'method' => $payment['method'], 'amount' => $payment['amount'], 'description' => 'Cobro de DTE '.$number, 'reference' => $payment['reference'] ?? $number, 'source_type' => 'dte', 'source_id' => $documentId, 'metadata' => ['fiscal_sync_operation_id' => $operation->id], 'created_by' => $operation->created_by, 'occurred_at' => now()],
            );
        }
    }

    public function recordCommercialSalePayment(Tenant $tenant, InventorySale $sale, int $userId, array $data): CashMovement
    {
        [$register, $session] = $this->paymentRegisterAndSession($tenant, $userId, $sale->core_sucursal_id);
        if ($data['method'] === 'cash' && ! $session) {
            throw ValidationException::withMessages(['method' => 'Abre una caja antes de recibir este pago en efectivo.']);
        }

        return CashMovement::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => 'commercial-sale-payment:'.$sale->id.':'.$data['idempotency_key']],
            [
                'cash_register_id' => $register?->id,
                'cash_session_id' => $session?->id,
                'direction' => 'in',
                'kind' => 'sale_collection',
                'method' => $data['method'],
                'amount' => $data['amount'],
                'description' => 'Cobro de '.$sale->source_number,
                'reference' => $data['reference'] ?? $sale->source_number,
                'source_type' => $sale->source_type,
                'source_id' => $sale->source_id,
                'metadata' => ['inventory_sale_id' => $sale->id, 'notes' => $data['notes'] ?? null],
                'created_by' => $userId,
                'occurred_at' => now(),
            ],
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
        if (! isset($branch['core_sucursal_id'])) {
            $branch = array_merge($branch, $this->resolveMainBranch($tenant));
        }

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

    /**
     * Resuelve la sucursal "casa matriz" (código M001, o la primera activa) del tenant
     * consultando dte-core, para que una caja abierta sin sucursal explícita quede
     * asociada a ella en vez de quedar con core_sucursal_id = NULL.
     *
     * @return array{core_sucursal_id?: int, core_sucursal_code?: string, core_sucursal_name?: string}
     */
    private function resolveMainBranch(Tenant $tenant): array
    {
        try {
            $empresaId = $this->fiscalLinkResolver->coreEmpresaId($tenant);
            $sucursales = $this->fiscalScopeClient->companyScope($empresaId)['sucursales'] ?? [];

            if ($sucursales === []) {
                return [];
            }

            $matriz = collect($sucursales)->first(fn (array $sucursal) => str_starts_with((string) ($sucursal['codigo'] ?? ''), 'M'))
                ?? $sucursales[0];

            return [
                'core_sucursal_id' => (int) $matriz['id'],
                'core_sucursal_code' => $matriz['codigo'] ?? null,
                'core_sucursal_name' => $matriz['nombre'] ?? null,
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{0:CashRegister|null,1:CashSession|null} */
    private function paymentRegisterAndSession(Tenant $tenant, ?int $userId, ?int $branchId): array
    {
        $session = $this->activeSession($tenant, $userId, null, $branchId);
        $register = $session?->register;
        if (! $register && $branchId) {
            $register = CashRegister::query()
                ->where('tenant_id', $tenant->id)
                ->where('core_sucursal_id', $branchId)
                ->where('status', 'active')
                ->first();
        }

        return [$register, $session];
    }
}
