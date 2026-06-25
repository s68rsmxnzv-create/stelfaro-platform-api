<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CoreBillingSessionBroker
{
    /**
     * @return array<string, mixed>
     */
    public function openFor(User $user, ?UserTenantMembership $membership = null): array
    {
        $membership ??= $this->defaultMembership($user);
        $tenant = $membership?->tenant;

        if (! $membership || ! $tenant) {
            throw new RuntimeException('No hay una empresa fiscal activa vinculada a este usuario.');
        }

        $empresaId = app(TenantFiscalLinkResolver::class)->coreEmpresaId($tenant);
        $accessPolicy = app(PlatformAccessPolicy::class);
        $assignments = $membership->fiscalAssignments()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
        $defaultAssignment = $assignments->first();

        if (! $accessPolicy->canAccessFiscalSession($user, $membership)) {
            throw new RuntimeException('No tienes acceso activo a la sesion fiscal de esta empresa.');
        }

        $billingRole = $accessPolicy->fiscalRoleFor($membership);

        return $this->open([
            'email' => $user->email,
            'name' => $user->name,
            'role' => $billingRole,
            'device_name' => 'stelfaro-platform',
            'platform_user_id' => $user->id,
            'platform_tenant_id' => $tenant->id,
            'platform_tenant_slug' => $tenant->slug,
            'platform_tenant_name' => $tenant->name,
            'platform_session_id' => session()->getId(),
            'empresas' => [[
                'id' => (int) $empresaId,
                'role' => $billingRole,
                'active' => true,
                'sucursales' => $assignments->pluck('core_sucursal_id')->unique()->values()->all(),
                'puntos_venta' => $assignments->pluck('core_punto_venta_id')->unique()->values()->all(),
                'default_sucursal_id' => $defaultAssignment?->core_sucursal_id,
                'default_punto_venta_id' => $defaultAssignment?->core_punto_venta_id,
            ]],
        ]);
    }

    public function revokePlatformSession(?string $platformSessionId): void
    {
        if (! is_string($platformSessionId) || $platformSessionId === '') {
            return;
        }

        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        $internalToken = config('services.dte_core.internal_token');

        if ($baseUrl === '' || ! is_string($internalToken) || $internalToken === '') {
            return;
        }

        try {
            Http::acceptJson()
                ->withToken($internalToken)
                ->timeout(10)
                ->post($baseUrl.'/internal/auth/billing-session/revoke', [
                    'platform_session_id' => $platformSessionId,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('No fue posible revocar sesiones fiscales vinculadas a la sesion de plataforma.', [
                'platform_session_id' => $platformSessionId,
                'error' => $exception->getMessage(),
            ]);
        }
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
            $message = (string) ($response->json('message') ?? 'No fue posible abrir la sesion fiscal.');

            throw new RuntimeException($message);
        }

        $session = $response->json();

        if (! is_array($session)) {
            throw new RuntimeException('No fue posible abrir la sesion fiscal.');
        }

        return $session;
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
}
