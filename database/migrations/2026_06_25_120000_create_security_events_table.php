<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 80)->index();
            $table->string('severity', 32)->default('warning')->index();
            $table->string('ip_address', 64)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('method', 12)->nullable();
            $table->text('url')->nullable();
            $table->string('field', 255)->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
