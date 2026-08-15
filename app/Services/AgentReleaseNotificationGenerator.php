<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\UserTenantMembership;

class AgentReleaseNotificationGenerator
{
    /** @var array<string, string> */
    private const LABELS = [
        'windows' => 'agente de impresión para Windows',
        'android' => 'app de impresión para Android',
    ];

    public function generate(string $platform, string $version): int
    {
        $label = self::LABELS[$platform] ?? "agente de impresión ({$platform})";
        $created = 0;

        UserTenantMembership::query()
            ->with(['tenant:id,status,primary_app_id', 'tenant.primaryApp:id,key'])
            ->where('status', 'active')
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->chunkById(200, function ($memberships) use ($platform, $version, $label, &$created): void {
                foreach ($memberships as $membership) {
                    $appKey = $membership->tenant?->primaryApp?->key ?? 'facturacion';
                    $dedupeKey = implode(':', ['agent-release', $platform, $version, $membership->user_id, $membership->tenant_id]);

                    $notification = InternalNotification::query()->firstOrCreate(
                        ['dedupe_key' => $dedupeKey],
                        [
                            'user_id' => $membership->user_id,
                            'tenant_id' => $membership->tenant_id,
                            'category' => 'agent_release',
                            'title' => 'Nueva versión del agente de impresión disponible',
                            'message' => "La versión {$version} del {$label} ya está disponible. Descárgala desde el Centro de descargas para seguir recibiendo mejoras y soporte.",
                            'action_url' => "/{$appKey}/configuracion?view=downloads",
                            'source_type' => 'print_agent_release',
                            'source_id' => "{$platform}:{$version}",
                            'metadata' => [
                                'platform' => $platform,
                                'version' => $version,
                            ],
                        ],
                    );

                    if ($notification->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}
