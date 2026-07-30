<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\ReceivableAccount;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceivableController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'partial', 'settled', 'cancelled'])], 'q' => ['nullable', 'string', 'max:120']]);
        $query = ReceivableAccount::query()->where('tenant_id', $tenant->id)->with('entries');
        $query->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('customer_name', 'like', "%{$term}%")->orWhere('source_number', 'like', "%{$term}%")));
        $rows = $query->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (ReceivableAccount $account) => ['id' => $account->id, 'source_type' => $account->source_type, 'source_id' => $account->source_id, 'source_number' => $account->source_number, 'customer' => ['id' => $account->core_customer_id, 'name' => $account->customer_name], 'original_amount' => (float) $account->original_amount, 'paid_amount' => (float) $account->paid_amount, 'refunded_amount' => (float) $account->refunded_amount, 'balance' => (float) $account->balance, 'status' => $account->status, 'recognized_at' => $account->recognized_at?->toISOString(), 'entries' => $account->entries->map(fn ($entry) => ['id' => $entry->id, 'type' => $entry->entry_type, 'amount' => (float) $entry->amount, 'reference' => $entry->reference, 'notes' => $entry->notes, 'occurred_at' => $entry->occurred_at?->toISOString()])->values()])->values(), 'summary' => ['open' => round((float) $rows->whereIn('status', ['open', 'partial'])->sum('balance'), 2), 'accounts' => $rows->whereIn('status', ['open', 'partial'])->count()]]);
    }
}
