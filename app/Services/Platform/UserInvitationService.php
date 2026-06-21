<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserTenantMembershipManager;
use App\Support\Platform\PlatformRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserInvitationService
{
    public function __construct(
        private readonly InvitationNotificationClient $notifications,
        private readonly UserTenantMembershipManager $memberships,
    ) {}

    /**
     * @return array{invitation: UserInvitation, token: string}
     */
    public function invite(Tenant $tenant, string $email, string $role, User $inviter): array
    {
        $email = strtolower(trim($email));
        $this->ensureInvitableRole($role);

        return DB::transaction(function () use ($tenant, $email, $role, $inviter): array {
            $existingMembership = $tenant->memberships()
                ->whereHas('user', fn ($query) => $query->where('email', $email))
                ->where('status', 'active')
                ->exists();

            if ($existingMembership) {
                throw ValidationException::withMessages([
                    'email' => ['El usuario ya tiene acceso activo a esta empresa.'],
                ]);
            }

            UserInvitation::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->where('status', 'pending')
                ->update(['status' => 'revoked']);

            $token = Str::random(48);
            $invitation = UserInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
                'status' => 'pending',
                'metadata' => ['source' => 'platform-api'],
            ])->load('tenant');

            $this->recordNotificationStatus(
                $invitation,
                $this->notifications->send($invitation, $this->acceptUrl($token))
            );

            return [
                'invitation' => $invitation,
                'token' => $token,
            ];
        });
    }

    /**
     * @return array{invitation: UserInvitation, token: string}
     */
    public function resend(UserInvitation $invitation): array
    {
        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => ['Solo se pueden reenviar invitaciones pendientes.'],
            ]);
        }

        if ($invitation->isExpired()) {
            $invitation->forceFill(['status' => 'expired'])->save();

            throw ValidationException::withMessages([
                'invitation' => ['La invitacion ya expiro.'],
            ]);
        }

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'metadata' => [
                ...($invitation->metadata ?? []),
                'resent_at' => now()->toISOString(),
            ],
        ])->save();

        $invitation->load('tenant');
        $this->recordNotificationStatus(
            $invitation,
            $this->notifications->send($invitation, $this->acceptUrl($token))
        );

        return [
            'invitation' => $invitation->refresh(),
            'token' => $token,
        ];
    }

    public function accept(string $token, User $user): UserInvitation
    {
        $invitation = UserInvitation::query()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => ['La invitacion no esta pendiente.'],
            ]);
        }

        if ($invitation->isExpired()) {
            $invitation->forceFill(['status' => 'expired'])->save();

            throw ValidationException::withMessages([
                'invitation' => ['La invitacion ya expiro.'],
            ]);
        }

        if (strtolower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages([
                'email' => ['Esta invitacion pertenece a otro correo.'],
            ]);
        }

        DB::transaction(function () use ($invitation, $user): void {
            $membership = $user->memberships()
                ->where('tenant_id', $invitation->tenant_id)
                ->first();

            if ($membership) {
                $membership->forceFill([
                    'role' => $invitation->role,
                    'status' => 'active',
                    'metadata' => [
                        ...($membership->metadata ?? []),
                        'accepted_invitation_id' => $invitation->id,
                    ],
                ])->save();
            } else {
                $this->memberships->create($user, $invitation->tenant, $invitation->role, [
                    'accepted_invitation_id' => $invitation->id,
                ]);
            }

            $invitation->forceFill([
                'accepted_at' => now(),
                'status' => 'accepted',
            ])->save();
        });

        return $invitation->refresh();
    }

    public function expirePending(): int
    {
        return UserInvitation::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    private function ensureInvitableRole(string $role): void
    {
        if (! in_array($role, [PlatformRoles::COMPANY_ADMIN, PlatformRoles::BILLING_ADMIN, PlatformRoles::BILLING_USER, PlatformRoles::VIEWER], true)) {
            throw ValidationException::withMessages([
                'role' => ['El rol no puede ser invitado.'],
            ]);
        }
    }

    private function acceptUrl(string $token): string
    {
        return 'https://'.config('platform.hosts.platform').'/invitations/'.$token;
    }

    /**
     * @param  array<string, mixed>|null  $notification
     */
    private function recordNotificationStatus(UserInvitation $invitation, ?array $notification): void
    {
        if (! $notification) {
            return;
        }

        $invitation->forceFill([
            'metadata' => [
                ...($invitation->metadata ?? []),
                'notification' => [
                    'message_id' => $notification['id'] ?? null,
                    'status' => $notification['status'] ?? null,
                    'recipient_email' => $notification['recipient_email'] ?? $invitation->email,
                    'queued_at' => now()->toISOString(),
                ],
            ],
        ])->save();
    }
}
