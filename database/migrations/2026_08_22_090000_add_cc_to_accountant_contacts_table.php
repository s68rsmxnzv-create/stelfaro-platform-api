<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accountant_contacts', function (Blueprint $table): void {
            $table->json('cc')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('accountant_contacts', function (Blueprint $table): void {
            $table->dropColumn('cc');
        });
    }
};
