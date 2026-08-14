<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Legal\LegalAcceptanceRequirement;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegalDocumentsAccepted
{
    public function __construct(private readonly LegalAcceptanceRequirement $requirement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if (! $user || ! $this->requirement->pending($user, $this->routeTenant($request))) {
            return $next($request);
        }

        $target = $request->getSchemeAndHttpHost().'/aceptacion-legal';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Debes aceptar los documentos legales vigentes antes de continuar.',
                'redirect' => $target,
            ], 428);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }

    private function routeTenant(Request $request): ?Tenant
    {
        $tenant = $request->route('tenant');

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        return is_numeric($tenant) ? Tenant::query()->find((int) $tenant) : null;
    }
}
