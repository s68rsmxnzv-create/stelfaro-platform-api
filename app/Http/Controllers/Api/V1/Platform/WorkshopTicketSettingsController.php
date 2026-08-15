<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkshopTicketSettingsController extends Controller
{
    public function show(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);

        return response()->json(['data' => $this->settings($tenant)]);
    }

    public function update(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'receipt_copies' => ['required', 'integer', Rule::in([1, 2, 3])],
            'terms' => ['nullable', 'string', 'max:4000'],
        ]);
        $metadata = $tenant->metadata ?? [];
        data_set($metadata, 'workshop.ticket', [
            'receipt_copies' => (int) $data['receipt_copies'],
            'terms' => trim((string) ($data['terms'] ?? '')),
        ]);
        $tenant->update(['metadata' => $metadata]);

        return response()->json(['data' => $this->settings($tenant->fresh())]);
    }

    /** @return array{receipt_copies:int,terms:string} */
    private function settings(Tenant $tenant): array
    {
        return [
            'receipt_copies' => in_array((int) data_get($tenant->metadata, 'workshop.ticket.receipt_copies', 2), [1, 2, 3], true) ? (int) data_get($tenant->metadata, 'workshop.ticket.receipt_copies', 2) : 2,
            'terms' => (string) data_get($tenant->metadata, 'workshop.ticket.terms', ''),
        ];
    }
}
