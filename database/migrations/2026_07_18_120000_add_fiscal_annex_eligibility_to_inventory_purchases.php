<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->boolean('fiscal_annex_eligible')->default(true)->after('is_consumable')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchases', function (Blueprint $table): void {
            $table->dropColumn('fiscal_annex_eligible');
        });
    }
};
