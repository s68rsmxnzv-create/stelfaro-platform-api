<?php

use App\Support\Platform\PortalUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las notificaciones de caja ("Caja pendiente de conteo" / "Caja abierta automáticamente")
     * se crearon con action_url = '/caja' a secas, una ruta que nunca existió: las rutas reales
     * viven bajo el prefijo de la app del tenant (/taller/caja o /facturacion/caja). Esto deja
     * un 404 al hacer click en la campanita para toda notificación ya creada antes del fix en
     * App\Services\Cash\CashAutomationService. Aquí se reescribe el action_url de esas filas
     * existentes con la misma resolución que ya usa el servicio corregido.
     */
    public function up(): void
    {
        DB::table('internal_notifications')
            ->where('category', 'cash')
            ->where('action_url', '/caja')
            ->orderBy('id')
            ->each(function (object $notification): void {
                $access = DB::table('tenant_app_accesses')
                    ->join('platform_apps', 'platform_apps.id', '=', 'tenant_app_accesses.platform_app_id')
                    ->where('tenant_app_accesses.tenant_id', $notification->tenant_id)
                    ->where('tenant_app_accesses.status', 'active')
                    ->orderByDesc('tenant_app_accesses.is_default')
                    ->orderBy('tenant_app_accesses.id')
                    ->value('platform_apps.key');

                if (! $access) {
                    return;
                }

                DB::table('internal_notifications')
                    ->where('id', $notification->id)
                    ->update(['action_url' => PortalUrl::app($access, '/caja')]);
            });
    }

    public function down(): void
    {
        // Irreversible a propósito: no hay forma de distinguir, tras el backfill, qué filas
        // tenían originalmente '/caja' a secas de las que ya llegaron con la URL correcta.
    }
};
