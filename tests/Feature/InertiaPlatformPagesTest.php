<?php

namespace Tests\Feature;

use App\Models\PlatformApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaPlatformPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_page_renders_available_apps(): void
    {
        PlatformApp::query()->create([
            'key' => 'taller',
            'name' => 'Taller electrónico',
            'host' => 'taller.stelfaro.com',
            'default_path' => '/',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('https://platform.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Home')
                ->has('availableApps', 1)
                ->where('availableApps.0.id', 'taller')
            );
    }

    public function test_taller_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://taller.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Taller/Dashboard')
                ->where('app.id', 'taller')
            );
    }

    public function test_facturacion_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('https://facturacion.stelfaro.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Facturacion/Dashboard')
                ->where('app.id', 'facturacion')
            );
    }
}
