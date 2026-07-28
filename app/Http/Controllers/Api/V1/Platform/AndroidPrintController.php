<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AndroidPrintAgent;
use App\Models\AndroidPrintJob;
use App\Models\AndroidPrintPairingCode;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AndroidPrintController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $agents = AndroidPrintAgent::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (AndroidPrintAgent $agent): array => $this->agentData($agent));

        return response()->json(['data' => $agents]);
    }

    public function pairingCode(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate(['agent_name' => ['nullable', 'string', 'max:120']]);
        do {
            $code = (string) random_int(100000, 999999);
        } while (AndroidPrintPairingCode::query()
            ->where('code', $code)->whereNull('used_at')->where('expires_at', '>', now())->exists());

        $pairing = AndroidPrintPairingCode::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()?->id,
            'code' => $code,
            'agent_name' => trim((string) ($data['agent_name'] ?? '')) ?: null,
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'data' => [
                'code' => $pairing->code,
                'expires_at' => $pairing->expires_at->toISOString(),
                'server_url' => $request->getSchemeAndHttpHost(),
            ],
        ], 201);
    }

    public function revoke(Request $request, Tenant $tenant, AndroidPrintAgent $agent, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        abort_unless($agent->tenant_id === $tenant->id, 404);
        $agent->update(['status' => 'revoked', 'token_hash' => hash('sha256', 'revoked:'.$agent->id.':'.microtime(true))]);

        return response()->json(['ok' => true]);
    }

    public function enqueue(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
            'paperWidth' => ['required', 'in:58,80'],
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.name' => ['required', 'string', 'max:60'],
            'operations.*.args' => ['present', 'array'],
            'openDrawer' => ['nullable', 'boolean'],
        ]);
        $agent = AndroidPrintAgent::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->findOrFail($data['agent_id']);

        // Android 1.0 no reconoce "font"; se conserva el texto y solo se
        // descarta ese cambio visual hasta que exista soporte comprobado.
        $operations = collect($data['operations'])
            ->reject(fn (array $operation): bool => ($operation['name'] ?? '') === 'font')
            ->values()
            ->all();

        $job = AndroidPrintJob::query()->create([
            'tenant_id' => $tenant->id,
            'agent_id' => $agent->id,
            'created_by' => $request->user()?->id,
            'status' => 'pending',
            'print_payload' => [
                'paperWidth' => (string) $data['paperWidth'],
                'operations' => $operations,
                'openDrawer' => (bool) ($data['openDrawer'] ?? false),
            ],
        ]);

        return response()->json(['ok' => true, 'job_id' => $job->id], 201);
    }

    private function agentData(AndroidPrintAgent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'device_name' => $agent->device_name,
            'status' => $agent->status,
            'last_seen_at' => $agent->last_seen_at?->toISOString(),
        ];
    }
}
