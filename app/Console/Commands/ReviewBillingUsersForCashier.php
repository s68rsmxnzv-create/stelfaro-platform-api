<?php

namespace App\Console\Commands;

use App\Models\UserTenantMembership;
use App\Support\Platform\PlatformRoles;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewBillingUsersForCashier extends Command
{
    protected $signature = 'cashier:review-billing-users';

    protected $description = 'Lista los usuarios billing_user que pasarán al rol fiscal cashier';

    public function handle(): int
    {
        $lastAccess = DB::table('sessions')
            ->selectRaw('user_id, MAX(last_activity) AS last_activity')
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        $rows = UserTenantMembership::query()
            ->select([
                'user_tenant_memberships.id',
                'user_tenant_memberships.status',
                'tenants.name as tenant_name',
                'users.name as user_name',
                'users.email',
                'last_session.last_activity',
            ])
            ->join('users', 'users.id', '=', 'user_tenant_memberships.user_id')
            ->join('tenants', 'tenants.id', '=', 'user_tenant_memberships.tenant_id')
            ->leftJoinSub($lastAccess, 'last_session', fn ($join) => $join->on('last_session.user_id', '=', 'users.id'))
            ->where('user_tenant_memberships.role', PlatformRoles::BILLING_USER)
            ->orderBy('tenants.name')
            ->orderBy('users.email')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No hay usuarios con rol billing_user.');

            return self::SUCCESS;
        }

        $this->table(
            ['Membresía', 'Empresa', 'Usuario', 'Correo', 'Estado', 'Último acceso'],
            $rows->map(fn ($row): array => [
                $row->id,
                $row->tenant_name,
                $row->user_name,
                $row->email,
                $row->status,
                $row->last_activity
                    ? CarbonImmutable::createFromTimestamp((int) $row->last_activity)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s T')
                    : 'Sin acceso registrado',
            ])->all(),
        );

        $this->warn('Confirma esta lista antes de desplegar el mapeo billing_user → cashier.');

        return self::SUCCESS;
    }
}
