<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_supplier_item_stats', function (Blueprint $table): void {
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_order_count')) $table->unsignedInteger('total_order_count')->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_order_amount')) $table->decimal('total_order_amount', 18, 4)->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_receipt_count')) $table->unsignedInteger('total_receipt_count')->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'on_time_receipt_count')) $table->unsignedInteger('on_time_receipt_count')->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_qualified_base_qty')) $table->decimal('total_qualified_base_qty', 18, 8)->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_unqualified_base_qty')) $table->decimal('total_unqualified_base_qty', 18, 8)->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'return_count')) $table->unsignedInteger('return_count')->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'total_return_base_qty')) $table->decimal('total_return_base_qty', 18, 8)->default(0);
            if (!Schema::hasColumn('erp_supplier_item_stats', 'on_time_rate')) $table->decimal('on_time_rate', 8, 4)->nullable();
            if (!Schema::hasColumn('erp_supplier_item_stats', 'qualified_rate')) $table->decimal('qualified_rate', 8, 4)->nullable();
            if (!Schema::hasColumn('erp_supplier_item_stats', 'return_rate')) $table->decimal('return_rate', 8, 4)->nullable();
            if (!Schema::hasColumn('erp_supplier_item_stats', 'last_return_at')) $table->timestamp('last_return_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_supplier_item_stats', function (Blueprint $table): void {
            $columns = [
                'total_order_count', 'total_order_amount', 'total_receipt_count', 'on_time_receipt_count',
                'total_qualified_base_qty', 'total_unqualified_base_qty', 'return_count', 'total_return_base_qty',
                'on_time_rate', 'qualified_rate', 'return_rate', 'last_return_at',
            ];
            $table->dropColumn(array_values(array_filter($columns, fn ($column) => Schema::hasColumn('erp_supplier_item_stats', $column))));
        });
    }
};
