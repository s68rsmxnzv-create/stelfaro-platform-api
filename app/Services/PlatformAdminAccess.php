<?php

namespace App\Services;

use App\Models\User;
use App\Support\Platform\PlatformRoles;

class PlatformAdminAccess
{
    public function allows(?User $user, string $scope = 'platform'): bool
    {
        if (! $user) {
            return false;
        }

        $email = strtolower(trim($user->email));
        $adminEmails = config("platform.admin.{$scope}_emails", []);

        if ($scope === 'platform') {
            $adminEmails = array_values(array_unique([
                ...$adminEmails,
                ...config('platform.admin.emails', []),
            ]));
        }

        if (in_array($email, $adminEmails, true)) {
            return true;
        }

        $platformRoles = (array) ($scope === 'platform'
            ? config('platform.admin.platform_roles', PlatformRoles::globalAdminRoles())
            : config("platform.admin.{$scope}_platform_roles", []));

        if ($user->platform_role !== null && in_array($user->platform_role, $platformRoles, true)) {
            return true;
        }

        if ($scope === 'platform') {
            return false;
        }

        $adminRoles = config("platform.admin.{$scope}_membership_roles", config('platform.admin.membership_roles', []));

        return $user->memberships()
            ->where('status', 'active')
            ->whereIn('role', $adminRoles)
            ->exists();
    }

    public function authorize(?User $user, string $scope = 'platform'): void
    {
        abort_unless($this->allows($user, $scope), 403, 'No tienes acceso al panel administrativo de plataforma.');
    }
}
