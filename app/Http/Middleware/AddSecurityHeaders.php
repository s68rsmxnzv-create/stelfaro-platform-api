<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self "https://pagos.wompi.sv")');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        $imageSources = implode(' ', $this->imageSources());

        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "script-src 'self' 'nonce-{$nonce}' https://pagos.wompi.sv",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: {$imageSources}",
            "font-src 'self' data:",
            "connect-src 'self' https://pagos.wompi.sv",
            'frame-src https://pagos.wompi.sv',
            "form-action 'self' https://pagos.wompi.sv",
            'upgrade-insecure-requests',
        ]);

        $response->headers->set(
            config('security.headers.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            $csp,
        );

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function imageSources(): array
    {
        $sources = collect(config('platform.hosts', []))
            ->filter(fn ($host): bool => is_string($host) && $host !== '')
            ->map(fn (string $host): string => 'https://'.trim($host, '/'));

        $coreBaseUrl = config('services.dte_core.base_url');
        $coreHost = is_string($coreBaseUrl) ? parse_url($coreBaseUrl, PHP_URL_HOST) : null;

        if (is_string($coreHost) && $coreHost !== '') {
            $sources->push('https://'.$coreHost);
        }

        return $sources
            ->unique()
            ->values()
            ->all();
    }
}
