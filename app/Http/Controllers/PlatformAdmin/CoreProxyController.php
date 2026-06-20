<?php

namespace App\Http\Controllers\PlatformAdmin;

use App\Http\Controllers\Controller;
use App\Services\CoreBillingSessionBroker;
use App\Services\PlatformAdminAccess;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class CoreProxyController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformAdminAccess $adminAccess,
        CoreBillingSessionBroker $broker,
        string $path = '',
    ): Response {
        $adminAccess->authorize($request->user(), 'fiscal');

        $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
        abort_if($baseUrl === '', 503, 'La conexion con el core fiscal no esta configurada.');

        $session = $broker->openBackoffice();
        $token = (string) ($session['token'] ?? '');
        abort_if($token === '', 503, 'No fue posible abrir la sesion fiscal.');

        $client = Http::acceptJson()
            ->withToken($token)
            ->timeout(60);

        $response = $this->send($client, $request, $baseUrl.'/'.ltrim($path, '/'));

        return $this->toLaravelResponse($response);
    }

    private function send(PendingRequest $client, Request $request, string $url): ClientResponse
    {
        if ($request->allFiles() !== []) {
            foreach ($request->allFiles() as $key => $file) {
                if (is_array($file)) {
                    continue;
                }

                $client = $client->attach(
                    $key,
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName(),
                );
            }

            return $client->send($request->method(), $url, [
                'query' => $request->query(),
                'multipart' => collect($request->except(array_keys($request->allFiles())))
                    ->map(fn ($value, $key): array => [
                        'name' => $key,
                        'contents' => is_scalar($value) || $value === null ? (string) $value : json_encode($value),
                    ])
                    ->values()
                    ->all(),
            ]);
        }

        $options = [
            'query' => $request->query(),
        ];

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $options['json'] = $request->all();
        }

        return $client->send($request->method(), $url, $options);
    }

    private function toLaravelResponse(ClientResponse $response): Response
    {
        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type', 'application/json'));
    }
}
