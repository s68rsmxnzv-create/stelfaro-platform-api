<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->string('approval_decision', 20)->nullable()->after('estimated_total');
            $table->string('approval_method', 20)->nullable()->after('approval_decision');
            $table->text('approval_notes')->nullable()->after('approval_method');
            $table->foreignId('approval_recorded_by')->nullable()->after('approval_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_decided_at')->nullable()->after('approval_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_recorded_by');
            $table->dropColumn(['approval_decision', 'approval_method', 'approval_notes', 'approval_decided_at']);
        });
    }
};
