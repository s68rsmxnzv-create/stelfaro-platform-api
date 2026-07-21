<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $emailChanged = strtolower($user->email) !== strtolower($validated['email']);
        $user->fill([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => filled($validated['phone'] ?? null) ? trim((string) $validated['phone']) : null,
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json(['data' => $this->payload($user->refresh())]);
    }

    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return response()->json([
            'message' => 'Contraseña actualizada. Las demás sesiones fueron cerradas.',
            'data' => $this->payload($user->refresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified_at' => optional($user->email_verified_at)->toISOString(),
            'password_changed_at' => optional($user->password_changed_at)->toISOString(),
        ];
    }
}
