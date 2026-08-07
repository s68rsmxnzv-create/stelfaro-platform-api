<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\InventorySale;
use App\Models\ReceivableAccount;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReceivableController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'partial', 'settled', 'cancelled'])], 'aging' => ['nullable', Rule::in(['current', 'overdue', '30', '60', '90'])], 'q' => ['nullable', 'string', 'max:120']]);
        $query = ReceivableAccount::query()->where('tenant_id', $tenant->id)->with('entries');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        } else {
            $query->whereIn('status', ['open', 'partial']);
        }
        $query->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('customer_name', 'like', "%{$term}%")->orWhere('source_number', 'like', "%{$term}%")));
        $this->applyAging($query, $data['aging'] ?? null);
        $rows = $query->latest()->limit(200)->get();
        $accounts = $rows->map(fn (ReceivableAccount $account) => [
            'id' => $account->id,
            'collection_id' => $account->source_id,
            'source_type' => $account->source_type,
            'source_id' => $account->source_id,
            'source_number' => $account->source_number,
            'customer' => ['id' => $account->core_customer_id, 'name' => $account->customer_name],
            'original_amount' => (float) $account->original_amount,
            'paid_amount' => (float) $account->paid_amount,
            'refunded_amount' => (float) $account->refunded_amount,
            'balance' => (float) $account->balance,
            'status' => $account->status,
            'recognized_at' => $account->recognized_at?->toISOString(),
            'due_at' => $account->due_at?->toISOString(),
            'days_overdue' => $account->due_at && $account->due_at->isPast() ? $account->due_at->startOfDay()->diffInDays(today()) : 0,
            'entries' => $account->entries->map(fn ($entry) => ['id' => $entry->id, 'type' => $entry->entry_type, 'amount' => (float) $entry->amount, 'reference' => $entry->reference, 'notes' => $entry->notes, 'occurred_at' => $entry->occurred_at?->toISOString()])->values(),
        ]);

        $dteAccounts = collect();
        if (! isset($data['status']) || in_array($data['status'], ['open', 'partial'], true)) {
            $term = trim((string) ($data['q'] ?? ''));
            $dteAccounts = InventorySale::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', 'dte')
                ->where('status', 'active')
                ->latest('sale_date')
                ->get(['id', 'source_id', 'source_number', 'sale_date', 'total_amount', 'reporting_sign', 'metadata'])
                ->filter(fn (InventorySale $sale): bool => data_get($sale->metadata, 'payment_status') === 'receivable')
                ->map(function (InventorySale $sale): array {
                    $original = round((float) $sale->total_amount * (int) $sale->reporting_sign, 2);
                    $balance = max(0, round((float) data_get($sale->metadata, 'outstanding_amount', $original), 2));
                    $dueAt = data_get($sale->metadata, 'due_at');
                    $dueDate = $dueAt ? Carbon::parse($dueAt) : null;

                    return [
                        'id' => 'dte-'.$sale->id,
                        'collection_id' => $sale->id,
                        'source_type' => 'dte',
                        'source_id' => (int) $sale->source_id,
                        'source_number' => $sale->source_number,
                        'customer' => ['id' => data_get($sale->metadata, 'customer_id'), 'name' => data_get($sale->metadata, 'customer_name', 'Consumidor Final')],
                        'original_amount' => $original,
                        'paid_amount' => max(0, round($original - $balance, 2)),
                        'refunded_amount' => 0.0,
                        'balance' => $balance,
                        'status' => $balance < $original ? 'partial' : 'open',
                        'recognized_at' => $sale->sale_date?->startOfDay()->toISOString(),
                        'due_at' => $dueDate?->toISOString(),
                        'days_overdue' => $dueDate && $dueDate->isPast() ? $dueDate->startOfDay()->diffInDays(today()) : 0,
                        'entries' => [],
                    ];
                })
                ->filter(fn (array $account): bool => $account['balance'] > 0)
                ->filter(fn (array $account): bool => $term === '' || str_contains(mb_strtolower($account['source_number'].' '.$account['customer']['name']), mb_strtolower($term)))
                ->filter(fn (array $account): bool => ! isset($data['status']) || $account['status'] === $data['status'])
                ->values();
        }

        $allAccounts = $accounts->concat($dteAccounts);
        if ($aging = ($data['aging'] ?? null)) {
            $allAccounts = $allAccounts->filter(function (array $account) use ($aging): bool {
                $days = (int) $account['days_overdue'];

                return match ($aging) {
                    'current' => $days === 0,
                    'overdue' => $days > 0,
                    '30' => $days >= 30 && $days < 60,
                    '60' => $days >= 60 && $days < 90,
                    '90' => $days >= 90,
                    default => true,
                };
            });
        }
        $allAccounts = $allAccounts->sortByDesc('recognized_at')->values();

        return response()->json(['data' => $allAccounts, 'summary' => ['open' => round((float) $allAccounts->whereIn('status', ['open', 'partial'])->sum('balance'), 2), 'accounts' => $allAccounts->whereIn('status', ['open', 'partial'])->count(), 'overdue' => round((float) $allAccounts->where('days_overdue', '>', 0)->sum('balance'), 2)]]);
    }

    private function applyAging($query, ?string $aging): void
    {
        if ($aging === 'current') {
            $query->where(fn ($q) => $q->whereNull('due_at')->orWhereDate('due_at', '>=', today()));
        } elseif ($aging === 'overdue') {
            $query->whereDate('due_at', '<', today());
        } elseif (in_array($aging, ['30', '60', '90'], true)) {
            $days = (int) $aging;
            $query->whereDate('due_at', '<=', today()->subDays($days));
            if ($days < 90) {
                $query->whereDate('due_at', '>', today()->subDays($days + 30));
            }
        }
    }
}
