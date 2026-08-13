<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->decimal('tax_total', 14, 2)->default(0)->after('discount_total');
        });

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->boolean('price_includes_tax')->default(true)->after('unit_price');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->dropColumn(['price_includes_tax', 'tax_amount']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('tax_total');
        });
    }
};
