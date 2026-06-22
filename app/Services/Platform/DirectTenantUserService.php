<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Services\UserTenantMembershipManager;
use App\Support\Platform\PlatformRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectTenantUserService
{
    public function __construct(
        private readonly UserTenantMembershipManager $memberships,
    ) {}

    /**
     * @return array{user: User, temporary_password: string|null, created: bool}
     */
    public function create(Tenant $tenant, string $name, string $email, string $role, User $creator): array
    {
        $email = strtolower(trim($email));
        $this->ensureAssignableRole($role);

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

    private function ensureAssignableRole(string $role): void
    {
        if (! in_array($role, [PlatformRoles::COMPANY_ADMIN, PlatformRoles::BILLING_ADMIN, PlatformRoles::BILLING_USER, PlatformRoles::VIEWER], true)) {
            throw ValidationException::withMessages([
                'role' => ['El rol no puede ser asignado.'],
            ]);
        }
    }

    private function temporaryPassword(): string
    {
        return 'Sf-'.Str::password(12, letters: true, numbers: true, symbols: false);
    }
}
