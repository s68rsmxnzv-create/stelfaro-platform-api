<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_device_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_device_id')->constrained()->cascadeOnDelete();
            $table->string('public_token_hash', 64)->unique();
            $table->text('public_token_encrypted');
            $table->string('pin_hash');
            $table->text('pin_encrypted');
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workshop_device_access_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workshop_device_access_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workshop_device_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_device_access_id')->constrained()->cascadeOnDelete();
            $table->string('action', 80);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workshop_device_access_id', 'created_at'], 'workshop_access_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_device_access_events');
        Schema::dropIfExists('workshop_device_access_sessions');
        Schema::dropIfExists('workshop_device_accesses');
    }
};
