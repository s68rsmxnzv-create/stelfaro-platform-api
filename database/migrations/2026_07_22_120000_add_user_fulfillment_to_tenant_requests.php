<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            $table->foreignId('fulfilled_user_id')->nullable()->after('assigned_to_user_id')->constrained('users')->nullOnDelete();
            $table->text('temporary_password')->nullable()->after('admin_response');
            $table->timestamp('credentials_available_at')->nullable()->after('temporary_password');
            $table->timestamp('credentials_revealed_at')->nullable()->after('credentials_available_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfilled_user_id');
            $table->dropColumn(['temporary_password', 'credentials_available_at', 'credentials_revealed_at']);
        });
    }
};
