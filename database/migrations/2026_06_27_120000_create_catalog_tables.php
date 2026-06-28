<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 20)->default('mixed')->index();
            $table->string('status', 20)->default('active')->index();
            $table->json('legacy_reference')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_category_id')->nullable()->constrained('catalog_categories')->nullOnDelete();
            $table->unsignedBigInteger('legacy_item_id')->nullable()->index();
            $table->string('sku', 60)->nullable();
            $table->string('name', 160);
            $table->string('description', 255)->nullable();
            $table->string('item_type', 30)->default('product')->index();
            $table->string('unit_code', 10)->default('59');
            $table->string('unit_name', 60)->nullable();
            $table->unsignedInteger('units_per_package')->default(1);
            $table->boolean('taxable')->default(true);
            $table->boolean('controls_inventory')->default(false)->index();
            $table->decimal('base_price', 12, 2)->default(0);
            $table->boolean('base_price_includes_tax')->default(false);
            $table->decimal('reference_cost', 12, 4)->nullable();
            $table->string('cost_source', 20)->default('none')->index();
            $table->decimal('stock_quantity', 14, 3)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'item_type']);
            $table->index(['tenant_id', 'status', 'controls_inventory'], 'catalog_items_tenant_status_stock_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_categories');
    }
};
