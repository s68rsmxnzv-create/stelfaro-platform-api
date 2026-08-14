<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('version', 40);
            $table->string('title');
            $table->date('effective_at');
            $table->string('public_path');
            $table->char('content_hash', 64);
            $table->longText('content_snapshot');
            $table->timestamps();

            $table->unique(['type', 'version']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('user_tenant_memberships')->nullOnDelete();
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 40);
            $table->string('document_version', 40);
            $table->char('document_hash', 64);
            $table->string('acceptance_version', 40);
            $table->text('acceptance_text');
            $table->string('environment', 2);
            $table->string('role_at_acceptance', 60)->nullable();
            $table->char('user_email_hash', 64);
            $table->string('tenant_slug')->nullable();
            $table->string('tenant_name')->nullable();
            $table->string('authentication_method', 40)->default('password_reentry');
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('session_id_hash', 64)->nullable();
            $table->string('request_id', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id', 'legal_document_id'], 'legal_acceptance_once');
            $table->index(['user_id', 'tenant_id', 'accepted_at'], 'legal_acceptance_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
    }
};
