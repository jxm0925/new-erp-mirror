<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_production_output_records', function (Blueprint $table): void {
            $table->id();
            $table->string('output_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->string('source_target_type', 30);
            $table->unsignedBigInteger('source_target_id');
            $table->unsignedBigInteger('production_unit_id')->nullable();
            $table->unsignedBigInteger('output_item_id');
            $table->decimal('output_base_qty', 18, 8);
            $table->string('output_mode_snapshot', 30);
            $table->string('quality_mode_snapshot', 20);
            $table->string('status', 30)->default('CREATED');
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('inventory_serial_id')->nullable();
            $table->string('serial_no_snapshot', 120)->nullable();
            $table->string('disposition', 30)->nullable();
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->timestamp('produced_at');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['source_target_type', 'source_target_id'], 'erp_prod_output_source_idx');
            $table->index(['work_order_id', 'status'], 'erp_prod_output_wo_status_idx');
            $table->foreign('work_order_id', 'erp_prod_output_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('production_unit_id', 'erp_prod_output_unit_fk')->references('id')->on('erp_production_units')->nullOnDelete();
            $table->foreign('output_item_id', 'erp_prod_output_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('serial_id', 'erp_prod_output_serial_fk')->references('id')->on('erp_production_serials')->nullOnDelete();
            $table->foreign('inventory_serial_id', 'erp_prod_output_inventory_serial_fk')->references('id')->on('erp_inventory_serials')->nullOnDelete();
        });

        Schema::create('erp_production_quality_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('inspection_no', 80)->unique();
            $table->unsignedBigInteger('output_record_id');
            $table->string('status', 30)->default('PENDING');
            $table->string('result', 20)->nullable();
            $table->decimal('inspected_base_qty', 18, 8)->default(0);
            $table->decimal('qualified_base_qty', 18, 8)->default(0);
            $table->decimal('unqualified_base_qty', 18, 8)->default(0);
            $table->text('reason')->nullable();
            $table->json('inspection_snapshot')->nullable();
            $table->unsignedBigInteger('inspector_legacy_id')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['output_record_id', 'status'], 'erp_prod_quality_output_status_idx');
            $table->foreign('output_record_id', 'erp_prod_quality_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
        });

        Schema::create('erp_production_output_warehouse_postings', function (Blueprint $table): void {
            $table->id();
            $table->string('posting_no', 80)->unique();
            $table->unsignedBigInteger('output_record_id');
            $table->unsignedBigInteger('quality_inspection_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('batch_no', 80)->nullable();
            $table->decimal('posted_base_qty', 18, 8);
            $table->string('status', 30)->default('POSTED');
            $table->unsignedBigInteger('inventory_transaction_id');
            $table->unsignedBigInteger('posted_by_legacy_id');
            $table->timestamp('posted_at');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['output_record_id', 'inventory_transaction_id'], 'erp_prod_output_post_tx_uq');
            $table->foreign('output_record_id', 'erp_prod_output_post_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('quality_inspection_id', 'erp_prod_output_post_quality_fk')->references('id')->on('erp_production_quality_inspections')->nullOnDelete();
            $table->foreign('warehouse_id', 'erp_prod_output_post_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('location_id', 'erp_prod_output_post_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_prod_output_post_tx_fk')->references('id')->on('erp_inventory_transactions')->restrictOnDelete();
        });

        Schema::create('erp_production_internal_issue_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('target_task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('source_type', 30);
            $table->string('status', 30)->default('WAIT_ISSUE');
            $table->unsignedBigInteger('expected_receiver_legacy_id')->nullable();
            $table->unsignedBigInteger('issued_by_legacy_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('received_by_legacy_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['target_type', 'target_id', 'status'], 'erp_prod_issue_target_status_idx');
            $table->foreign('work_order_id', 'erp_prod_issue_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('target_task_id', 'erp_prod_issue_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_prod_issue_tx_fk')->references('id')->on('erp_inventory_transactions')->nullOnDelete();
        });

        Schema::create('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('issue_task_id');
            $table->unsignedBigInteger('output_record_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('inventory_balance_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('batch_no', 80)->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->string('serial_no_snapshot', 120)->nullable();
            $table->decimal('issue_base_qty', 18, 8);
            $table->timestamps();

            $table->foreign('issue_task_id', 'erp_prod_issue_line_task_fk')->references('id')->on('erp_production_internal_issue_tasks')->cascadeOnDelete();
            $table->foreign('output_record_id', 'erp_prod_issue_line_output_fk')->references('id')->on('erp_production_output_records')->nullOnDelete();
            $table->foreign('item_id', 'erp_prod_issue_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('inventory_balance_id', 'erp_prod_issue_line_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('warehouse_id', 'erp_prod_issue_line_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('location_id', 'erp_prod_issue_line_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
            $table->foreign('serial_id', 'erp_prod_issue_line_serial_fk')->references('id')->on('erp_inventory_serials')->nullOnDelete();
        });

        Schema::create('erp_production_material_return_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('inspection_no', 80)->unique();
            $table->unsignedBigInteger('return_id');
            $table->string('status', 30)->default('PENDING');
            $table->string('result', 20)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('inspector_legacy_id')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->foreign('return_id', 'erp_prod_return_quality_return_fk')->references('id')->on('erp_production_material_returns')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_prod_return_quality_tx_fk')->references('id')->on('erp_inventory_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_material_return_inspections');
        Schema::dropIfExists('erp_production_internal_issue_lines');
        Schema::dropIfExists('erp_production_internal_issue_tasks');
        Schema::dropIfExists('erp_production_output_warehouse_postings');
        Schema::dropIfExists('erp_production_quality_inspections');
        Schema::dropIfExists('erp_production_output_records');
    }
};
