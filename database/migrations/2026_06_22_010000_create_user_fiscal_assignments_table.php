<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_fiscal_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('user_tenant_memberships')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('core_empresa_id');
            $table->unsignedBigInteger('core_sucursal_id');
            $table->unsignedBigInteger('core_punto_venta_id');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'core_sucursal_id', 'core_punto_venta_id'], 'user_fiscal_assignments_scope_unique');
            $table->index(['core_empresa_id', 'core_sucursal_id', 'core_punto_venta_id'], 'user_fiscal_assignments_core_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_fiscal_assignments');
    }
};
