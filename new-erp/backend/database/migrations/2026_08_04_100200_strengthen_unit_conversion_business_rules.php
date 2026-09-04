<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_units', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_units', 'is_legacy')) {
                $table->boolean('is_legacy')->default(false)->after('is_base')->index();
            }
            if (!Schema::hasColumn('erp_units', 'standard_unit_id')) {
                $table->foreignId('standard_unit_id')->nullable()->after('is_legacy')
                    ->constrained('erp_units')->nullOnDelete();
            }
        });

        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'qualified_base_qty')) {
                $table->decimal('qualified_base_qty', 18, 8)->default(0)->after('actual_base_qty');
            }
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'unqualified_base_qty')) {
                $table->decimal('unqualified_base_qty', 18, 8)->default(0)->after('qualified_base_qty');
            }
        });

        Schema::table('erp_sales_order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_lines', 'undetermined_qty')) {
                $table->decimal('undetermined_qty', 18, 8)->default(0)->after('no_delivery_qty');
            }
        });

        Schema::table('erp_purchase_price_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_price_histories', 'base_unit_id')) {
                $table->foreignId('base_unit_id')->nullable()->after('unit_id')->constrained('erp_units')->nullOnDelete();
            }
            if (!Schema::hasColumn('erp_purchase_price_histories', 'conversion_factor_snapshot')) {
                $table->decimal('conversion_factor_snapshot', 18, 8)->default(1)->after('base_unit_id');
            }
            if (!Schema::hasColumn('erp_purchase_price_histories', 'base_unit_price')) {
                $table->decimal('base_unit_price', 18, 8)->default(0)->after('price');
            }
        });

    }

    public function down(): void
    {
        Schema::table('erp_purchase_price_histories', function (Blueprint $table) {
            if (Schema::hasColumn('erp_purchase_price_histories', 'base_unit_id')) $table->dropForeign(['base_unit_id']);
            $columns = array_values(array_filter(['base_unit_id', 'conversion_factor_snapshot', 'base_unit_price'],
                fn (string $column) => Schema::hasColumn('erp_purchase_price_histories', $column)));
            if ($columns) $table->dropColumn($columns);
        });
        Schema::table('erp_sales_order_lines', function (Blueprint $table) {
            if (Schema::hasColumn('erp_sales_order_lines', 'undetermined_qty')) $table->dropColumn('undetermined_qty');
        });
        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            $columns = array_values(array_filter(['qualified_base_qty', 'unqualified_base_qty'],
                fn (string $column) => Schema::hasColumn('erp_purchase_receipt_items', $column)));
            if ($columns) $table->dropColumn($columns);
        });
        Schema::table('erp_units', function (Blueprint $table) {
            if (Schema::hasColumn('erp_units', 'standard_unit_id')) {
                $table->dropForeign(['standard_unit_id']);
                $table->dropColumn('standard_unit_id');
            }
            if (Schema::hasColumn('erp_units', 'is_legacy')) $table->dropColumn('is_legacy');
        });
    }

};

