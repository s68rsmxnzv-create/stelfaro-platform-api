<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('total');
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->uuid('public_token')->nullable()->unique()->after('idempotency_key');
            $table->unsignedInteger('version')->default(1)->after('quotation_number');
            $table->string('approval_method', 30)->nullable()->after('status');
            $table->text('approval_note')->nullable()->after('approval_method');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
        Schema::create('sales_order_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['sales_order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_status_events');
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'version', 'approval_method', 'approval_note']);
        });
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('due_at'));
    }
};
