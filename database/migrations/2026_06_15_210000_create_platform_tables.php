<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('host')->nullable();
            $table->string('default_path')->default('/');
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->foreignId('primary_app_id')->nullable()->constrained('platform_apps')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_app_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_app_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform_app_id']);
        });

        Schema::create('user_tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner')->index();
            $table->string('status')->default('active')->index();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tenant_memberships');
        Schema::dropIfExists('tenant_app_accesses');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('platform_apps');
    }
};
