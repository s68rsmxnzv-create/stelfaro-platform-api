<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('resource_type', 120)->nullable()->index();
            $table->string('resource_id', 120)->nullable()->index();
            $table->string('result', 32)->default('success')->index();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip_address', 64)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('method', 12)->nullable();
            $table->text('url')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('session_id_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
