<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_receptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_sucursal_id')->nullable();
            $table->string('core_sucursal_code', 30)->nullable();
            $table->string('core_sucursal_name', 160)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('ticket_number');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'ticket_number']);
            $table->index(['tenant_id', 'received_at']);
        });

        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->foreignId('workshop_reception_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('reception_sequence')->default(1)->after('ticket_number');
        });

        DB::table('workshop_orders')->orderBy('id')->each(function (object $order): void {
            $device = DB::table('workshop_devices')->where('id', $order->workshop_device_id)->first();
            if (! $device) {
                return;
            }
            $receptionId = DB::table('workshop_receptions')->insertGetId([
                'tenant_id' => $order->tenant_id,
                'workshop_customer_id' => $device->workshop_customer_id,
                'core_sucursal_id' => $order->core_sucursal_id,
                'core_sucursal_code' => $order->core_sucursal_code,
                'core_sucursal_name' => $order->core_sucursal_name,
                'received_by' => $order->received_by,
                'ticket_number' => $order->ticket_number,
                'received_at' => $order->received_at,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);
            DB::table('workshop_orders')->where('id', $order->id)->update(['workshop_reception_id' => $receptionId]);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workshop_orders ALTER COLUMN workshop_reception_id SET NOT NULL');
        }

        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->dropUnique('workshop_orders_tenant_id_ticket_number_unique');
            $table->unique(['workshop_reception_id', 'reception_sequence'], 'workshop_order_reception_sequence_unique');
            $table->index(['tenant_id', 'ticket_number']);
        });
    }

    public function down(): void
    {
        Schema::table('workshop_orders', function (Blueprint $table): void {
            $table->dropUnique('workshop_order_reception_sequence_unique');
            $table->dropIndex(['tenant_id', 'ticket_number']);
            $table->dropConstrainedForeignId('workshop_reception_id');
            $table->dropColumn('reception_sequence');
            $table->unique(['tenant_id', 'ticket_number']);
        });
        Schema::dropIfExists('workshop_receptions');
    }
};
