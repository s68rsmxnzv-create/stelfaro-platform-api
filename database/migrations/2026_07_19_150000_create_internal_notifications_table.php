<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category', 60)->index();
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('dedupe_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'tenant_id', 'read_at'], 'internal_notifications_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notifications');
    }
};
