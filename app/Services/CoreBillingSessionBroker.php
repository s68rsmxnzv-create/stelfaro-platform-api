<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoreBillingSessionBroker
{
    /**
     * @return array<string, mixed>
     */
    public function openFor(User $user): array
    {
        $membership = $this->defaultMembership($user);
        $tenant = $membership?->tenant;
        $empresaId = $tenant?->metadata['core_empresa_id'] ?? null;

        if (! $membership || ! $tenant || ! is_numeric($empresaId)) {
            throw new RuntimeException('No hay una empresa fiscal activa vinculada a este usuario.');
        }

        $billingRole = $this->billingRoleFor($membership->role);

        return $this->open([
            'email' => $user->email,
            'name' => $user->name,
            'role' => $billingRole,
            'device_name' => 'stelfaro-platform',
            'platform_user_id' => $user->id,
            'platform_tenant_id' => $tenant->id,
            'platform_tenant_slug' => $tenant->slug,
            'platform_tenant_name' => $tenant->name,
            'empresas' => [[
                'id' => (int) $empresaId,
                'role' => $billingRole,
                'active' => true,
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function openBackoffice(): array
    {
        $email = config('services.dte_core.admin_email');
        $deviceName = config('services.dte_core.admin_device_name', 'stelfaro-platform-admin');

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('La cuenta fiscal backoffice no esta configurada.');
        }

        return $this->open([
            'email' => $email,
            'name' => 'Stelfaro Fiscal Admin',
            'role' => config('services.dte_core.admin_role', 'admin_fiscal'),
            'device_name' => is_string($deviceName) && $deviceName !== '' ? $deviceName : 'stelfaro-platform-admin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function open(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $internalToken = config('services.dte_core.internal_token');

        if ($baseUrl === '' || ! is_string($internalToken) || $internalToken === '') {
            throw new RuntimeException('La conexion con el core fiscal no esta configurada.');
        }

        $response = Http::acceptJson()
            ->withToken($internalToken)
            ->timeout(15)
            ->post($baseUrl.'/internal/auth/billing-session', $payload);

        if ($response->failed()) {
            throw new RuntimeException('No fue posible abrir la sesion fiscal.');
        }

        return $response->json();
    }

    private function defaultMembership(User $user): ?UserTenantMembership
    {
        return $user->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function billingRoleFor(string $platformRole): string
    {
        return match ($platformRole) {
            'owner', 'admin', 'tenant_admin', 'company_admin', 'billing_admin', 'fiscal_admin' => 'company_admin',
            'viewer', 'read_only' => 'viewer',
            default => 'billing_user',
        };
    }
}
