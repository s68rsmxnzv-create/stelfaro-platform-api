<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_devices', function (Blueprint $table): void {
            $table->boolean('identifier_not_visible')->default(false)->after('serial_number');
            $table->string('power_status', 20)->default('not_tested')->after('identifier_not_visible');
            $table->json('functional_tests')->nullable()->after('power_status');
            $table->boolean('is_locked')->default(false)->after('functional_tests');
            $table->string('access_type', 20)->nullable()->after('is_locked');
            $table->text('access_secret')->nullable()->after('access_type');
        });

        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->json('physical_conditions')->nullable()->after('physical_condition');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_orders', fn (Blueprint $table) => $table->dropColumn('physical_conditions'));
        Schema::table('workshop_devices', fn (Blueprint $table) => $table->dropColumn(['identifier_not_visible', 'power_status', 'functional_tests', 'is_locked', 'access_type', 'access_secret']));
    }
};
