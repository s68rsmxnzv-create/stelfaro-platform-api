<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantPurgeService
{
    public function purgeByCoreEmpresaId(int $coreEmpresaId): bool
    {
        $tenant = Tenant::query()
            ->where('metadata->core_empresa_id', $coreEmpresaId)
            ->first();

        if (! $tenant) {
            return false;
        }

        $this->purge($tenant);

        return true;
    }

    public function purge(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $userIds = DB::table('user_tenant_memberships')
                ->where('tenant_id', $tenant->id)
                ->pluck('user_id')
                ->all();

            DB::table('tenant_app_accesses')->where('tenant_id', $tenant->id)->delete();
            DB::table('user_invitations')->where('tenant_id', $tenant->id)->delete();
            DB::table('user_tenant_memberships')->where('tenant_id', $tenant->id)->delete();
            $tenant->delete();

            foreach ($userIds as $userId) {
                $this->deleteTenantOnlyUser((int) $userId);
            }
        });
    }

    private function deleteTenantOnlyUser(int $userId): void
    {
        $user = User::query()->find($userId);

        if (! $user || $user->platform_role !== null) {
            return;
        }

        $hasOtherMemberships = DB::table('user_tenant_memberships')
            ->where('user_id', $user->id)
            ->exists();

        if (! $hasOtherMemberships) {
            $user->delete();
        }
    }
}
