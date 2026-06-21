<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalUserController extends Controller
{
    public function index(Request $request, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewGlobalUsers($request->user()), 403);

        return response()->json([
            'users' => User::query()
                ->with(['memberships.tenant'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'memberships' => $user->memberships
                        ->map(fn ($membership): array => [
                            'id' => $membership->id,
                            'tenant_id' => $membership->tenant_id,
                            'tenant_name' => $membership->tenant?->name,
                            'role' => $membership->role,
                            'status' => $membership->status,
                            'is_default' => (bool) $membership->is_default,
                        ])
                        ->values(),
                ])
                ->values(),
        ]);
    }
}
