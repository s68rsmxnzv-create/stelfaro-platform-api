<?php

namespace App\Http\Controllers;

use App\Services\CoreBillingSessionBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoreBillingSessionController extends Controller
{
    public function __invoke(Request $request, CoreBillingSessionBroker $broker): JsonResponse
    {
        try {
            return response()->json($broker->openFor($request->user()));
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }
    }
}
