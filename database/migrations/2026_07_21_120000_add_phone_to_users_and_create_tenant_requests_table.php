<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->after('email');
        });

        Schema::create('tenant_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->uuid('idempotency_key')->nullable();
            $table->string('type', 60);
            $table->string('status', 30)->default('pending');
            $table->string('subject', 180);
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->text('admin_response')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['type', 'status', 'created_at']);
            $table->unique(['tenant_id', 'requested_by_user_id', 'idempotency_key'], 'tenant_requests_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_requests');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
