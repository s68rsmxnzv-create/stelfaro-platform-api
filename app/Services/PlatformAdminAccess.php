<?php

namespace App\Services;

use App\Models\User;

class PlatformAdminAccess
{
    public function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $email = strtolower(trim($user->email));
        $adminEmails = config('platform.admin.emails', []);

        if (in_array($email, $adminEmails, true)) {
            return true;
        }

        $adminRoles = config('platform.admin.membership_roles', []);

        return $user->memberships()
            ->where('status', 'active')
            ->whereIn('role', $adminRoles)
            ->exists();
    }

    public function authorize(?User $user): void
    {
        abort_unless($this->allows($user), 403, 'No tienes acceso al panel administrativo de plataforma.');
    }
}
