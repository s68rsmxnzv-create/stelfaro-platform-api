<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->string('name', 120);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'core_sucursal_id', 'name'], 'cash_register_tenant_branch_name_unique');
        });

        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('expected_balance', 14, 2)->nullable();
            $table->decimal('declared_balance', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'cash_register_id', 'status']);
        });

        Schema::create('cash_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workshop_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40)->default('general')->index();
            $table->string('destination', 30)->default('expense');
            $table->decimal('amount', 14, 2);
            $table->string('status', 30)->default('pending_document')->index();
            $table->string('description', 500);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'workshop_order_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workshop_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10)->index();
            $table->string('kind', 40)->index();
            $table->string('method', 30)->index();
            $table->decimal('amount', 14, 2);
            $table->string('description', 500);
            $table->string('reference', 160)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('idempotency_key', 160);
            $table->json('metadata')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_movements')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_expenses');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
