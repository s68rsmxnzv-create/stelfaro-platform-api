<?php

namespace Database\Seeders;

use App\Models\PlatformApp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $taller = PlatformApp::query()->firstOrCreate([
            'key' => 'taller',
        ], [
            'name' => 'Taller electrónico',
            'host' => 'taller.stelfaro.com',
            'default_path' => '/',
        ]);

        $facturacion = PlatformApp::query()->firstOrCreate([
            'key' => 'facturacion',
        ], [
            'name' => 'Facturación',
            'host' => 'facturacion.stelfaro.com',
            'default_path' => '/',
        ]);

        $tenant = Tenant::query()->firstOrCreate([
            'slug' => 'servicio-tecnico-el-faro',
        ], [
            'name' => 'Servicio Técnico El Faro',
            'primary_app_id' => $taller->id,
        ]);

        $tenant->appAccesses()->firstOrCreate([
            'platform_app_id' => $taller->id,
        ], [
            'is_default' => true,
        ]);

        $tenant->appAccesses()->firstOrCreate([
            'platform_app_id' => $facturacion->id,
        ]);

        $user = User::query()->firstOrCreate([
            'email' => 'owner@stelfaro.test',
        ], [
            'name' => 'Owner Demo',
            'password' => Hash::make('password'),
        ]);

        $user->memberships()->firstOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'role' => 'owner',
            'is_default' => true,
        ]);
    }
}
