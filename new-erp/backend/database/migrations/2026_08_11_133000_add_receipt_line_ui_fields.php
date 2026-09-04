<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'expected_arrival_date')) {
                $table->date('expected_arrival_date')->nullable()->after('batch_no');
            }
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'serial_entries')) {
                $table->json('serial_entries')->nullable()->after('serial_text');
            }
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'tax_rate')) {
                $table->decimal('tax_rate', 8, 4)->default(13)->after('unit_price');
            }
        });

        Schema::table('erp_purchase_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipts', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 4)->default(0)->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $columns = array_values(array_filter(['expected_arrival_date', 'serial_entries', 'tax_rate'], fn ($column) => Schema::hasColumn('erp_purchase_receipt_items', $column)));
            if ($columns) $table->dropColumn($columns);
        });
        Schema::table('erp_purchase_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_receipts', 'tax_amount')) $table->dropColumn('tax_amount');
        });
    }
};
