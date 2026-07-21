<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CashExpense;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\InventoryPurchase;
use App\Models\Tenant;
use App\Services\Cash\CashService;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashRegisterController extends Controller
{
    public function overview(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $query = CashMovement::query()->where('tenant_id', $tenant->id)->with(['expense.supplier', 'order.device.customer']);
        $query->when($data['cash_register_id'] ?? null, fn ($q, $registerId) => $q->where('cash_register_id', $registerId));
        $query->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date));
        $query->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date));
        $query->when($data['method'] ?? null, fn ($q, $method) => $q->where('method', $method));
        $query->when($data['direction'] ?? null, fn ($q, $direction) => $q->where('direction', $direction));
        $summaryQuery = clone $query;
        $movements = $query->latest('occurred_at')->paginate((int) ($data['per_page'] ?? 20));
        $selectedRegisterId = isset($data['cash_register_id']) ? (int) $data['cash_register_id'] : null;
        $session = $cash->activeSession($tenant, $request->user()?->id, $selectedRegisterId);

        return response()->json([
            'registers' => $tenant->cashRegisters()->where('status', 'active')->with('setting')->orderBy('core_sucursal_name')->orderBy('name')->get()->map(fn (CashRegister $register) => [
                'id' => $register->id, 'name' => $register->name, 'status' => $register->status,
                'branch_id' => $register->core_sucursal_id, 'branch_code' => $register->core_sucursal_code, 'branch_name' => $register->core_sucursal_name,
                'configured' => $register->setting !== null,
            ])->values(),
            'active_session' => $session ? $this->sessionPayload($session->load('register'), $cash) : null,
            'pending_counts' => CashSession::query()->where('tenant_id', $tenant->id)->where('status', 'closed_unverified')
                ->when($selectedRegisterId, fn ($query) => $query->where('cash_register_id', $selectedRegisterId))
                ->with('register')->oldest('business_date')->get()->map(fn (CashSession $pending) => $this->sessionPayload($pending, $cash))->values(),
            'summary' => [
                'inflows' => round((float) (clone $summaryQuery)->whereNull('reversed_at')->where('direction', 'in')->sum('amount'), 2),
                'outflows' => round((float) (clone $summaryQuery)->whereNull('reversed_at')->where('direction', 'out')->sum('amount'), 2),
                'pending_documents' => CashExpense::query()->where('tenant_id', $tenant->id)->where('status', 'pending_document')->count(),
            ],
            'data' => collect($movements->items())->map(fn (CashMovement $movement) => $this->movementPayload($movement))->values(),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    public function open(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'name' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000'],
            'core_sucursal_id' => ['nullable', 'integer', 'min:1'], 'core_sucursal_code' => ['nullable', 'string', 'max:30'], 'core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $session = DB::transaction(function () use ($tenant, $request, $cash, $data): CashSession {
            $register = isset($data['cash_register_id'])
                ? CashRegister::query()->where('tenant_id', $tenant->id)->findOrFail($data['cash_register_id'])
                : $cash->defaultRegister($tenant, $data);
            $locked = $register->sessions()->where('status', 'open')->lockForUpdate()->first();
            if ($locked) {
                throw ValidationException::withMessages(['cash_register' => 'Esta caja ya tiene una sesión abierta.']);
            }

            $timezone = $register->setting?->timezone ?? 'America/El_Salvador';
            $businessDate = now($timezone)->toDateString();
            if ($register->sessions()->whereDate('business_date', $businessDate)->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'Esta caja ya tiene una sesión para hoy.']);
            }

            return CashSession::query()->create([
                'tenant_id' => $tenant->id, 'cash_register_id' => $register->id,
                'opened_by' => $request->user()->id, 'opening_balance' => $data['opening_balance'],
                'business_date' => $businessDate, 'opening_source' => 'manual', 'count_status' => 'pending',
                'status' => 'open', 'opening_notes' => $data['notes'] ?? null, 'opened_at' => now(),
            ])->load('register');
        });
        $audit->record($request, 'cash.session.opened', ['cash_session_id' => $session->id]);

        return response()->json(['data' => $this->sessionPayload($session, $cash)], 201);
    }

    public function close(Request $request, Tenant $tenant, CashSession $cashSession, PlatformAccessPolicy $policy, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($cashSession->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate(['declared_balance' => ['required', 'numeric', 'min:0', 'max:999999999999.99'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $session = DB::transaction(function () use ($cashSession, $cash, $data, $request): CashSession {
            $locked = CashSession::query()->whereKey($cashSession->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['open', 'closed_unverified'], true)) {
                throw ValidationException::withMessages(['cash_session' => 'Esta caja ya fue cerrada.']);
            }
            $expected = $locked->expected_balance !== null && $locked->status === 'closed_unverified'
                ? (float) $locked->expected_balance
                : $cash->sessionTotals($locked)['expected'];
            $locked->forceFill(['status' => 'closed', 'count_status' => 'counted', 'expected_balance' => $expected, 'declared_balance' => $data['declared_balance'], 'difference' => round((float) $data['declared_balance'] - $expected, 2), 'closing_notes' => $data['notes'] ?? $locked->closing_notes, 'closed_by' => $request->user()->id, 'closed_at' => $locked->closed_at ?? now()])->save();

            return $locked->load('register');
        });
        $audit->record($request, 'cash.session.closed', ['cash_session_id' => $session->id, 'difference' => $session->difference]);

        return response()->json(['data' => $this->sessionPayload($session, $cash)]);
    }

    public function storeMovement(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, CashService $cash, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'kind' => ['required', Rule::in(['manual_income', 'expense', 'supplier_purchase', 'withdrawal', 'deposit'])],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'description' => ['required', 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:160'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'supplier_id' => ['nullable', Rule::exists('inventory_suppliers', 'id')->where('tenant_id', $tenant->id)],
            'workshop_order_id' => ['nullable', Rule::exists('workshop_orders', 'id')->where('tenant_id', $tenant->id)],
            'expense_category' => ['nullable', Rule::in(['replacement', 'tool', 'service', 'transport', 'general'])],
            'destination' => ['nullable', Rule::in(['direct_order', 'inventory', 'expense'])],
            'cash_register_id' => ['nullable', Rule::exists('cash_registers', 'id')->where('tenant_id', $tenant->id)],
        ]);
        if (in_array($data['kind'], ['expense', 'supplier_purchase', 'withdrawal'], true) && $data['direction'] !== 'out') {
            throw ValidationException::withMessages(['direction' => 'Este tipo de movimiento debe ser una salida.']);
        }
        $movement = DB::transaction(function () use ($tenant, $request, $cash, $data): CashMovement {
            $registerId = isset($data['cash_register_id']) ? (int) $data['cash_register_id'] : null;
            $session = $data['method'] === 'cash' ? $cash->activeSession($tenant, $request->user()->id, $registerId) : null;
            if ($data['method'] === 'cash' && ! $session) {
                throw ValidationException::withMessages(['method' => 'Abre una caja antes de registrar efectivo.']);
            }
            if ($data['method'] !== 'cash' && $registerId) {
                $register = CashRegister::query()->where('tenant_id', $tenant->id)->with('setting')->findOrFail($registerId);
                if ($register->setting && ! $register->setting->allow_non_cash_when_closed && ! $cash->activeSession($tenant, $request->user()->id, $registerId)) {
                    throw ValidationException::withMessages(['method' => 'Esta caja debe estar abierta para registrar cualquier forma de pago.']);
                }
            }
            $expense = null;
            if ($data['direction'] === 'out' && in_array($data['kind'], ['expense', 'supplier_purchase'], true)) {
                $expense = CashExpense::query()->create([
                    'tenant_id' => $tenant->id, 'inventory_supplier_id' => $data['supplier_id'] ?? null,
                    'workshop_order_id' => $data['workshop_order_id'] ?? null, 'category' => $data['expense_category'] ?? 'general',
                    'destination' => $data['destination'] ?? 'expense', 'amount' => $data['amount'],
                    'status' => 'pending_document', 'description' => trim($data['description']), 'created_by' => $request->user()->id,
                ]);
            }

            return CashMovement::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid()],
                ['cash_register_id' => $session?->cash_register_id, 'cash_session_id' => $session?->id, 'cash_expense_id' => $expense?->id, 'workshop_order_id' => $data['workshop_order_id'] ?? null, 'direction' => $data['direction'], 'kind' => $data['kind'], 'method' => $data['method'], 'amount' => $data['amount'], 'description' => trim($data['description']), 'reference' => $data['reference'] ?? null, 'source_type' => $expense ? 'cash_expense' : 'manual', 'source_id' => $expense ? (string) $expense->id : null, 'created_by' => $request->user()->id, 'occurred_at' => now()],
            )->load(['expense.supplier', 'order.device.customer']);
        });
        $audit->record($request, 'cash.movement.created', ['cash_movement_id' => $movement->id, 'direction' => $movement->direction, 'amount' => $movement->amount]);

        return response()->json(['data' => $this->movementPayload($movement)], 201);
    }

    public function reverse(Request $request, Tenant $tenant, CashMovement $cashMovement, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($cashMovement->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $reversal = DB::transaction(function () use ($cashMovement, $request, $tenant, $data): CashMovement {
            $locked = CashMovement::query()->whereKey($cashMovement->id)->lockForUpdate()->firstOrFail();
            if ($locked->reversed_at) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento ya fue revertido.']);
            }
            $locked->forceFill(['reversed_at' => now(), 'reversed_by' => $request->user()->id])->save();
            if ($locked->cash_expense_id) {
                CashExpense::query()->whereKey($locked->cash_expense_id)->update(['status' => 'reversed']);
            }

            return CashMovement::query()->create(['tenant_id' => $tenant->id, 'cash_register_id' => $locked->cash_register_id, 'cash_session_id' => $locked->cash_session_id, 'workshop_order_id' => $locked->workshop_order_id, 'direction' => $locked->direction === 'in' ? 'out' : 'in', 'kind' => 'reversal', 'method' => $locked->method, 'amount' => $locked->amount, 'description' => 'Reversión: '.$data['reason'], 'source_type' => 'cash_movement', 'source_id' => (string) $locked->id, 'idempotency_key' => 'reversal:'.$locked->id, 'reversal_of_id' => $locked->id, 'created_by' => $request->user()->id, 'occurred_at' => now()]);
        });
        $audit->record($request, 'cash.movement.reversed', ['cash_movement_id' => $cashMovement->id, 'reversal_id' => $reversal->id]);

        return response()->json(['data' => $this->movementPayload($reversal)]);
    }

    public function reconcileExpense(Request $request, Tenant $tenant, CashExpense $cashExpense, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($cashExpense->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        if ($cashExpense->status === 'reversed') {
            throw ValidationException::withMessages(['cash_expense' => 'Un gasto revertido no puede conciliarse.']);
        }
        $data = $request->validate(['inventory_purchase_id' => ['required', Rule::exists('inventory_purchases', 'id')->where('tenant_id', $tenant->id)]]);
        $purchase = InventoryPurchase::query()->where('tenant_id', $tenant->id)->findOrFail($data['inventory_purchase_id']);
        if ($cashExpense->inventory_purchase_id && $cashExpense->inventory_purchase_id !== $purchase->id) {
            throw ValidationException::withMessages(['inventory_purchase_id' => 'Este gasto ya fue conciliado con otra compra.']);
        }
        $difference = round((float) $purchase->total - (float) $cashExpense->amount, 2);
        $metadata = $cashExpense->metadata ?? [];
        $metadata['reconciliation'] = ['purchase_total' => (float) $purchase->total, 'difference' => $difference, 'reconciled_at' => now()->toISOString()];
        $cashExpense->forceFill(['inventory_purchase_id' => $purchase->id, 'status' => abs($difference) < 0.01 ? 'reconciled' : 'review_difference', 'metadata' => $metadata])->save();
        $audit->record($request, 'cash.expense.reconciled', ['cash_expense_id' => $cashExpense->id, 'inventory_purchase_id' => $purchase->id, 'difference' => $difference]);

        return response()->json(['data' => ['id' => $cashExpense->id, 'status' => $cashExpense->status, 'inventory_purchase_id' => $purchase->id, 'difference' => $difference]]);
    }

    private function sessionPayload(CashSession $session, CashService $cash): array
    {
        return ['id' => $session->id, 'status' => $session->status, 'business_date' => $session->business_date?->toDateString(), 'opening_source' => $session->opening_source, 'count_status' => $session->count_status, 'opening_balance' => (float) $session->opening_balance, 'opened_at' => $session->opened_at?->toISOString(), 'closed_at' => $session->closed_at?->toISOString(), 'declared_balance' => $session->declared_balance !== null ? (float) $session->declared_balance : null, 'difference' => $session->difference !== null ? (float) $session->difference : null, 'register' => ['id' => $session->register->id, 'name' => $session->register->name, 'branch_id' => $session->register->core_sucursal_id, 'branch_name' => $session->register->core_sucursal_name], ...$cash->sessionTotals($session)];
    }

    private function movementPayload(CashMovement $movement): array
    {
        return ['id' => $movement->id, 'direction' => $movement->direction, 'kind' => $movement->kind, 'method' => $movement->method, 'amount' => (float) $movement->amount, 'description' => $movement->description, 'reference' => $movement->reference, 'occurred_at' => $movement->occurred_at?->toISOString(), 'reversed_at' => $movement->reversed_at?->toISOString(), 'expense' => $movement->expense ? ['id' => $movement->expense->id, 'status' => $movement->expense->status, 'category' => $movement->expense->category, 'supplier' => $movement->expense->supplier?->name] : null, 'order' => $movement->order ? ['id' => $movement->order->id, 'ticket' => 'T-'.str_pad((string) $movement->order->ticket_number, 6, '0', STR_PAD_LEFT)] : null];
    }
}
