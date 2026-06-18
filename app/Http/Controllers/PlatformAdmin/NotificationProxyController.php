<?php

namespace App\Http\Controllers\PlatformAdmin;

use App\Http\Controllers\Controller;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class NotificationProxyController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformAdminAccess $adminAccess,
        string $path = '',
    ): Response {
        $adminAccess->authorize($request->user());

        $baseUrl = rtrim((string) config('services.notifications.base_url'), '/');
        $token = (string) config('services.notifications.internal_token', '');

        abort_if($baseUrl === '' || $token === '', 503, 'La conexion con notificaciones no esta configurada.');

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(15)
            ->send($request->method(), $baseUrl.'/'.ltrim($path, '/'), $this->optionsFor($request));

        return $this->toLaravelResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(Request $request): array
    {
        $options = [
            'query' => $request->query(),
        ];

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $options['json'] = $request->all();
        }

        return $options;
    }

    private function toLaravelResponse(ClientResponse $response): Response
    {
        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type', 'application/json'));
    }
}
