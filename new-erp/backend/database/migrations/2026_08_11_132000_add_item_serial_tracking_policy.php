<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table) {
            $table->string('serial_tracking_mode', 20)->default('none')->after('is_serial_managed');
            $table->string('serial_number_prefix', 30)->nullable()->after('serial_tracking_mode');
            $table->index('serial_tracking_mode', 'erp_items_serial_tracking_mode_idx');
        });

        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $table->string('serial_number_source', 30)->nullable()->after('serial_text');
        });
    }

    public function down(): void
    {
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $table->dropColumn('serial_number_source');
        });

        Schema::table('erp_items', function (Blueprint $table) {
            $table->dropIndex('erp_items_serial_tracking_mode_idx');
            $table->dropColumn(['serial_tracking_mode', 'serial_number_prefix']);
        });
    }
};

