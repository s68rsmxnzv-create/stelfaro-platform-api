<?php

namespace Database\Seeders;

use App\Models\PlatformApp;
use Illuminate\Database\Seeder;

class SystemConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        PlatformApp::query()->updateOrCreate(
            ['key' => 'taller'],
            [
                'name' => 'Taller electrónico',
                'host' => 'new.stelfaro.com',
                'default_path' => '/',
                'status' => 'active',
            ],
        );

        PlatformApp::query()->updateOrCreate(
            ['key' => 'facturacion'],
            [
                'name' => 'Facturación',
                'host' => 'new.stelfaro.com',
                'default_path' => '/',
                'status' => 'active',
            ],
        );
    }
}
