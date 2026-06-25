<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WompiPaymentReturnController extends Controller
{
    public function __invoke(Request $request)
    {
        $approved = $this->isApprovedRedirect($request);
        $hasTransaction = trim((string) $request->query('idTransaccion', '')) !== '';
        $declined = $request->query->has('esAprobada') && ! $approved && ! $hasTransaction;
        $transactionId = e((string) $request->query('idTransaccion', ''));
        $message = $declined
            ? 'No pudimos confirmar un pago aprobado. Si completaste el pago, revisaremos la notificación de Wompi.'
            : 'Pago recibido. Estamos confirmando la transacción con Wompi para activar tu suscripción.';
        $title = $declined ? 'Pago no confirmado' : 'Pago recibido';

        return response()->make(<<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} | Stelfaro</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; }
        main { width: min(92vw, 560px); border: 1px solid color-mix(in srgb, CanvasText 14%, transparent); border-radius: 16px; padding: 32px; box-shadow: 0 18px 60px color-mix(in srgb, CanvasText 10%, transparent); }
        h1 { margin: 0 0 12px; font-size: clamp(28px, 6vw, 40px); line-height: 1.05; }
        p { margin: 0; color: color-mix(in srgb, CanvasText 72%, transparent); font-size: 16px; line-height: 1.6; }
        .meta { margin-top: 20px; font-size: 13px; }
        a { display: inline-flex; margin-top: 28px; color: #0284c7; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <h1>{$title}</h1>
        <p>{$message}</p>
        <p class="meta">Transacción: {$transactionId}</p>
        <a href="/">Volver a Stelfaro</a>
    </main>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function isApprovedRedirect(Request $request): bool
    {
        $approved = filter_var($request->query('esAprobada'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($approved === true) {
            return true;
        }

        $message = mb_strtolower((string) $request->query('mensaje', ''));
        $message = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $message);

        return str_contains($message, 'aprobada') || str_contains($message, 'exitosa');
    }
}
