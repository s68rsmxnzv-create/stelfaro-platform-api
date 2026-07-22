<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cash_movements', 'sales_order_id')) {
            Schema::table('cash_movements', function (Blueprint $table): void {
                $table->dropIndex('cash_movements_sales_order_idx');
                $table->dropConstrainedForeignId('sales_order_id');
            });
        }
        Schema::dropIfExists('sales_order_payments');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');

        Schema::create('follow_up_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('core_customer_id')->nullable();
            $table->string('person_name', 160);
            $table->string('person_phone', 40)->nullable();
            $table->string('person_email', 160)->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 30)->default('other')->index();
            $table->date('occurred_on');
            $table->timestamp('remind_at')->nullable()->index();
            $table->timestamp('reminder_notified_at')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('resolution_type', 30)->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('resolution_reference', 160)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'remind_at'], 'follow_up_notes_tenant_status_reminder_idx');
            $table->index(['tenant_id', 'person_name'], 'follow_up_notes_tenant_person_idx');
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_notes');
    }
};
