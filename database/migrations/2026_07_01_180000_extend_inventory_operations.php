<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->decimal('min_stock_quantity', 14, 3)->default(0)->after('stock_quantity');
        });

        Schema::create('inventory_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->string('source_type', 40)->default('dte');
            $table->string('source_id', 80);
            $table->string('source_number', 120)->nullable();
            $table->date('sale_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_id'], 'inventory_sales_source_unique');
            $table->index(['tenant_id', 'core_sucursal_id', 'sale_date'], 'inventory_sales_branch_date_idx');
        });

        Schema::create('inventory_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('line_origin', 20)->default('free')->index();
            $table->string('description_snapshot', 255)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('reference_unit_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'catalog_item_id', 'created_at'], 'inventory_sale_lines_item_created_idx');
            $table->index(['tenant_id', 'line_origin']);
        });

        Schema::create('inventory_physical_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->date('count_date');
            $table->string('status', 20)->default('applied')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_physical_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_physical_count_id')->constrained('inventory_physical_counts')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->decimal('system_quantity', 14, 3)->default(0);
            $table->decimal('counted_quantity', 14, 3)->default(0);
            $table->decimal('difference_quantity', 14, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_core_sucursal_id');
            $table->string('from_core_sucursal_code', 30)->nullable();
            $table->string('from_core_sucursal_name', 160)->nullable();
            $table->unsignedBigInteger('to_core_sucursal_id');
            $table->string('to_core_sucursal_code', 30)->nullable();
            $table->string('to_core_sucursal_name', 160)->nullable();
            $table->string('transfer_number', 80);
            $table->date('transfer_date');
            $table->string('status', 20)->default('applied')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'transfer_number']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_physical_count_lines');
        Schema::dropIfExists('inventory_physical_counts');
        Schema::dropIfExists('inventory_sale_lines');
        Schema::dropIfExists('inventory_sales');

        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropColumn('min_stock_quantity');
        });
    }
};
