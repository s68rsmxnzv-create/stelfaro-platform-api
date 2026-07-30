<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\FiscalSyncPayloadConflictException;
use App\Http\Controllers\Controller;
use App\Models\FiscalSyncOperation;
use App\Models\Tenant;
use App\Services\FiscalSyncService;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FiscalSyncController extends Controller
{
    public function prepareDteIssue(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, FiscalSyncService $sync): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate($this->dteIssueRules($tenant));

        try {
            $operation = $sync->prepareDteIssue($tenant, $data, $request->user()?->id);
        } catch (FiscalSyncPayloadConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($operation)], $operation->wasRecentlyCreated ? 201 : 200);
    }

    public function prepareInvalidation(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, FiscalSyncService $sync): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'invalidation_type' => ['required', 'integer', Rule::in([1, 2, 3])],
            'original_source_id' => ['required', 'string', 'max:80'],
            'replacement_source_id' => ['nullable', 'string', 'max:80', 'different:original_source_id'],
        ]);

        try {
            $operation = $sync->prepareInvalidation($tenant, $data, $request->user()?->id);
        } catch (FiscalSyncPayloadConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->payload($operation)], $operation->wasRecentlyCreated ? 201 : 200);
    }

    public function attach(Request $request, Tenant $tenant, FiscalSyncOperation $operation, PlatformAccessPolicy $policy, FiscalSyncService $sync): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        abort_unless($operation->tenant_id === $tenant->id, 404);
        $data = $request->validate(['core_resource_id' => ['required', 'string', 'max:80']]);

        try {
            $operation = $sync->attachCoreResource($tenant, $operation, $data['core_resource_id']);
        } catch (FiscalSyncPayloadConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->payload($operation)]);
    }

    public function complete(Request $request, Tenant $tenant, FiscalSyncOperation $operation, PlatformAccessPolicy $policy, FiscalSyncService $sync): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        abort_unless($operation->tenant_id === $tenant->id, 404);
        $data = $request->validate(['fact' => ['required', 'array']]);

        try {
            $operation = $sync->finalize($tenant, $operation, $data['fact']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($operation)]);
    }

    /** @return array<string, mixed> */
    private function dteIssueRules(Tenant $tenant): array
    {
        $catalogExists = Rule::exists('catalog_items', 'id')->where('tenant_id', $tenant->id);

        return [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'workshop_order_id' => ['nullable', 'integer', Rule::exists('workshop_orders', 'id')->where('tenant_id', $tenant->id)],
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenant->id)],
            'reservation' => ['nullable', 'array'],
            'reservation.core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'reservation.core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'reservation.core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'reservation.lines' => ['nullable', 'array'],
            'reservation.lines.*.catalog_item_id' => ['required', 'integer', $catalogExists],
            'reservation.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'reservation.lines.*.description' => ['nullable', 'string', 'max:255'],
            'sale' => ['required', 'array'],
            'sale.core_sucursal_id' => ['nullable', 'integer', 'min:1'],
            'sale.core_sucursal_code' => ['nullable', 'string', 'max:30'],
            'sale.core_sucursal_name' => ['nullable', 'string', 'max:160'],
            'sale.source_type' => ['nullable', 'string', Rule::in(['dte', 'workshop_order', 'sales_order'])],
            'sale.sale_date' => ['nullable', 'date'],
            'sale.fiscal_document_type' => ['nullable', 'string', Rule::in(['01', '03', '05', '06', '14'])],
            'sale.net_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.total_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.metadata' => ['nullable', 'array'],
            'sale.replacement_of_source_type' => ['nullable', 'string', Rule::in(['dte'])],
            'sale.replacement_of_source_id' => ['nullable', 'string', 'max:80'],
            'sale.lines' => ['required', 'array', 'min:1'],
            'sale.lines.*.catalog_item_id' => ['nullable', 'integer', $catalogExists],
            'sale.lines.*.line_origin' => ['nullable', 'string', Rule::in(['free', 'catalog', 'inventory'])],
            'sale.lines.*.inherited_from_line_id' => ['nullable', 'integer'],
            'sale.lines.*.inherited_quantity' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.description' => ['required', 'string', 'max:255'],
            'sale.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'sale.lines.*.unit_price' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.net_total' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.total_amount' => ['nullable', 'numeric', 'gte:0'],
            'sale.lines.*.reference_unit_cost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(FiscalSyncOperation $operation): array
    {
        $reservationId = (int) data_get($operation->payload, 'reservation_id', 0);

        return [
            'id' => $operation->id,
            'kind' => $operation->kind,
            'idempotency_key' => $operation->idempotency_key,
            'status' => $operation->status,
            'core_resource_id' => $operation->core_resource_id,
            'reservation' => $reservationId > 0
                ? $operation->tenant->inventoryReservations()->with(['lines.catalogItem', 'lines.allocations.lot'])->find($reservationId)
                : null,
            'result' => $operation->result,
            'last_error' => $operation->last_error,
            'completed_at' => $operation->completed_at?->toISOString(),
        ];
    }
}
