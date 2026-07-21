<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->date('business_date')->nullable()->after('cash_register_id')->index();
            $table->string('opening_source', 20)->default('manual')->after('opening_balance');
            $table->string('count_status', 30)->default('pending')->after('status')->index();
        });
        DB::table('cash_sessions')->whereNull('business_date')->update(['business_date' => DB::raw('DATE(opened_at)')]);
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->unique(['cash_register_id', 'business_date'], 'cash_session_register_business_unique');
        });

        Schema::create('cash_register_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 60)->default('America/El_Salvador');
            $table->decimal('default_opening_balance', 14, 2)->default(0);
            $table->boolean('carry_forward_balance')->default(true);
            $table->boolean('auto_open_enabled')->default(false);
            $table->time('auto_open_time')->nullable();
            $table->boolean('auto_close_enabled')->default(false);
            $table->time('auto_close_time')->nullable();
            $table->unsignedSmallInteger('close_grace_minutes')->default(15);
            $table->json('working_days');
            $table->json('non_working_dates')->nullable();
            $table->boolean('use_official_holidays')->default(false);
            $table->boolean('allow_non_cash_when_closed')->default(true);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('core_sucursal_id')->nullable()->after('tenant_id');
            $table->string('core_sucursal_code', 30)->nullable()->after('core_sucursal_id');
            $table->string('core_sucursal_name', 160)->nullable()->after('core_sucursal_code');
            $table->index(['tenant_id', 'core_sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'core_sucursal_id']);
            $table->dropColumn(['core_sucursal_id', 'core_sucursal_code', 'core_sucursal_name']);
        });
        Schema::dropIfExists('cash_register_settings');
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropUnique('cash_session_register_business_unique');
            $table->dropColumn(['business_date', 'opening_source', 'count_status']);
        });
    }
};
