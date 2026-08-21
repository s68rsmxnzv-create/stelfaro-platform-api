<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AccountantContact;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantAccountantContactController extends Controller
{
    private const MAX_CONTACTS_PER_TENANT = 5;
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantUsers($request->user(), $tenant), 403);

        return response()->json([
            'contacts' => $tenant->accountantContacts()
                ->orderBy('name')
                ->get()
                ->map(fn (AccountantContact $contact): array => $this->payload($contact))
                ->values(),
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);

        if ($tenant->accountantContacts()->count() >= self::MAX_CONTACTS_PER_TENANT) {
            throw ValidationException::withMessages([
                'name' => ["Ya tienes el máximo de ".self::MAX_CONTACTS_PER_TENANT." contactos frecuentes. Elimina uno para agregar otro."],
            ]);
        }

        $validated = $request->validate([
            'core_empresa_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $contact = $tenant->accountantContacts()->create([
            ...$validated,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['contact' => $this->payload($contact)], 201);
    }

    public function update(Request $request, Tenant $tenant, AccountantContact $contact, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);
        abort_if($contact->tenant_id !== $tenant->id, 404);

        $validated = $request->validate([
            'core_empresa_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $contact->forceFill($validated)->save();

        return response()->json(['contact' => $this->payload($contact->refresh())]);
    }

    public function destroy(Request $request, Tenant $tenant, AccountantContact $contact, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canManageTenantCatalog($request->user(), $tenant), 403);
        abort_if($contact->tenant_id !== $tenant->id, 404);

        $contact->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AccountantContact $contact): array
    {
        return [
            'id' => $contact->id,
            'tenant_id' => $contact->tenant_id,
            'core_empresa_id' => $contact->core_empresa_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'notes' => $contact->notes,
            'created_at' => optional($contact->created_at)->toISOString(),
        ];
    }
}
