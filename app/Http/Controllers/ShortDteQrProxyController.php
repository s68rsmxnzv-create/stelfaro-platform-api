<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;

class ShortDteQrProxyController extends Controller
{
    public function __invoke(string $destination, int $document, string $token): RedirectResponse
    {
        abort_unless(in_array($destination, ['m', 'd'], true)
            && preg_match('/^[A-Za-z0-9_-]{12}$/', $token) === 1, 404);

        $base = rtrim((string) config('services.dte_core.base_url'), '/');

        try {
            $response = Http::withoutRedirecting()
                ->timeout(5)
                ->get($base.'/q/'.$destination.'/'.$document.'/'.$token);
        } catch (ConnectionException) {
            abort(503);
        }

        abort_unless($response->status() >= 300 && $response->status() < 400, 404);
        $location = (string) $response->header('Location');
        $host = strtolower((string) parse_url($location, PHP_URL_HOST));
        abort_unless(in_array($host, ['admin.factura.gob.sv', 'facturacion.stelfaro.com'], true), 404);

        return redirect()->away($location);
    }
}
