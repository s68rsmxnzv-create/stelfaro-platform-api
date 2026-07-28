<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AndroidPrintAgent;
use App\Models\AndroidPrintJob;
use App\Models\AndroidPrintPairingCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AndroidPrintAgentController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['ok' => true, 'name' => 'Stelfaro', 'version' => '1.0']);
    }

    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $pairing = AndroidPrintPairingCode::query()
                ->where('code', $data['code'])
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairing) {
                return response()->json(['ok' => false, 'message' => 'Código inválido o vencido.'], 422);
            }

            $plainToken = Str::random(80);
            $deviceName = trim((string) ($data['device_name'] ?? ''));
            $agent = AndroidPrintAgent::query()->create([
                'tenant_id' => $pairing->tenant_id,
                'name' => $pairing->agent_name ?: ($deviceName ?: 'Agente Android'),
                'device_name' => $deviceName ?: null,
                'token_hash' => hash('sha256', $plainToken),
                'status' => 'active',
                'last_seen_at' => now(),
            ]);
            $pairing->update(['used_at' => now()]);

            return response()->json([
                'ok' => true,
                'bearer_token' => $plainToken,
                'token_type' => 'Bearer',
                'device_name' => $agent->name,
            ]);
        });
    }

    public function jobs(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $agent->update(['last_seen_at' => now()]);
        $jobs = DB::transaction(function () use ($agent) {
            $rows = AndroidPrintJob::query()
                ->where('agent_id', $agent->id)
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($stale): void {
                            $stale->where('status', 'processing')
                                ->where('processing_at', '<', now()->subMinutes(3));
                        });
                })
                ->orderBy('id')
                ->limit(1)
                ->lockForUpdate()
                ->get();
            foreach ($rows as $row) {
                $row->update(['status' => 'processing', 'processing_at' => now()]);
            }

            return $rows;
        });

        return response()->json($jobs->map(function (AndroidPrintJob $job): array {
            $payload = $job->print_payload;

            return [
                'id' => (string) $job->id,
                'content' => null,
                'operations' => $payload['operations'] ?? [],
                'print_payload' => $payload,
                'paperWidth' => (string) ($payload['paperWidth'] ?? '80'),
                'paper_width' => (string) ($payload['paperWidth'] ?? '80'),
                'widthMm' => (int) ($payload['paperWidth'] ?? 80),
                'open_drawer' => (bool) ($payload['openDrawer'] ?? false),
            ];
        })->values());
    }

    public function printed(Request $request, AndroidPrintJob $job): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        abort_unless($job->agent_id === $agent->id, 404);
        $job->update(['status' => 'printed', 'printed_at' => now(), 'processing_at' => null]);
        $agent->update(['last_seen_at' => now()]);

        return response()->json(['ok' => true, 'success' => true]);
    }

    private function agent(Request $request): ?AndroidPrintAgent
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return null;
        }

        return AndroidPrintAgent::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'active')
            ->first();
    }
}
