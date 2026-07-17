<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->string('billing_status', 20)->default('unbilled')->after('financial_status')->index();
            $table->unsignedBigInteger('core_dte_document_id')->nullable()->after('billing_status');
            $table->string('dte_number', 80)->nullable()->after('core_dte_document_id');
            $table->string('dte_generation_code', 80)->nullable()->after('dte_number');
            $table->timestamp('invoiced_at')->nullable()->after('dte_generation_code');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_orders', fn (Blueprint $table) => $table->dropColumn(['billing_status', 'core_dte_document_id', 'dte_number', 'dte_generation_code', 'invoiced_at']));
    }
};
