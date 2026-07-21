<?php

namespace App\Http\Controllers;

use App\Models\WorkshopDeviceAccess;
use App\Models\WorkshopDeviceAccessSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicWorkshopDeviceController extends Controller
{
    public function show(string $token): View
    {
        $access = $this->access($token)->load('order.device');
        $this->log($access, request(), 'opened');

        return view('workshop.device', [
            'token' => $token,
            'order' => $access->order,
            'device' => $access->device,
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        $access = $this->access($token);
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $rateKey = 'workshop-device-pin:'.$access->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->log($access, $request, 'pin_rate_limited');

            return response()->json(['message' => 'Demasiados intentos. Espera antes de volver a probar.'], 429);
        }
        if (! Hash::check($data['pin'], $access->pin_hash)) {
            RateLimiter::hit($rateKey, 15 * 60);
            $this->log($access, $request, 'pin_rejected');

            return response()->json(['message' => 'El PIN no es correcto.'], 422);
        }

        RateLimiter::clear($rateKey);
        $plainSession = Str::random(64);
        DB::transaction(function () use ($access, $plainSession, $request): void {
            $access->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $access->sessions()->create([
                'token_hash' => hash('sha256', $plainSession),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'expires_at' => now()->addMinutes(15),
                'last_seen_at' => now(),
            ]);
            $access->update(['last_accessed_at' => now()]);
        });
        $this->log($access, $request, 'pin_accepted');

        return response()->json([
            'session_token' => $plainSession,
            'expires_in' => 900,
            'order' => $this->privatePayload($access->fresh()->load('order.device')),
        ]);
    }

    public function reveal(Request $request, string $token): JsonResponse
    {
        [$access] = $this->authorized($request, $token);
        $order = $access->order;
        if (in_array($order->status, ['ready', 'delivered', 'cancelled'], true)) {
            $this->log($access, $request, 'secret_denied_status', ['status' => $order->status]);

            return response()->json(['message' => 'El acceso ya no está disponible para el estado actual.'], 422);
        }
        if (! $access->device->is_locked || blank($access->device->access_secret)) {
            return response()->json(['message' => 'No hay una clave de acceso registrada.'], 422);
        }
        $this->log($access, $request, 'secret_revealed', ['type' => $access->device->access_type]);

        return response()->json([
            'type' => $access->device->access_type,
            'secret' => $access->device->access_secret,
        ]);
    }

    public function updateStatus(Request $request, string $token): JsonResponse
    {
        [$access] = $this->authorized($request, $token);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys($this->statusLabels()))]]);
        $order = $access->order;
        $allowed = [
            'received' => ['diagnosing'],
            'diagnosing' => ['awaiting_approval'],
            'awaiting_approval' => [],
            'approved' => ['repairing'],
            'repairing' => ['ready'],
            'ready' => [],
            'delivered' => [],
            'cancelled' => [],
        ];
        if (! in_array($data['status'], $allowed[$order->status] ?? [], true)) {
            return response()->json(['message' => 'Ese cambio no está disponible desde el estado actual.'], 422);
        }
        if ($data['status'] === 'awaiting_approval' && (blank($order->diagnosis) || $order->estimated_total === null)) {
            return response()->json(['message' => 'Completa el diagnóstico y presupuesto desde Órdenes antes de solicitar aprobación.'], 422);
        }

        $previous = $order->status;
        $order->status = $data['status'];
        if ($data['status'] === 'ready') {
            $order->completed_at = now();
        }
        $order->save();
        $this->log($access, $request, 'status_updated', ['from' => $previous, 'to' => $order->status]);

        return response()->json(['order' => $this->privatePayload($access->fresh()->load('order.device'))]);
    }

    private function access(string $token): WorkshopDeviceAccess
    {
        return WorkshopDeviceAccess::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }

    /** @return array{WorkshopDeviceAccess,WorkshopDeviceAccessSession} */
    private function authorized(Request $request, string $token): array
    {
        $access = $this->access($token)->load('order.device');
        $plainSession = (string) $request->header('X-Workshop-Session', '');
        abort_if($plainSession === '', 401, 'La sesión móvil expiró.');
        $session = WorkshopDeviceAccessSession::query()
            ->where('workshop_device_access_id', $access->id)
            ->where('token_hash', hash('sha256', $plainSession))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($session, 401, 'La sesión móvil expiró.');
        $currentAgent = Str::limit((string) $request->userAgent(), 500, '');
        abort_unless(hash_equals((string) $session->user_agent, $currentAgent), 401, 'La sesión móvil no pertenece a este dispositivo.');
        $session->update(['last_seen_at' => now()]);

        return [$access, $session];
    }

    /** @return array<string,mixed> */
    private function privatePayload(WorkshopDeviceAccess $access): array
    {
        $order = $access->order;

        return [
            'ticket' => 'T-'.str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT),
            'equipment_label' => 'Equipo '.((int) $order->reception_sequence),
            'status' => $order->status,
            'status_label' => $this->statusLabels()[$order->status] ?? $order->status,
            'next_statuses' => match ($order->status) {
                'received' => [['value' => 'diagnosing', 'label' => 'Iniciar diagnóstico']],
                'diagnosing' => [['value' => 'awaiting_approval', 'label' => 'Solicitar aprobación']],
                'approved' => [['value' => 'repairing', 'label' => 'Iniciar reparación']],
                'repairing' => [['value' => 'ready', 'label' => 'Marcar como listo']],
                default => [],
            },
            'device' => [
                'type' => $access->device->type,
                'brand' => $access->device->brand,
                'model' => $access->device->model,
                'identifier' => $access->device->imei ?: $access->device->serial_number,
                'has_access_secret' => filled($access->device->access_secret),
                'access_type' => $access->device->access_type,
            ],
        ];
    }

    /** @return array<string,string> */
    private function statusLabels(): array
    {
        return ['received' => 'Recibido', 'diagnosing' => 'En diagnóstico', 'awaiting_approval' => 'Esperando aprobación', 'approved' => 'Aprobado', 'repairing' => 'En reparación', 'ready' => 'Listo', 'delivered' => 'Entregado', 'cancelled' => 'Cancelado'];
    }

    /** @param array<string,mixed> $metadata */
    private function log(WorkshopDeviceAccess $access, Request $request, string $action, array $metadata = []): void
    {
        DB::table('workshop_device_access_events')->insert([
            'tenant_id' => $access->tenant_id,
            'workshop_order_id' => $access->workshop_order_id,
            'workshop_device_access_id' => $access->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
