<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('erp_purchase_request_items')) {
            Schema::create('erp_purchase_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('erp_purchase_requests')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
                $table->string('item_code', 80)->nullable();
                $table->string('item_name', 160)->nullable();
                $table->foreignId('unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
                $table->decimal('request_qty', 14, 4);
                $table->decimal('converted_qty', 14, 4)->default(0);
                $table->decimal('remaining_qty', 14, 4)->default(0);
                $table->date('expected_date')->nullable();
                $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->nullOnDelete();
                $table->string('priority', 20)->default('normal');
                $table->string('line_status', 30)->default('open');
                $table->text('remark')->nullable();
                $this->syncColumns($table);
                $table->timestamps();
            });
        }

        Schema::table('erp_purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_requests', 'request_date')) $table->date('request_date')->nullable()->after('request_no');
            if (!Schema::hasColumn('erp_purchase_requests', 'status')) $table->string('status', 30)->nullable()->after('source_type');
            if (!Schema::hasColumn('erp_purchase_requests', 'source_id')) $table->string('source_id', 80)->nullable()->after('source_type');
            if (!Schema::hasColumn('erp_purchase_requests', 'source_no')) $table->string('source_no', 80)->nullable()->after('source_id');
            if (!Schema::hasColumn('erp_purchase_requests', 'created_by')) $table->string('created_by', 80)->nullable()->after('status');
            if (!Schema::hasColumn('erp_purchase_requests', 'confirmed_by')) $table->string('confirmed_by', 80)->nullable()->after('created_by');
            if (!Schema::hasColumn('erp_purchase_requests', 'confirmed_at')) $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            if (!Schema::hasColumn('erp_purchase_requests', 'closed_at')) $table->timestamp('closed_at')->nullable()->after('confirmed_at');
            if (!Schema::hasColumn('erp_purchase_requests', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable()->after('closed_at');
        });

        Schema::table('erp_purchase_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_plans', 'order_status')) $table->string('order_status', 30)->default('not_ordered')->after('audit_status');
            if (!Schema::hasColumn('erp_purchase_plans', 'created_by')) $table->string('created_by', 80)->nullable()->after('order_status');
            if (!Schema::hasColumn('erp_purchase_plans', 'approved_by')) $table->string('approved_by', 80)->nullable()->after('created_by');
            if (!Schema::hasColumn('erp_purchase_plans', 'approved_at')) $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::table('erp_purchase_plan_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_plan_items', 'request_item_id')) $table->foreignId('request_item_id')->nullable()->after('request_id')->constrained('erp_purchase_request_items')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_items', 'line_status')) $table->string('line_status', 30)->default('open')->after('status');
        });

        Schema::table('erp_purchase_plan_supplier_splits', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_plan_supplier_splits', 'request_id')) $table->foreignId('request_id')->nullable()->after('plan_item_id')->constrained('erp_purchase_requests')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_supplier_splits', 'request_item_id')) $table->foreignId('request_item_id')->nullable()->after('request_id')->constrained('erp_purchase_request_items')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_supplier_splits', 'order_id')) $table->foreignId('order_id')->nullable()->after('amount')->constrained('erp_purchase_orders')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_supplier_splits', 'order_item_id')) $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained('erp_purchase_order_items')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_plan_supplier_splits', 'split_status')) $table->string('split_status', 30)->default('not_ordered')->after('order_item_id');
        });

        Schema::table('erp_purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_orders', 'source_type')) $table->string('source_type', 40)->nullable()->after('plan_id');
            if (!Schema::hasColumn('erp_purchase_orders', 'source_no')) $table->string('source_no', 80)->nullable()->after('source_type');
        });

        Schema::table('erp_purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_purchase_order_items', 'supplier_split_id')) $table->foreignId('supplier_split_id')->nullable()->after('plan_split_id')->constrained('erp_purchase_plan_supplier_splits')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'request_id')) $table->foreignId('request_id')->nullable()->after('supplier_split_id')->constrained('erp_purchase_requests')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'request_item_id')) $table->foreignId('request_item_id')->nullable()->after('request_id')->constrained('erp_purchase_request_items')->nullOnDelete();
            if (!Schema::hasColumn('erp_purchase_order_items', 'supplier_id')) $table->foreignId('supplier_id')->nullable()->after('item_id')->constrained('erp_suppliers')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('erp_purchase_request_items');
    }

    private function syncColumns(Blueprint $table): void
    {
        $table->string('legacy_system', 40)->nullable();
        $table->string('legacy_table', 64)->nullable();
        $table->string('legacy_id', 64)->nullable();
        $table->string('legacy_code', 120)->nullable();
        $table->string('legacy_status', 80)->nullable();
        $table->string('data_source', 30)->default('manual');
        $table->string('sync_batch_no', 80)->nullable();
        $table->timestamp('last_synced_at')->nullable();
    }
};

