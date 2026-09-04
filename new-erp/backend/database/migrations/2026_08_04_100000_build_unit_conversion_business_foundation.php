<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_units', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_units', 'symbol')) $table->string('symbol', 20)->nullable()->after('unit_name');
            if (!Schema::hasColumn('erp_units', 'allow_decimal')) $table->boolean('allow_decimal')->default(false)->after('unit_type');
            if (!Schema::hasColumn('erp_units', 'sort_order')) $table->unsignedInteger('sort_order')->default(0)->after('decimal_places')->index();
        });

        if (!Schema::hasTable('erp_item_purchase_conversions')) {
            Schema::create('erp_item_purchase_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('erp_items')->cascadeOnDelete();
                $table->foreignId('purchase_unit_id')->constrained('erp_units')->restrictOnDelete();
                $table->foreignId('base_unit_id')->constrained('erp_units')->restrictOnDelete();
                $table->decimal('factor', 18, 8);
                $table->boolean('is_default')->default(false);
                $table->boolean('allow_actual_conversion')->default(false);
                $table->timestamp('effective_from')->nullable();
                $table->timestamp('effective_to')->nullable();
                $table->string('status', 20)->default('active');
                $table->string('change_reason', 80);
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->string('operator_name', 80)->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();
                $table->index(['item_id', 'status'], 'erp_item_purchase_conversion_item_status');
                $table->index(['item_id', 'purchase_unit_id', 'effective_from'], 'erp_item_purchase_conversion_period');
            });
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE erp_item_purchase_conversions ADD COLUMN active_default_item_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' AND is_default = 1 THEN item_id ELSE NULL END) VIRTUAL");
                DB::statement('CREATE UNIQUE INDEX erp_item_purchase_one_active_default ON erp_item_purchase_conversions (active_default_item_id)');
            }
        }

        Schema::table('erp_item_supplier_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_item_supplier_prices', 'standard_conversion_factor')) $table->decimal('standard_conversion_factor', 18, 8)->default(1)->after('unit_id');
            if (!Schema::hasColumn('erp_item_supplier_prices', 'final_conversion_factor')) $table->decimal('final_conversion_factor', 18, 8)->default(1)->after('standard_conversion_factor');
            if (!Schema::hasColumn('erp_item_supplier_prices', 'factor_source')) $table->string('factor_source', 30)->default('item_standard')->after('final_conversion_factor');
            if (!Schema::hasColumn('erp_item_supplier_prices', 'supplier_factor_reason')) $table->string('supplier_factor_reason', 255)->nullable()->after('factor_source');
            if (!Schema::hasColumn('erp_item_supplier_prices', 'base_unit_price')) $table->decimal('base_unit_price', 18, 8)->default(0)->after('price');
        });

        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_order_items', 'purchase_unit_id')) $table->foreignId('purchase_unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'purchase_unit_name_snapshot')) $table->string('purchase_unit_name_snapshot', 80)->nullable()->after('purchase_unit_id');
            if (!Schema::hasColumn('erp_purchase_order_items', 'conversion_factor_snapshot')) $table->decimal('conversion_factor_snapshot', 18, 8)->default(1)->after('purchase_unit_name_snapshot');
            if (!Schema::hasColumn('erp_purchase_order_items', 'base_unit_id')) $table->foreignId('base_unit_id')->nullable()->after('conversion_factor_snapshot')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'base_unit_name_snapshot')) $table->string('base_unit_name_snapshot', 80)->nullable()->after('base_unit_id');
            if (!Schema::hasColumn('erp_purchase_order_items', 'purchase_qty')) $table->decimal('purchase_qty', 18, 8)->default(0)->after('supplier_id');
            if (!Schema::hasColumn('erp_purchase_order_items', 'planned_base_qty')) $table->decimal('planned_base_qty', 18, 8)->default(0)->after('purchase_qty');
            if (!Schema::hasColumn('erp_purchase_order_items', 'purchase_unit_price')) $table->decimal('purchase_unit_price', 18, 8)->default(0)->after('unit_price');
            if (!Schema::hasColumn('erp_purchase_order_items', 'base_unit_price')) $table->decimal('base_unit_price', 18, 8)->default(0)->after('purchase_unit_price');
        });

        Schema::table('erp_purchase_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'purchase_unit_id')) $table->foreignId('purchase_unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'purchase_unit_name_snapshot')) $table->string('purchase_unit_name_snapshot', 80)->nullable()->after('purchase_unit_id');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'conversion_factor_snapshot')) $table->decimal('conversion_factor_snapshot', 18, 8)->default(1)->after('purchase_unit_name_snapshot');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'base_unit_id')) $table->foreignId('base_unit_id')->nullable()->after('conversion_factor_snapshot')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'base_unit_name_snapshot')) $table->string('base_unit_name_snapshot', 80)->nullable()->after('base_unit_id');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'standard_base_qty')) $table->decimal('standard_base_qty', 18, 8)->default(0)->after('unqualified_qty');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'actual_base_qty')) $table->decimal('actual_base_qty', 18, 8)->nullable()->after('standard_base_qty');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'difference_qty')) $table->decimal('difference_qty', 18, 8)->default(0)->after('actual_base_qty');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'difference_reason')) $table->string('difference_reason', 255)->nullable()->after('difference_qty');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'allow_actual_conversion')) $table->boolean('allow_actual_conversion')->default(false)->after('difference_reason');
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'inventory_posting_status')) $table->string('inventory_posting_status', 30)->default('pending')->after('allow_actual_conversion')->index();
            if (!Schema::hasColumn('erp_purchase_receipt_items', 'inventory_posting_log_id')) $table->foreignId('inventory_posting_log_id')->nullable()->after('inventory_posting_status')->constrained('erp_inventory_posting_logs')->nullOnDelete();
        });

        Schema::table('erp_sales_order_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_base_unit_id')) $table->foreignId('item_base_unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_base_unit_name_snapshot')) $table->string('item_base_unit_name_snapshot', 80)->nullable()->after('item_base_unit_id');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_base_unit_code_snapshot')) $table->string('item_base_unit_code_snapshot', 40)->nullable()->after('item_base_unit_name_snapshot');
            if (!Schema::hasColumn('erp_sales_order_lines', 'fulfillment_factor_snapshot')) $table->decimal('fulfillment_factor_snapshot', 18, 8)->nullable()->after('item_base_unit_code_snapshot');
            if (!Schema::hasColumn('erp_sales_order_lines', 'item_base_required_qty')) $table->decimal('item_base_required_qty', 18, 8)->default(0)->after('fulfillment_factor_snapshot');
        });

        Schema::table('erp_sales_order_fulfillments', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'base_unit_id')) $table->foreignId('base_unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'base_unit_name_snapshot')) $table->string('base_unit_name_snapshot', 80)->nullable()->after('base_unit_id');
            if (!Schema::hasColumn('erp_sales_order_fulfillments', 'demand_status')) $table->string('demand_status', 30)->default('confirmed')->after('production_requirement_status');
        });

        Schema::table('erp_sales_order_production_requirements', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_sales_order_production_requirements', 'base_unit_id')) $table->foreignId('base_unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_sales_order_production_requirements', 'base_unit_name_snapshot')) $table->string('base_unit_name_snapshot', 80)->nullable()->after('base_unit_id');
            if (!Schema::hasColumn('erp_sales_order_production_requirements', 'item_base_required_qty')) $table->decimal('item_base_required_qty', 18, 8)->default(0)->after('production_qty');
        });

        if (Schema::hasColumn('erp_sku_item_relations', 'qty')) {
            Schema::table('erp_sku_item_relations', fn (Blueprint $table) => $table->decimal('qty', 18, 8)->default(1)->change());
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_purchase_conversions');

        $this->dropColumns('erp_sales_order_production_requirements', ['base_unit_id', 'base_unit_name_snapshot', 'item_base_required_qty']);
        $this->dropColumns('erp_sales_order_fulfillments', ['base_unit_id', 'base_unit_name_snapshot', 'demand_status']);
        $this->dropColumns('erp_sales_order_lines', ['item_base_unit_id', 'item_base_unit_name_snapshot', 'item_base_unit_code_snapshot', 'fulfillment_factor_snapshot', 'item_base_required_qty']);
        $this->dropColumns('erp_purchase_receipt_items', ['purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot', 'base_unit_id', 'base_unit_name_snapshot', 'standard_base_qty', 'actual_base_qty', 'difference_qty', 'difference_reason', 'allow_actual_conversion', 'inventory_posting_status', 'inventory_posting_log_id']);
        $this->dropColumns('erp_purchase_order_items', ['purchase_unit_id', 'purchase_unit_name_snapshot', 'conversion_factor_snapshot', 'base_unit_id', 'base_unit_name_snapshot', 'purchase_qty', 'planned_base_qty', 'purchase_unit_price', 'base_unit_price']);
        $this->dropColumns('erp_item_supplier_prices', ['standard_conversion_factor', 'final_conversion_factor', 'factor_source', 'supplier_factor_reason', 'base_unit_price']);
        $this->dropColumns('erp_units', ['symbol', 'allow_decimal', 'sort_order']);
    }

    private function dropColumns(string $tableName, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($tableName, $column)));
        if (!$existing) return;
        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
    }
};

