<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_orders', fn (Blueprint $table) => $table->string('dte_type', 2)->nullable()->after('billing_status'));
    }

    public function down(): void
    {
        Schema::table('workshop_orders', fn (Blueprint $table) => $table->dropColumn('dte_type'));
    }
};
