<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('billing_cycle', 32)->default('monthly')->index();
            $table->json('included_app_keys')->nullable();
            $table->json('limits')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('trialing')->index();
            $table->string('billing_cycle', 32)->default('monthly')->index();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index(['status', 'current_period_ends_at']);
        });

        $now = now();
        DB::table('subscription_plans')->insert([
            [
                'key' => 'starter',
                'name' => 'Starter',
                'description' => 'Facturacion inicial para una empresa pequena.',
                'price_cents' => 1900,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'included_app_keys' => json_encode(['facturacion']),
                'limits' => json_encode(['users' => 2, 'branches' => 1, 'dte_monthly' => 100]),
                'status' => 'active',
                'metadata' => json_encode(['source' => 'system']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'pro',
                'name' => 'Pro',
                'description' => 'Taller y facturacion para operacion en crecimiento.',
                'price_cents' => 4900,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'included_app_keys' => json_encode(['taller', 'facturacion']),
                'limits' => json_encode(['users' => 10, 'branches' => 3, 'dte_monthly' => 1000]),
                'status' => 'active',
                'metadata' => json_encode(['source' => 'system']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'implementation',
                'name' => 'Implementacion',
                'description' => 'Plan manual para onboarding, pilotos y acuerdos comerciales especiales.',
                'price_cents' => 0,
                'currency' => 'USD',
                'billing_cycle' => 'manual',
                'included_app_keys' => json_encode(['taller', 'facturacion']),
                'limits' => json_encode(['users' => null, 'branches' => null, 'dte_monthly' => null]),
                'status' => 'active',
                'metadata' => json_encode(['source' => 'system']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
