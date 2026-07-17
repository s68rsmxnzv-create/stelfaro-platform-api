<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->decimal('final_total', 12, 2)->nullable()->after('estimated_total');
            $table->string('financial_status', 20)->default('pending')->after('final_total')->index();
            $table->timestamp('closed_at')->nullable()->after('delivered_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('workshop_order_payments', function (Blueprint $table): void {
            $table->string('kind', 20)->default('payment')->after('received_by')->index();
        });
        DB::table('workshop_orders')
            ->where('status', 'cancelled')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workshop_order_payments')->whereColumn('workshop_order_payments.workshop_order_id', 'workshop_orders.id')->whereNull('voided_at'))
            ->update(['final_total' => 0, 'financial_status' => 'settled', 'closed_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('workshop_order_payments', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['final_total', 'financial_status', 'closed_at']);
        });
    }
};
