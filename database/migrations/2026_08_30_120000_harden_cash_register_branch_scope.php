<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropUnique('cash_register_tenant_branch_name_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_registers ALTER COLUMN core_sucursal_id SET NOT NULL');
        }

        DB::statement(
            'CREATE UNIQUE INDEX cash_register_tenant_active_branch_unique '
            .'ON cash_registers (tenant_id, core_sucursal_id) '
            ."WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cash_register_tenant_active_branch_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_registers ALTER COLUMN core_sucursal_id DROP NOT NULL');
        }

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'core_sucursal_id', 'name'], 'cash_register_tenant_branch_name_unique');
        });
    }
};
