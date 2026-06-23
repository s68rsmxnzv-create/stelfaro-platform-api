<?php

namespace App\Services\Platform;

use App\Models\Tenant;

class TenantEnvironmentResolver
{
    public function environmentFor(Tenant $tenant): ?string
    {
        $environment = data_get($tenant->metadata, 'environment');

        return is_string($environment) && in_array($environment, ['00', '01'], true)
            ? $environment
            : null;
    }

    public function isProduction(Tenant $tenant): bool
    {
        return $this->environmentFor($tenant) === '01';
    }
}
