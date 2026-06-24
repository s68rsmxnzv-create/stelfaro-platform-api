<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\WompiPaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WompiWebhookController extends Controller
{
    public function __invoke(Request $request, WompiPaymentProcessor $processor): JsonResponse
    {
        $result = $processor->handleWebhook($request);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'] ?? null,
            'event_id' => isset($result['event']) ? $result['event']->id : null,
        ], $result['http_status']);
    }
}
