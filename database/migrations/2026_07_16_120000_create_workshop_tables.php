<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_customer_id');
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'core_customer_id']);
        });

        Schema::create('workshop_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('brand', 80);
            $table->string('model', 120);
            $table->string('color', 60)->nullable();
            $table->string('imei', 15)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'imei']);
            $table->index(['tenant_id', 'serial_number']);
        });

        Schema::create('workshop_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('ticket_number');
            $table->string('status', 30)->default('received')->index();
            $table->string('priority', 20)->default('normal');
            $table->text('reported_fault');
            $table->text('physical_condition')->nullable();
            $table->json('accessories')->nullable();
            $table->text('diagnosis')->nullable();
            $table->decimal('estimated_total', 12, 2)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'ticket_number']);
            $table->index(['tenant_id', 'status', 'received_at']);
        });

        Schema::create('workshop_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workshop_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'workshop_order_id', 'voided_at'], 'workshop_payments_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_order_payments');
        Schema::dropIfExists('workshop_orders');
        Schema::dropIfExists('workshop_devices');
        Schema::dropIfExists('workshop_customers');
    }
};
