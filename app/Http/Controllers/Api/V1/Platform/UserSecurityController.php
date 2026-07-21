<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSecurityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->session()->getId();
        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => $this->sessionPayload($session, $currentId))
            ->values();
        $events = SecurityEvent::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (SecurityEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type,
                'severity' => $event->severity,
                'ip_address' => $event->ip_address,
                'device' => $this->deviceLabel($event->user_agent),
                'created_at' => optional($event->created_at)->toISOString(),
            ]);

        return response()->json(['sessions' => $sessions, 'events' => $events]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        abort_if(hash_equals($request->session()->getId(), $sessionId), 422, 'Usa Salir para cerrar la sesión actual.');

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->delete();

        abort_unless($deleted === 1, 404, 'La sesión ya no está activa.');

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->json(['message' => $deleted === 1 ? 'Se cerró una sesión.' : "Se cerraron {$deleted} sesiones.", 'closed' => $deleted]);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(object $session, string $currentId): array
    {
        return [
            'id' => (string) $session->id,
            'current' => hash_equals($currentId, (string) $session->id),
            'ip_address' => $session->ip_address,
            'device' => $this->deviceLabel($session->user_agent),
            'last_activity' => now()->setTimestamp((int) $session->last_activity)->toISOString(),
        ];
    }

    private function deviceLabel(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);
        $browser = str_contains($agent, 'edg/') ? 'Edge' : (str_contains($agent, 'firefox/') ? 'Firefox' : (str_contains($agent, 'chrome/') ? 'Chrome' : (str_contains($agent, 'safari/') ? 'Safari' : 'Navegador')));
        $system = str_contains($agent, 'iphone') || str_contains($agent, 'ipad') ? 'iOS' : (str_contains($agent, 'android') ? 'Android' : (str_contains($agent, 'windows') ? 'Windows' : (str_contains($agent, 'mac os') ? 'macOS' : 'Dispositivo')));

        return Str::limit("{$browser} · {$system}", 80, '');
    }
}
