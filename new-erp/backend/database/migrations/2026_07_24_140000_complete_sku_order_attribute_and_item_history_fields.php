<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_skus', 'sales_unit_id')) $table->foreignId('sales_unit_id')->nullable()->after('product_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_skus', 'sales_unit_snapshot')) $table->string('sales_unit_snapshot', 80)->nullable()->after('sales_unit_id');
            if (!Schema::hasColumn('erp_skus', 'electric_mode')) $table->string('electric_mode', 20)->default('hidden')->after('electric_options');
            if (!Schema::hasColumn('erp_skus', 'need_pump_mode')) $table->string('need_pump_mode', 20)->default('hidden')->after('need_pump_required');
        });

        Schema::table('erp_sku_item_relations', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sku_item_relations', 'effective_at')) $table->timestamp('effective_at')->nullable()->after('status');
            if (!Schema::hasColumn('erp_sku_item_relations', 'expired_at')) $table->timestamp('expired_at')->nullable()->after('effective_at');
            if (!Schema::hasColumn('erp_sku_item_relations', 'operator_name')) $table->string('operator_name', 80)->nullable()->after('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('erp_sku_item_relations', function (Blueprint $table) {
            $columns = array_values(array_filter(['operator_name', 'expired_at', 'effective_at'], fn ($column) => Schema::hasColumn('erp_sku_item_relations', $column)));
            if ($columns) $table->dropColumn($columns);
        });
        Schema::table('erp_skus', function (Blueprint $table) {
            $columns = array_values(array_filter(['need_pump_mode', 'electric_mode', 'sales_unit_snapshot', 'sales_unit_id'], fn ($column) => Schema::hasColumn('erp_skus', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};

