<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('purchase_number')->nullable()->after('inventory_supplier_id');
            $table->string('document_mode', 20)->default('manual')->after('document_type');
            $table->string('payment_condition', 20)->default('cash')->after('document_number');
            $table->decimal('document_total', 14, 2)->nullable()->after('payment_condition');
            $table->boolean('is_consumable')->default(false)->after('document_total');
            $table->decimal('tax_perceived', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('fovial_per_unit', 14, 4)->default(0)->after('tax_perceived');
            $table->decimal('cotrans_per_unit', 14, 4)->default(0)->after('fovial_per_unit');
            $table->decimal('other_non_taxable_total', 14, 2)->default(0)->after('cotrans_per_unit');
            $table->unsignedTinyInteger('f07_operation_type')->nullable()->after('other_non_taxable_total');
            $table->unsignedTinyInteger('f07_classification')->nullable()->after('f07_operation_type');
            $table->unsignedTinyInteger('f07_sector')->nullable()->after('f07_classification');
            $table->unsignedTinyInteger('f07_cost_expense_type')->nullable()->after('f07_sector');
            $table->json('supplier_snapshot')->nullable()->after('f07_cost_expense_type');
            $table->json('import_metadata')->nullable()->after('supplier_snapshot');

            $table->unique(['tenant_id', 'inventory_supplier_id', 'document_type', 'document_number'], 'inventory_purchase_document_unique');
            $table->index(['tenant_id', 'purchase_number'], 'inventory_purchases_number_idx');
        });

        Schema::table('inventory_purchase_lines', function (Blueprint $table): void {
            $table->string('description_snapshot', 255)->nullable()->after('catalog_item_id');
            $table->string('unit_code', 10)->nullable()->after('description_snapshot');
            $table->string('unit_name', 60)->nullable()->after('unit_code');
            $table->decimal('input_unit_cost', 14, 4)->nullable()->after('quantity');
            $table->decimal('base_unit_cost', 14, 4)->default(0)->after('unit_cost');
            $table->decimal('tax_unit_amount', 14, 4)->default(0)->after('base_unit_cost');
            $table->decimal('tax_rate', 9, 6)->default(0)->after('tax_unit_amount');
            $table->decimal('total_unit_amount', 14, 4)->default(0)->after('tax_rate');
            $table->boolean('price_includes_tax')->default(false)->after('total_unit_amount');
            $table->boolean('no_inventory')->default(false)->after('price_includes_tax');
            $table->boolean('controls_inventory_snapshot')->default(false)->after('no_inventory');
            $table->decimal('inventory_quantity', 14, 3)->default(0)->after('controls_inventory_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchase_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'description_snapshot',
                'unit_code',
                'unit_name',
                'input_unit_cost',
                'base_unit_cost',
                'tax_unit_amount',
                'tax_rate',
                'total_unit_amount',
                'price_includes_tax',
                'no_inventory',
                'controls_inventory_snapshot',
                'inventory_quantity',
            ]);
        });

        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->dropUnique('inventory_purchase_document_unique');
            $table->dropIndex('inventory_purchases_number_idx');
            $table->dropColumn([
                'purchase_number',
                'document_mode',
                'payment_condition',
                'document_total',
                'is_consumable',
                'tax_perceived',
                'fovial_per_unit',
                'cotrans_per_unit',
                'other_non_taxable_total',
                'f07_operation_type',
                'f07_classification',
                'f07_sector',
                'f07_cost_expense_type',
                'supplier_snapshot',
                'import_metadata',
            ]);
        });
    }
};
