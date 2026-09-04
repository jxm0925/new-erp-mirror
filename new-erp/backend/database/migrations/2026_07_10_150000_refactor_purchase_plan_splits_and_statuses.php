<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_purchase_plan_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_plan_items', 'unit_id')) $table->foreignId('unit_id')->nullable()->after('item_id')->constrained('erp_units')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_items', 'required_qty')) $table->decimal('required_qty', 14, 4)->default(0)->after('unit_id');
            if (!Schema::hasColumn('erp_purchase_plan_items', 'allocated_qty')) $table->decimal('allocated_qty', 14, 4)->default(0)->after('required_qty');
            if (!Schema::hasColumn('erp_purchase_plan_items', 'remaining_qty')) $table->decimal('remaining_qty', 14, 4)->default(0)->after('allocated_qty');
            if (!Schema::hasColumn('erp_purchase_plan_items', 'warehouse_id')) $table->foreignId('warehouse_id')->nullable()->after('remaining_qty')->constrained('erp_warehouses')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_items', 'expected_date')) $table->date('expected_date')->nullable()->after('warehouse_id');
            if (!Schema::hasColumn('erp_purchase_plan_items', 'status')) $table->string('status', 30)->default('unallocated')->after('expected_date');
        });

        if (!Schema::hasTable('erp_purchase_plan_supplier_splits')) {
            Schema::create('erp_purchase_plan_supplier_splits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('erp_purchase_plans')->cascadeOnDelete();
                $table->foreignId('plan_item_id')->constrained('erp_purchase_plan_items')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
                $table->decimal('purchase_qty', 14, 4);
                $table->decimal('ordered_qty', 14, 4)->default(0);
                $table->decimal('unit_price', 14, 4)->default(0);
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->unsignedInteger('delivery_days')->default(0);
                $table->decimal('moq_qty', 14, 4)->default(0);
                $table->date('expected_date')->nullable();
                $table->decimal('amount', 14, 4)->default(0);
                $table->string('status', 30)->default('draft');
                $table->text('remark')->nullable();
                $table->string('legacy_system', 40)->nullable();
                $table->string('legacy_table', 64)->nullable();
                $table->string('legacy_id', 64)->nullable();
                $table->string('legacy_code', 120)->nullable();
                $table->string('legacy_status', 80)->nullable();
                $table->string('data_source', 30)->default('manual');
                $table->string('sync_batch_no', 80)->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_order_items', 'plan_split_id')) $table->foreignId('plan_split_id')->nullable()->after('plan_item_id')->constrained('erp_purchase_plan_supplier_splits')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'target_warehouse_id')) $table->foreignId('target_warehouse_id')->nullable()->after('expected_arrival_date')->constrained('erp_warehouses')->nullOnDelete();
        });

        Schema::table('erp_purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_orders', 'expected_arrival_date')) $table->date('expected_arrival_date')->nullable()->after('order_date');
            if (!Schema::hasColumn('erp_purchase_orders', 'currency')) $table->string('currency', 20)->default('CNY')->after('expected_arrival_date');
            if (!Schema::hasColumn('erp_purchase_orders', 'tax_mode')) $table->string('tax_mode', 30)->default('tax_included')->after('currency');
            if (!Schema::hasColumn('erp_purchase_orders', 'settlement_method')) $table->string('settlement_method', 80)->nullable()->after('tax_mode');
            if (!Schema::hasColumn('erp_purchase_orders', 'delivery_method')) $table->string('delivery_method', 80)->nullable()->after('settlement_method');
            if (!Schema::hasColumn('erp_purchase_orders', 'freight_amount')) $table->decimal('freight_amount', 14, 4)->default(0)->after('tax_amount');
        });

        Schema::table('erp_purchase_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_receipts', 'confirm_status')) $table->string('confirm_status', 30)->default('draft')->after('receipt_status');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_plan_supplier_splits');
    }
};

