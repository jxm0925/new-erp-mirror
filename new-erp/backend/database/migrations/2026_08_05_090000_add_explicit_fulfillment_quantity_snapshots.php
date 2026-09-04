<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_order_fulfillments', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'sales_qty')) {
                $table->decimal('sales_qty', 18, 8)->nullable()->after('fulfillment_qty');
            }
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'sales_unit_id')) {
                $table->unsignedBigInteger('sales_unit_id')->nullable()->after('sales_qty');
            }
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'sales_unit_code_snapshot')) {
                $table->string('sales_unit_code_snapshot', 40)->nullable()->after('sales_unit_id');
            }
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'sales_unit_name_snapshot')) {
                $table->string('sales_unit_name_snapshot', 80)->nullable()->after('sales_unit_code_snapshot');
            }
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'fulfillment_factor_snapshot')) {
                $table->decimal('fulfillment_factor_snapshot', 18, 8)->nullable()->after('sales_unit_name_snapshot');
            }
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'item_base_qty')) {
                $table->decimal('item_base_qty', 18, 8)->nullable()->after('fulfillment_factor_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_sales_order_fulfillments', function (Blueprint $table) {
            foreach (['item_base_qty', 'fulfillment_factor_snapshot', 'sales_unit_name_snapshot', 'sales_unit_code_snapshot', 'sales_unit_id', 'sales_qty'] as $column) {
                if (Schema::hasColumn('erp_sales_order_fulfillments', $column)) $table->dropColumn($column);
            }
        });
    }
};
