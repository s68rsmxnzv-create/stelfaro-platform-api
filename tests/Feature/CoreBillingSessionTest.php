<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoreBillingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_billing_session_requires_authentication(): void
    {
        $this->get('https://taller.stelfaro.com/platform/core-billing-session')
            ->assertRedirect('https://platform.stelfaro.com/login');
    }

    public function test_core_billing_session_opens_core_session_for_platform_user(): void
    {
        config([
            'services.dte_core.base_url' => 'https://core.test/api/v1',
            'services.dte_core.bridge_password' => 'secret',
        ]);

        Http::fake([
            'https://core.test/api/v1/auth/login' => Http::response([
                'token' => 'core-token',
                'token_type' => 'Bearer',
                'expires_at' => null,
                'user' => [
                    'id' => 1,
                    'name' => 'Armando',
                    'email' => 'armando@example.test',
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'email' => 'armando@example.test',
        ]);

        $this->actingAs($user)
            ->getJson('https://taller.stelfaro.com/platform/core-billing-session')
            ->assertOk()
            ->assertJsonPath('token', 'core-token');

        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/api/v1/auth/login'
            && $request['email'] === 'armando@example.test'
            && $request['password'] === 'secret');
    }
}
