<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WompiPaymentReturnController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $approved = $this->isApprovedRedirect($request);
        $hasTransaction = trim((string) $request->query('idTransaccion', '')) !== '';
        $declined = $request->query->has('esAprobada') && ! $approved && ! $hasTransaction;
        $transactionId = trim((string) $request->query('idTransaccion', ''));

        return Inertia::render('Payments/WompiReturn', [
            'title' => $declined ? 'Pago no confirmado' : 'Pago recibido',
            'message' => $declined
            ? 'No pudimos confirmar un pago aprobado. Si completaste el pago, revisaremos la notificación de Wompi.'
            : 'Estamos confirmando la transacción con Wompi para activar tu suscripción.',
            'transactionId' => $transactionId,
            'declined' => $declined,
            'confirmationUrl' => route('payments.wompi.confirmation', array_filter([
                'idTransaccion' => $transactionId,
                'identificadorEnlaceComercio' => $request->query('identificadorEnlaceComercio'),
            ])),
        ]);
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
