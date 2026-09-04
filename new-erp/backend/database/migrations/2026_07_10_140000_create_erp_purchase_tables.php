<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_import_rows', function (Blueprint $table) {
            if (!Schema::hasColumn('erp_import_rows', 'source_table')) $table->string('source_table', 80)->nullable()->after('row_no');
            if (!Schema::hasColumn('erp_import_rows', 'source_id')) $table->string('source_id', 80)->nullable()->after('source_table');
            if (!Schema::hasColumn('erp_import_rows', 'error_message')) $table->text('error_message')->nullable()->after('error_reason');
        });

        Schema::create('erp_item_supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->decimal('min_order_qty', 14, 4)->default(0);
            $table->string('status', 20)->default('enabled');
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
            $table->unique(['item_id', 'supplier_id'], 'erp_item_supplier_price_unique');
        });

        Schema::create('erp_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 80)->unique();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->decimal('request_qty', 14, 4);
            $table->decimal('planned_qty', 14, 4)->default(0);
            $table->date('required_date')->nullable();
            $table->string('source_type', 30)->default('manual');
            $table->string('request_status', 30)->default('draft');
            $table->string('priority', 20)->default('normal');
            $table->string('requester', 80)->nullable();
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_no', 80)->unique();
            $table->date('plan_date')->nullable();
            $table->string('plan_status', 30)->default('draft');
            $table->string('audit_status', 30)->default('pending');
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('erp_purchase_plans')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('erp_purchase_requests')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('erp_suppliers')->restrictOnDelete();
            $table->decimal('plan_qty', 14, 4);
            $table->decimal('ordered_qty', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->date('delivery_date')->nullable();
            $table->date('expected_arrival_date')->nullable();
            $table->string('line_status', 30)->default('draft');
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_order_no', 80)->unique();
            $table->foreignId('plan_id')->nullable()->constrained('erp_purchase_plans')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->date('order_date')->nullable();
            $table->string('purchase_status', 30)->default('draft');
            $table->string('audit_status', 30)->default('pending');
            $table->string('receipt_status', 30)->default('not_received');
            $table->decimal('total_qty', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('erp_purchase_orders')->cascadeOnDelete();
            $table->foreignId('plan_item_id')->nullable()->constrained('erp_purchase_plan_items')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->decimal('order_qty', 14, 4);
            $table->decimal('received_qty', 14, 4)->default(0);
            $table->decimal('remaining_qty', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('receipt_cost', 14, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);
            $table->date('expected_arrival_date')->nullable();
            $table->string('line_status', 30)->default('open');
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->foreignId('order_id')->nullable()->constrained('erp_purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->date('receipt_date')->nullable();
            $table->string('receipt_status', 30)->default('draft');
            $table->string('stock_post_status', 30)->default('pending');
            $table->decimal('total_receipt_qty', 14, 4)->default(0);
            $table->decimal('total_qualified_qty', 14, 4)->default(0);
            $table->decimal('total_unqualified_qty', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('erp_purchase_receipts')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('erp_purchase_order_items')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->decimal('receipt_qty', 14, 4);
            $table->decimal('qualified_qty', 14, 4)->default(0);
            $table->decimal('unqualified_qty', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('receipt_cost', 14, 4)->default(0);
            $table->string('batch_no', 80)->nullable();
            $table->text('serial_text')->nullable();
            $table->text('remark')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_purchase_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('erp_purchase_orders')->nullOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('erp_purchase_receipts')->nullOnDelete();
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('receipt_cost', 14, 4)->default(0);
            $table->date('effective_date')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
        });

        Schema::create('erp_supplier_item_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->decimal('total_order_qty', 14, 4)->default(0);
            $table->decimal('total_received_qty', 14, 4)->default(0);
            $table->decimal('last_price', 14, 4)->default(0);
            $table->decimal('avg_price', 14, 4)->default(0);
            $table->timestamp('last_receipt_at')->nullable();
            $this->syncColumns($table);
            $table->timestamps();
            $table->unique(['supplier_id', 'item_id'], 'erp_supplier_item_stats_unique');
        });

        Schema::create('erp_purchase_logs', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->string('action', 60);
            $table->string('operator', 80)->nullable();
            $table->text('content')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'erp_purchase_logs',
            'erp_supplier_item_stats',
            'erp_purchase_price_histories',
            'erp_purchase_receipt_items',
            'erp_purchase_receipts',
            'erp_purchase_order_items',
            'erp_purchase_orders',
            'erp_purchase_plan_items',
            'erp_purchase_plans',
            'erp_purchase_requests',
            'erp_item_supplier_prices',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('erp_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('erp_import_rows', 'error_message')) $table->dropColumn('error_message');
            if (Schema::hasColumn('erp_import_rows', 'source_id')) $table->dropColumn('source_id');
            if (Schema::hasColumn('erp_import_rows', 'source_table')) $table->dropColumn('source_table');
        });
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
        $table->unique(['legacy_system', 'legacy_table', 'legacy_id'], $table->getTable() . '_legacy_unique');
    }
};
