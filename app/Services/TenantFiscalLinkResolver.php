<?php

namespace App\Services;

use App\Models\Tenant;
use RuntimeException;

class TenantFiscalLinkResolver
{
    public function coreEmpresaId(Tenant $tenant): int
    {
        $empresaId = $tenant->metadata['core_empresa_id'] ?? null;

        if (! is_numeric($empresaId) || (int) $empresaId < 1) {
            throw new RuntimeException('No hay una empresa fiscal activa vinculada a este tenant.');
        }

        return (int) $empresaId;
    }
}
