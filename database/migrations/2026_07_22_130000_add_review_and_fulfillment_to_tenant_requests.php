<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            $table->json('reviewed_payload')->nullable()->after('payload');
            $table->string('fulfilled_resource_type', 60)->nullable()->after('fulfilled_user_id');
            $table->string('fulfilled_resource_id', 120)->nullable()->after('fulfilled_resource_type');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            $table->dropColumn(['reviewed_payload', 'fulfilled_resource_type', 'fulfilled_resource_id']);
        });
    }
};
