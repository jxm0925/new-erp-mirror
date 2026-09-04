<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->decimal('picked_qty', 18, 8)->default(0)->after('remaining_qty');
            $table->decimal('delivered_qty', 18, 8)->default(0)->after('picked_qty');
            $table->decimal('received_qty', 18, 8)->default(0)->after('delivered_qty');
        });

        Schema::create('erp_material_picking_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->string('status', 30)->default('WAIT_PICK');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('organization_code', 80)->nullable();
            $table->string('production_location_name_snapshot', 160);
            $table->unsignedBigInteger('responsible_user_legacy_id')->nullable();
            $table->unsignedBigInteger('assigned_picker_legacy_id')->nullable();
            $table->timestamp('planned_delivery_at')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'warehouse_id'], 'erp_material_pick_status_wh_idx');
            $table->index(['work_order_id', 'status'], 'erp_material_pick_wo_status_idx');
            $table->foreign('work_order_id', 'erp_material_pick_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('warehouse_id', 'erp_material_pick_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_material_pick_tx_fk')->references('id')->on('erp_inventory_transactions')->nullOnDelete();
        });

        Schema::create('erp_material_picking_task_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('material_requirement_id');
            $table->unsignedBigInteger('component_item_id');
            $table->decimal('required_qty_snapshot', 18, 8);
            $table->decimal('planned_pick_qty', 18, 8);
            $table->decimal('actual_pick_qty', 18, 8)->default(0);
            $table->decimal('delivered_qty', 18, 8)->default(0);
            $table->decimal('received_qty', 18, 8)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name_snapshot', 80)->nullable();
            $table->unsignedBigInteger('inventory_balance_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('batch_no', 80);
            $table->string('serial_control_type', 30)->default('none');
            $table->json('serial_snapshot')->nullable();
            $table->string('status', 30)->default('WAIT_PICK');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['material_requirement_id', 'status'], 'erp_material_pick_line_req_idx');
            $table->foreign('task_id', 'erp_material_pick_line_task_fk')->references('id')->on('erp_material_picking_tasks')->restrictOnDelete();
            $table->foreign('material_requirement_id', 'erp_material_pick_line_req_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_material_pick_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('unit_id', 'erp_material_pick_line_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
            $table->foreign('inventory_balance_id', 'erp_material_pick_line_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('warehouse_id', 'erp_material_pick_line_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('location_id', 'erp_material_pick_line_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
        });

        Schema::create('erp_material_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('picking_task_id');
            $table->string('status', 30)->default('READY');
            $table->unsignedBigInteger('delivery_user_legacy_id')->nullable();
            $table->unsignedBigInteger('from_warehouse_id');
            $table->string('organization_code', 80)->nullable();
            $table->string('to_production_location_snapshot', 160);
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->unsignedBigInteger('created_by_legacy_id')->nullable();
            $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'delivery_user_legacy_id'], 'erp_material_delivery_status_user_idx');
            $table->index(['work_order_id', 'status'], 'erp_material_delivery_wo_idx');
            $table->foreign('work_order_id', 'erp_material_delivery_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('picking_task_id', 'erp_material_delivery_task_fk')->references('id')->on('erp_material_picking_tasks')->restrictOnDelete();
            $table->foreign('from_warehouse_id', 'erp_material_delivery_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
        });

        Schema::create('erp_material_delivery_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('material_requirement_id');
            $table->unsignedBigInteger('picking_task_line_id');
            $table->unsignedBigInteger('component_item_id');
            $table->decimal('delivery_qty', 18, 8);
            $table->decimal('received_qty', 18, 8)->default(0);
            $table->decimal('rejected_qty', 18, 8)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name_snapshot', 80)->nullable();
            $table->string('batch_no', 80);
            $table->json('serial_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['delivery_id', 'picking_task_line_id'], 'erp_material_delivery_pick_line_uq');
            $table->foreign('delivery_id', 'erp_material_delivery_line_doc_fk')->references('id')->on('erp_material_deliveries')->restrictOnDelete();
            $table->foreign('material_requirement_id', 'erp_material_delivery_line_req_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $table->foreign('picking_task_line_id', 'erp_material_delivery_line_pick_fk')->references('id')->on('erp_material_picking_task_lines')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_material_delivery_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('unit_id', 'erp_material_delivery_line_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
        });

        Schema::create('erp_material_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('work_order_id');
            $table->string('status', 30)->default('CONFIRMED');
            $table->unsignedBigInteger('received_by_legacy_id');
            $table->timestamp('received_at');
            $table->text('remark')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['work_order_id', 'received_at'], 'erp_material_receipt_wo_time_idx');
            $table->foreign('delivery_id', 'erp_material_receipt_delivery_fk')->references('id')->on('erp_material_deliveries')->restrictOnDelete();
            $table->foreign('work_order_id', 'erp_material_receipt_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
        });

        Schema::create('erp_material_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('delivery_line_id');
            $table->unsignedBigInteger('component_item_id');
            $table->decimal('delivered_qty_snapshot', 18, 8);
            $table->decimal('accepted_qty', 18, 8)->default(0);
            $table->decimal('rejected_qty', 18, 8)->default(0);
            $table->text('reject_reason')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->json('accepted_serial_snapshot')->nullable();
            $table->json('rejected_serial_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('receipt_id', 'erp_material_receipt_line_doc_fk')->references('id')->on('erp_material_receipts')->restrictOnDelete();
            $table->foreign('delivery_line_id', 'erp_material_receipt_line_delivery_fk')->references('id')->on('erp_material_delivery_lines')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_material_receipt_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('unit_id', 'erp_material_receipt_line_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
        });

        Schema::create('erp_production_material_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('command_type', 60);
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->string('request_hash', 64);
            $table->string('result_type', 40)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->string('status', 30)->default('processing');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('initiated_by_legacy_id')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id'], 'erp_prod_material_cmd_aggregate_idx');
        });

        Schema::create('erp_production_material_events', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('action', 60);
            $table->string('before_status', 30)->nullable();
            $table->string('after_status', 30)->nullable();
            $table->unsignedInteger('before_version');
            $table->unsignedInteger('after_version');
            $table->json('quantity_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('operator_legacy_id')->nullable();
            $table->string('operator_name', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'erp_prod_material_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_material_events');
        Schema::dropIfExists('erp_production_material_commands');
        Schema::dropIfExists('erp_material_receipt_lines');
        Schema::dropIfExists('erp_material_receipts');
        Schema::dropIfExists('erp_material_delivery_lines');
        Schema::dropIfExists('erp_material_deliveries');
        Schema::dropIfExists('erp_material_picking_task_lines');
        Schema::dropIfExists('erp_material_picking_tasks');

        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->dropColumn(['picked_qty', 'delivered_qty', 'received_qty']);
        });
    }
};
