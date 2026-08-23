<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountant_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('core_empresa_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'core_empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_contacts');
    }
};
