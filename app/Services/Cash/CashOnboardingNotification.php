<?php

namespace App\Services\Cash;

use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Platform\PortalUrl;

class CashOnboardingNotification
{
    /** @param array<string, mixed> $session */
    public function ensure(User $user, array $session): void
    {
        $tenantId = (int) data_get($session, 'tenant.id', 0);
        $appKey = (string) data_get($session, 'default_app.id', '');

        if ($tenantId <= 0 || ! in_array($appKey, ['facturacion', 'taller'], true)) {
            return;
        }

        $membership = $user->memberships()
            ->with('tenant')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $membership || $membership->tenant->cashRegisters()->exists()) {
            return;
        }

        InternalNotification::query()->firstOrCreate(
            ['dedupe_key' => "cash:onboarding:{$tenantId}:{$user->id}"],
            [
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'category' => 'cash',
                'title' => 'Prepara tu caja',
                'message' => 'Configura el nombre y registra el efectivo inicial para comenzar a cobrar.',
                'action_url' => PortalUrl::app($appKey, '/caja'),
                'source_type' => 'cash_onboarding',
                'source_id' => (string) $tenantId,
                'metadata' => ['action_label' => 'Configurar y abrir caja'],
            ],
        );
    }
}
