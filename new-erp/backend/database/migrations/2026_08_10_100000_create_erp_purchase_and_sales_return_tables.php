<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no', 80)->unique();
            $table->string('return_scope', 40);
            $table->foreignId('source_receipt_id')->constrained('erp_purchase_receipts')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('erp_suppliers')->restrictOnDelete();
            $table->date('return_date');
            $table->string('return_status', 30)->default('draft');
            $table->string('audit_status', 30)->default('pending');
            $table->string('stock_post_status', 30)->default('not_required');
            $table->string('return_reason', 160);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['return_status', 'audit_status'], 'erp_pr_status_audit_idx');
            $table->index(['source_receipt_id', 'return_scope'], 'erp_pr_receipt_scope_idx');
        });

        Schema::create('erp_purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_id')->constrained('erp_purchase_returns')->cascadeOnDelete();
            $table->foreignId('source_receipt_item_id')->constrained('erp_purchase_receipt_items')->restrictOnDelete();
            $table->foreignId('source_defect_handling_id')->nullable()->constrained('erp_purchase_defect_handlings')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80)->nullable();
            $table->foreignId('base_unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('requested_base_qty', 18, 8);
            $table->decimal('approved_base_qty', 18, 8)->default(0);
            $table->decimal('posted_base_qty', 18, 8)->default(0);
            $table->decimal('unit_cost_snapshot', 18, 8)->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['source_receipt_item_id', 'batch_no'], 'erp_pri_source_batch_idx');
        });

        Schema::create('erp_purchase_return_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_id')->constrained('erp_purchase_returns')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no', 80)->unique();
            $table->foreignId('sales_order_id')->constrained('erp_sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('erp_sales_customers')->nullOnDelete();
            $table->date('return_date');
            $table->string('return_status', 30)->default('draft');
            $table->string('return_reason', 160);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'return_status'], 'erp_sr_order_status_idx');
        });

        Schema::create('erp_sales_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('erp_sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('erp_sales_order_lines')->restrictOnDelete();
            $table->foreignId('fulfillment_id')->nullable()->constrained('erp_sales_order_fulfillments')->nullOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('base_unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('requested_sales_qty', 18, 8);
            $table->decimal('requested_base_qty', 18, 8);
            $table->decimal('received_base_qty', 18, 8)->default(0);
            $table->decimal('restock_base_qty', 18, 8)->default(0);
            $table->decimal('pending_base_qty', 18, 8)->default(0);
            $table->decimal('scrap_base_qty', 18, 8)->default(0);
            $table->decimal('rejected_base_qty', 18, 8)->default(0);
            $table->json('fulfillment_snapshot');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['sales_order_line_id', 'item_id'], 'erp_sri_line_item_idx');
        });

        Schema::create('erp_sales_return_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->foreignId('sales_return_id')->constrained('erp_sales_returns')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('receipt_status', 30)->default('draft');
            $table->string('stock_post_status', 30)->default('pending');
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['sales_return_id', 'receipt_status'], 'erp_srr_return_status_idx');
        });

        Schema::create('erp_sales_return_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('erp_sales_return_receipts')->cascadeOnDelete();
            $table->foreignId('sales_return_item_id')->constrained('erp_sales_return_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('erp_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('erp_locations')->restrictOnDelete();
            $table->string('batch_no', 80)->nullable();
            $table->foreignId('base_unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('received_base_qty', 18, 8);
            $table->decimal('restock_base_qty', 18, 8)->default(0);
            $table->decimal('pending_base_qty', 18, 8)->default(0);
            $table->decimal('scrap_base_qty', 18, 8)->default(0);
            $table->decimal('rejected_base_qty', 18, 8)->default(0);
            $table->text('inspection_remark')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_sales_return_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('erp_sales_returns')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 80)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        foreach ([
            'erp_sales_return_logs',
            'erp_sales_return_receipt_items',
            'erp_sales_return_receipts',
            'erp_sales_return_items',
            'erp_sales_returns',
            'erp_purchase_return_logs',
            'erp_purchase_return_items',
            'erp_purchase_returns',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

