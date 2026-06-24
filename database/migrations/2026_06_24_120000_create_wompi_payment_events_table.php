<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wompi_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('payment_attempt_id')->nullable()->index();
            $table->string('payment_link_id')->nullable()->index();
            $table->string('commerce_identifier')->nullable()->index();
            $table->string('customer_email')->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('result')->nullable()->index();
            $table->boolean('is_productive')->nullable();
            $table->boolean('hash_valid')->default(false)->index();
            $table->string('status', 40)->default('received')->index();
            $table->json('raw_payload');
            $table->json('headers')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wompi_payment_events');
    }
};
