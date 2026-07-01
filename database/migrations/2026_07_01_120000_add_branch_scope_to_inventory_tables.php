<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('inventory_supplier_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');

            $table->index(['tenant_id', 'core_sucursal_id', 'purchase_date'], 'inventory_purchases_branch_date_idx');
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('tenant_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');

            $table->index(['tenant_id', 'core_sucursal_id', 'catalog_item_id', 'available_quantity'], 'inventory_lots_branch_item_available_idx');
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('tenant_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');

            $table->index(['tenant_id', 'core_sucursal_id', 'status'], 'inventory_reservations_branch_status_idx');
        });

        Schema::table('inventory_sale_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('tenant_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');

            $table->index(['tenant_id', 'core_sucursal_id', 'catalog_item_id'], 'inventory_allocations_branch_item_idx');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('tenant_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');

            $table->index(['tenant_id', 'core_sucursal_id', 'catalog_item_id', 'created_at'], 'inventory_movements_branch_item_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inventory_movements_branch_item_created_idx');
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });

        Schema::table('inventory_sale_allocations', function (Blueprint $table): void {
            $table->dropIndex('inventory_allocations_branch_item_idx');
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_branch_status_idx');
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropIndex('inventory_lots_branch_item_available_idx');
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });

        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->dropIndex('inventory_purchases_branch_date_idx');
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });
    }
};
