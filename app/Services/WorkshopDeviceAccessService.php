<?php

namespace App\Services;

use App\Support\Platform\PortalUrl;
use App\Models\WorkshopDeviceAccess;
use App\Models\WorkshopOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkshopDeviceAccessService
{
    /** @return array{url:string,pin:string} */
    public function ensure(WorkshopOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $lockedOrder = WorkshopOrder::query()->lockForUpdate()->findOrFail($order->id);
            $access = WorkshopDeviceAccess::query()->where('workshop_order_id', $lockedOrder->id)->first();
            if ($access && ! $access->revoked_at && $access->expires_at?->isFuture()) {
                return ['url' => $this->url((string) $access->public_token_encrypted), 'pin' => (string) $access->pin_encrypted];
            }

            $publicToken = Str::random(64);
            $pin = (string) random_int(100000, 999999);
            WorkshopDeviceAccess::query()->updateOrCreate(
                ['workshop_order_id' => $lockedOrder->id],
                [
                    'tenant_id' => $lockedOrder->tenant_id,
                    'workshop_device_id' => $lockedOrder->workshop_device_id,
                    'public_token_hash' => hash('sha256', $publicToken),
                    'public_token_encrypted' => $publicToken,
                    'pin_hash' => Hash::make($pin),
                    'pin_encrypted' => $pin,
                    'expires_at' => now()->addYears(2),
                    'last_accessed_at' => null,
                    'revoked_at' => null,
                ],
            );

            return ['url' => $this->url($publicToken), 'pin' => $pin];
        });
    }

    private function url(string $token): string
    {
        return PortalUrl::app('taller', '/equipo/'.$token);
    }
}
