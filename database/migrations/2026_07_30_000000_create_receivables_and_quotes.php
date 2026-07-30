<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 80);
            $table->unsignedBigInteger('core_customer_id')->nullable();
            $table->string('customer_name', 160);
            $table->decimal('original_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'status', 'due_at']);
        });

        Schema::create('receivable_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_account_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type', 24);
            $table->decimal('amount', 14, 2);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['receivable_account_id', 'entry_type', 'source_type', 'source_id'], 'receivable_entry_source_unique');
        });

        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->unsignedBigInteger('quotation_number');
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->unsignedBigInteger('core_customer_id')->nullable();
            $table->string('customer_name', 160);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('title', 180);
            $table->string('status', 24)->default('draft')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('requested_deposit', 14, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'quotation_number']);
        });

        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('order_number');
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->unsignedBigInteger('core_customer_id')->nullable();
            $table->string('customer_name', 160);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('title', 180);
            $table->string('work_type', 40)->default('custom');
            $table->string('status', 24)->default('open')->index();
            $table->string('financial_status', 24)->default('pending')->index();
            $table->string('billing_status', 24)->default('unbilled')->index();
            $table->string('dte_type', 2)->nullable();
            $table->unsignedBigInteger('core_dte_document_id')->nullable();
            $table->string('dte_number', 80)->nullable();
            $table->string('dte_generation_code', 80)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'order_number']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('line_origin', 20)->default('free');
            $table->string('description_snapshot', 255);
            $table->string('sku_snapshot', 120)->nullable();
            $table->string('unit_code', 10)->default('59');
            $table->boolean('taxable')->default(true);
            $table->boolean('price_includes_tax')->default(false);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('reference_unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->default('payment');
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable()->after('workshop_order_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'sales_order_id', 'occurred_at'], 'cash_movements_sales_order_idx');
        });

        Schema::create('quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('description_snapshot', 255);
            $table->string('unit_code', 10)->default('59');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex('cash_movements_sales_order_idx');
            $table->dropConstrainedForeignId('sales_order_id');
        });
        Schema::dropIfExists('sales_order_payments');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('receivable_entries');
        Schema::dropIfExists('receivable_accounts');
    }
};
