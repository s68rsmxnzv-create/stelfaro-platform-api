<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('idempotency_key', 120);
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->string('core_resource_id', 80)->nullable();
            $table->json('payload');
            $table->json('result')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'idempotency_key'], 'fiscal_sync_tenant_kind_key_unique');
            $table->index(['status', 'next_attempt_at'], 'fiscal_sync_pending_idx');
            $table->index(['tenant_id', 'core_resource_id'], 'fiscal_sync_core_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sync_operations');
    }
};
