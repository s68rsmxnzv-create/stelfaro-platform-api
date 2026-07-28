<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('android_print_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 6)->index();
            $table->string('agent_name', 120)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('android_print_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('device_name', 120)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('android_print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('android_print_agents')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->json('print_payload');
            $table->timestamp('processing_at')->nullable()->index();
            $table->timestamp('printed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status', 'created_at'], 'android_print_jobs_poll_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('android_print_jobs');
        Schema::dropIfExists('android_print_agents');
        Schema::dropIfExists('android_print_pairing_codes');
    }
};
