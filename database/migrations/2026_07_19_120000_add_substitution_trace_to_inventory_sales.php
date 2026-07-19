<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->foreignId('replacement_of_sale_id')
                ->nullable()
                ->after('status')
                ->constrained('inventory_sales')
                ->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('reversed_at');
            $table->index(['tenant_id', 'replacement_of_sale_id', 'status'], 'inventory_sales_replacement_idx');
        });

        Schema::table('inventory_sale_lines', function (Blueprint $table): void {
            $table->foreignId('inherited_from_line_id')
                ->nullable()
                ->after('line_origin')
                ->constrained('inventory_sale_lines')
                ->nullOnDelete();
            $table->decimal('inherited_quantity', 14, 3)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sale_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inherited_from_line_id');
            $table->dropColumn('inherited_quantity');
        });

        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->dropIndex('inventory_sales_replacement_idx');
            $table->dropConstrainedForeignId('replacement_of_sale_id');
            $table->dropColumn('superseded_at');
        });
    }
};
