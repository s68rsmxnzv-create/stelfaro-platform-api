<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->string('operation_kind', 40)->default('sale')->after('sale_date')->index();
            $table->string('fiscal_document_type', 4)->nullable()->after('operation_kind')->index();
            $table->smallInteger('reporting_sign')->default(1)->after('fiscal_document_type');
            $table->decimal('net_amount', 14, 2)->default(0)->after('reporting_sign');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('net_amount');
            $table->decimal('total_amount', 14, 2)->default(0)->after('tax_amount');
        });

        Schema::table('inventory_sale_lines', function (Blueprint $table): void {
            $table->decimal('tax_amount', 14, 2)->default(0)->after('net_total');
            $table->decimal('total_amount', 14, 2)->default(0)->after('tax_amount');
        });

        DB::table('inventory_sales')->orderBy('id')->chunkById(200, function ($sales): void {
            foreach ($sales as $sale) {
                $metadata = json_decode((string) ($sale->metadata ?? '{}'), true);
                $documentType = is_array($metadata) ? str_pad((string) ($metadata['document_type'] ?? ''), 2, '0', STR_PAD_LEFT) : '';
                $kind = match ($documentType) {
                    '05' => 'credit_note',
                    '06' => 'debit_note',
                    '14' => 'excluded_subject_purchase',
                    default => 'sale',
                };
                $sign = $documentType === '05' ? -1 : ($documentType === '14' ? 0 : 1);
                $lineTotal = (float) DB::table('inventory_sale_lines')->where('inventory_sale_id', $sale->id)->sum('net_total');
                $metadataTotal = is_array($metadata) && is_numeric($metadata['total'] ?? null) ? (float) $metadata['total'] : null;
                $total = round($metadataTotal ?? $lineTotal, 2);

                DB::table('inventory_sales')->where('id', $sale->id)->update([
                    'operation_kind' => $kind,
                    'fiscal_document_type' => $documentType !== '' ? $documentType : null,
                    'reporting_sign' => $sign,
                    'net_amount' => $total,
                    'tax_amount' => 0,
                    'total_amount' => $total,
                ]);
                DB::table('inventory_sale_lines')->where('inventory_sale_id', $sale->id)->update([
                    'total_amount' => DB::raw('net_total'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sale_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'total_amount']);
        });
        Schema::table('inventory_sales', function (Blueprint $table): void {
            $table->dropIndex(['operation_kind']);
            $table->dropIndex(['fiscal_document_type']);
            $table->dropColumn(['operation_kind', 'fiscal_document_type', 'reporting_sign', 'net_amount', 'tax_amount', 'total_amount']);
        });
    }
};
