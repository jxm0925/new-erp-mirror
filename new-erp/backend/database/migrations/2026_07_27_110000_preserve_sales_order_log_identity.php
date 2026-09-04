<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_order_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_logs', 'order_no_snapshot')) {
                $table->string('order_no_snapshot', 80)->nullable()->after('sales_order_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_order_logs', function (Blueprint $table) {
            if (Schema::hasColumn('erp_sales_order_logs', 'order_no_snapshot')) {
                $table->dropColumn('order_no_snapshot');
            }
        });
    }
};
