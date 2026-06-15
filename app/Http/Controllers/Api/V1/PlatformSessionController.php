<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformSessionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSessionController extends Controller
{
    public function __invoke(Request $request, PlatformSessionResolver $resolver): JsonResponse
    {
        return response()->json($resolver->resolve($request->user()));
    }
}
