<?php

namespace App\Services\Legal;

use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\Platform\TenantEnvironmentResolver;
use Illuminate\Support\Collection;

class LegalAcceptanceRequirement
{
    public function __construct(
        private readonly LegalDocumentRegistry $documents,
        private readonly TenantEnvironmentResolver $environments,
    ) {}

    /**
     * @return array{membership: UserTenantMembership, tenant: Tenant, documents: Collection<int, LegalDocument>}|null
     */
    public function pending(User $user, ?Tenant $tenant = null): ?array
    {
        $membership = $this->membership($user, $tenant);

        if (! $membership || ! $this->environments->isProduction($membership->tenant)) {
            return null;
        }

        $documents = $this->documents->current();
        $acceptedDocumentIds = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $membership->tenant_id)
            ->whereIn('legal_document_id', $documents->pluck('id'))
            ->pluck('legal_document_id');

        $pending = $documents->reject(fn ($document): bool => $acceptedDocumentIds->contains($document->id))->values();

        if ($pending->isEmpty()) {
            return null;
        }

        return [
            'membership' => $membership,
            'tenant' => $membership->tenant,
            'documents' => $documents,
        ];
    }

    private function membership(User $user, ?Tenant $tenant): ?UserTenantMembership
    {
        $query = $user->memberships()
            ->with('tenant')
            ->where('status', 'active');

        if ($tenant) {
            return $query->where('tenant_id', $tenant->id)->first();
        }

        return $query
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
