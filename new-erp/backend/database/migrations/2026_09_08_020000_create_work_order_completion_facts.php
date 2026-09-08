<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_work_order_completions')) Schema::create('erp_work_order_completions', function (Blueprint $table): void {
            $table->id();
            $table->string('completion_no', 80)->unique();
            $table->string('client_command_id', 120)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->string('status', 30)->default('PENDING_REVIEW');
            $table->decimal('submitted_base_qty', 18, 8);
            $table->decimal('qualified_base_qty', 18, 8);
            $table->decimal('unqualified_base_qty', 18, 8)->default(0);
            $table->decimal('scrapped_base_qty', 18, 8)->default(0);
            $table->string('defect_reason', 1000)->nullable();
            $table->string('remark', 2000)->nullable();
            $table->json('attachment_snapshot')->nullable();
            $table->json('preflight_snapshot');
            $table->unsignedBigInteger('submitted_by_legacy_id');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('reviewed_by_legacy_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 1000)->nullable();
            $table->string('organization_code', 80)->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['work_order_id', 'status'], 'erp_wo_completion_wo_status_idx');
            $table->foreign('work_order_id', 'erp_wo_completion_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
        });

        if (! Schema::hasTable('erp_work_order_completion_lines')) Schema::create('erp_work_order_completion_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('completion_id');
            $table->unsignedBigInteger('output_record_id');
            $table->string('source_target_type', 30);
            $table->unsignedBigInteger('source_target_id');
            $table->unsignedBigInteger('output_item_id');
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->decimal('submitted_base_qty', 18, 8);
            $table->decimal('qualified_base_qty', 18, 8);
            $table->decimal('unqualified_base_qty', 18, 8)->default(0);
            $table->decimal('scrapped_base_qty', 18, 8)->default(0);
            $table->timestamps();

            $table->unique(['completion_id', 'output_record_id'], 'erp_wo_completion_line_output_uq');
            $table->index(['output_record_id', 'completion_id'], 'erp_wo_completion_output_idx');
            $table->foreign('completion_id', 'erp_wo_completion_line_parent_fk')->references('id')->on('erp_work_order_completions')->restrictOnDelete();
            $table->foreign('output_record_id', 'erp_wo_completion_line_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('output_item_id', 'erp_wo_completion_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('base_unit_id', 'erp_wo_completion_line_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
        });

        if (! Schema::hasTable('erp_work_order_finished_goods_receipts')) Schema::create('erp_work_order_finished_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->unsignedBigInteger('completion_id');
            $table->unsignedBigInteger('completion_line_id');
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('output_record_id');
            $table->unsignedBigInteger('output_warehouse_posting_id')->nullable();
            $table->unsignedBigInteger('output_item_id');
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('batch_no', 80)->nullable();
            $table->decimal('posted_base_qty', 18, 8);
            $table->string('status', 30)->default('POSTING');
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedBigInteger('posted_by_legacy_id');
            $table->timestamp('posted_at');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['work_order_id', 'posted_at'], 'erp_wo_fg_receipt_wo_time_idx');
            $table->unique('output_warehouse_posting_id', 'erp_wo_fg_receipt_posting_uq');
            $table->unique('inventory_transaction_id', 'erp_wo_fg_receipt_tx_uq');
            $table->foreign('completion_id', 'erp_wo_fg_receipt_completion_fk')->references('id')->on('erp_work_order_completions')->restrictOnDelete();
            $table->foreign('completion_line_id', 'erp_wo_fg_receipt_line_fk')->references('id')->on('erp_work_order_completion_lines')->restrictOnDelete();
            $table->foreign('work_order_id', 'erp_wo_fg_receipt_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('output_record_id', 'erp_wo_fg_receipt_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('output_warehouse_posting_id', 'erp_wo_fg_receipt_posting_fk')->references('id')->on('erp_production_output_warehouse_postings')->restrictOnDelete();
            $table->foreign('output_item_id', 'erp_wo_fg_receipt_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('base_unit_id', 'erp_wo_fg_receipt_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
            $table->foreign('warehouse_id', 'erp_wo_fg_receipt_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('location_id', 'erp_wo_fg_receipt_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_wo_fg_receipt_tx_fk')->references('id')->on('erp_inventory_transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_work_order_finished_goods_receipts');
        Schema::dropIfExists('erp_work_order_completion_lines');
        Schema::dropIfExists('erp_work_order_completions');
    }
};
