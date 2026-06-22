<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformAccessPolicy;
use App\Services\UserTenantMembershipManager;
use App\Support\Platform\PlatformRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectTenantUserService
{
    public function __construct(
        private readonly UserTenantMembershipManager $memberships,
        private readonly PlatformAccessPolicy $accessPolicy,
    ) {}

    /**
     * @return array{user: User, temporary_password: string|null, created: bool}
     */
    public function create(Tenant $tenant, string $name, string $email, string $role, User $creator): array
    {
        $email = strtolower(trim($email));
        $this->ensureAssignableRole($role, $creator);

        return DB::transaction(function () use ($tenant, $name, $email, $role, $creator): array {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $created = false;
            $temporaryPassword = null;

            if (! $user) {
                $temporaryPassword = $this->temporaryPassword();
                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => $temporaryPassword,
                    'must_change_password' => true,
                ]);
                $created = true;
            }

            $membership = $user->memberships()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            if ($membership && $membership->status === 'active') {
                throw ValidationException::withMessages([
                    'email' => ['El usuario ya tiene acceso activo a esta empresa.'],
                ]);
            }

            if ($membership) {
                $membership->forceFill([
                    'role' => $role,
                    'status' => 'active',
                    'metadata' => [
                        ...($membership->metadata ?? []),
                        'reactivated_by' => $creator->id,
                        'reactivated_directly_at' => now()->toISOString(),
                    ],
                ])->save();
            } else {
                if ($role === PlatformRoles::OWNER) {
                    $this->replaceExistingTenantOwners($tenant, $user, $creator);
                }

                $this->memberships->create($user, $tenant, $role, [
                    'created_by' => $creator->id,
                    'created_directly_at' => now()->toISOString(),
                ]);
            }

            return [
                'user' => $user->refresh(),
                'temporary_password' => $temporaryPassword,
                'created' => $created,
            ];
        });
    }

    private function ensureAssignableRole(string $role, User $creator): void
    {
        if ($role === PlatformRoles::OWNER) {
            if ($this->accessPolicy->hasPlatformOwnerRole($creator)) {
                return;
            }

            throw ValidationException::withMessages([
                'role' => ['Solo platform_owner puede asignar el rol owner.'],
            ]);
        }

        if (! in_array($role, [PlatformRoles::COMPANY_ADMIN, PlatformRoles::BILLING_ADMIN, PlatformRoles::BILLING_USER, PlatformRoles::VIEWER], true)) {
            throw ValidationException::withMessages([
                'role' => ['El rol no puede ser asignado.'],
            ]);
        }
    }

    private function replaceExistingTenantOwners(Tenant $tenant, User $newOwner, User $creator): void
    {
        $tenant->memberships()
            ->where('role', PlatformRoles::OWNER)
            ->where('status', 'active')
            ->where('user_id', '!=', $newOwner->id)
            ->get()
            ->each(function ($membership) use ($creator, $newOwner): void {
                $membership->forceFill([
                    'status' => 'removed',
                    'is_default' => false,
                    'metadata' => [
                        ...($membership->metadata ?? []),
                        'replaced_by' => $newOwner->id,
                        'replaced_by_platform_owner' => $creator->id,
                        'replaced_directly_at' => now()->toISOString(),
                    ],
                ])->save();
            });
    }

    private function temporaryPassword(): string
    {
        return 'Sf-'.Str::password(12, letters: true, numbers: true, symbols: false);
    }
}
