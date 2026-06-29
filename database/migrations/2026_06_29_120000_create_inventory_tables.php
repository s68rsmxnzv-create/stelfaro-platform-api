<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('tax_id', 32)->nullable();
            $table->string('nrc', 32)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'tax_id']);
        });

        Schema::create('inventory_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_supplier_id')->nullable()->constrained('inventory_suppliers')->nullOnDelete();
            $table->string('document_type', 40)->nullable();
            $table->string('document_number', 80)->nullable();
            $table->date('purchase_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('status', 20)->default('registered')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_date']);
            $table->index(['tenant_id', 'document_type', 'document_number'], 'inventory_purchases_document_idx');
        });

        Schema::create('inventory_purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_purchase_id')->constrained('inventory_purchases')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_purchase_id']);
            $table->index(['tenant_id', 'catalog_item_id']);
        });

        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('inventory_supplier_id')->nullable()->constrained('inventory_suppliers')->nullOnDelete();
            $table->foreignId('inventory_purchase_id')->nullable()->constrained('inventory_purchases')->nullOnDelete();
            $table->foreignId('inventory_purchase_line_id')->nullable()->constrained('inventory_purchase_lines')->nullOnDelete();
            $table->string('lot_code', 80);
            $table->date('received_date')->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('initial_quantity', 14, 3)->default(0);
            $table->decimal('available_quantity', 14, 3)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'lot_code']);
            $table->index(['tenant_id', 'catalog_item_id', 'available_quantity'], 'inventory_lots_item_available_idx');
            $table->index(['tenant_id', 'received_date', 'id']);
        });

        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('status', 20)->default('reserved')->index();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 80)->nullable();
            $table->string('source_number', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('inventory_reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('description_snapshot', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_reservation_id'], 'inventory_reservation_lines_reservation_idx');
            $table->index(['tenant_id', 'catalog_item_id']);
        });

        Schema::create('inventory_sale_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_reservation_line_id')->constrained('inventory_reservation_lines')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();

            $table->index(['tenant_id', 'inventory_reservation_line_id'], 'inventory_allocations_reservation_line_idx');
            $table->index(['tenant_id', 'catalog_item_id']);
            $table->index(['tenant_id', 'inventory_lot_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->string('movement_type', 40)->index();
            $table->string('reason', 40)->index();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('balance_after', 14, 3)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 80)->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'catalog_item_id', 'created_at']);
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_sale_allocations');
        Schema::dropIfExists('inventory_reservation_lines');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('inventory_purchase_lines');
        Schema::dropIfExists('inventory_purchases');
        Schema::dropIfExists('inventory_suppliers');
    }
};
