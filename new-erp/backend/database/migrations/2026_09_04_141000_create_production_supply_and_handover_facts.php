<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_routing_operation_material_supply_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('routing_operation_id');
            $table->unsignedBigInteger('component_item_id');
            $table->unsignedBigInteger('target_routing_operation_id');
            $table->decimal('required_qty_ratio', 10, 6)->default(1);
            $table->string('supply_mode', 30)->default('dedicated_delivery');
            $table->boolean('requires_delivery')->default(true);
            $table->boolean('participates_in_kitting')->default(true);
            $table->boolean('allow_partial_delivery')->default(false);
            $table->string('delivery_location_type', 40)->default('operation_station');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['routing_operation_id', 'component_item_id', 'target_routing_operation_id'], 'erp_route_supply_rule_uq');
            $table->foreign('routing_operation_id', 'erp_route_supply_source_op_fk')->references('id')->on('erp_production_routing_operations')->cascadeOnDelete();
            $table->foreign('component_item_id', 'erp_route_supply_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('target_routing_operation_id', 'erp_route_supply_target_op_fk')->references('id')->on('erp_production_routing_operations')->restrictOnDelete();
        });

        Schema::create('erp_work_order_material_supply_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('material_requirement_id');
            $table->unsignedBigInteger('component_item_id');
            $table->unsignedBigInteger('source_rule_id')->nullable();
            $table->unsignedBigInteger('target_routing_operation_id_snapshot')->nullable();
            $table->string('target_operation_code_snapshot', 80);
            $table->string('target_operation_name_snapshot', 160);
            $table->decimal('required_base_qty_snapshot', 18, 8);
            $table->string('supply_mode_snapshot', 30);
            $table->boolean('requires_delivery_snapshot');
            $table->boolean('participates_in_kitting_snapshot');
            $table->boolean('allow_partial_delivery_snapshot');
            $table->string('delivery_location_type_snapshot', 40);
            $table->json('rule_snapshot');
            $table->timestamps();

            $table->index(['work_order_id', 'target_routing_operation_id_snapshot'], 'erp_wo_supply_target_idx');
            $table->foreign('work_order_id', 'erp_wo_supply_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('material_requirement_id', 'erp_wo_supply_requirement_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_wo_supply_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('source_rule_id', 'erp_wo_supply_source_rule_fk')->references('id')->on('erp_routing_operation_material_supply_rules')->nullOnDelete();
            $table->foreign('target_routing_operation_id_snapshot', 'erp_wo_supply_target_op_fk')->references('id')->on('erp_production_routing_operations')->nullOnDelete();
        });

        Schema::create('erp_production_target_material_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('material_requirement_id');
            $table->unsignedBigInteger('material_supply_rule_snapshot_id');
            $table->unsignedBigInteger('component_item_id');
            $table->string('requirement_kind', 20)->default('standard');
            $table->decimal('required_base_qty', 18, 8);
            $table->decimal('satisfied_base_qty', 18, 8)->default(0);
            $table->decimal('consumed_base_qty', 18, 8)->default(0);
            $table->decimal('returned_base_qty', 18, 8)->default(0);
            $table->string('status', 30)->default('OPEN');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['target_type', 'target_id', 'material_supply_rule_snapshot_id', 'requirement_kind'], 'erp_prod_target_material_uq');
            $table->index(['work_order_id', 'status'], 'erp_prod_target_material_wo_idx');
            $table->foreign('work_order_id', 'erp_prod_target_material_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('material_requirement_id', 'erp_prod_target_material_requirement_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $table->foreign('material_supply_rule_snapshot_id', 'erp_prod_target_material_supply_fk')->references('id')->on('erp_work_order_material_supply_rules')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_prod_target_material_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
        });

        Schema::table('erp_material_picking_task_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('material_supply_rule_snapshot_id')->nullable()->after('material_requirement_id');
            $table->unsignedBigInteger('target_routing_operation_id_snapshot')->nullable()->after('material_supply_rule_snapshot_id');
            $table->string('target_operation_code_snapshot', 80)->nullable()->after('target_routing_operation_id_snapshot');
            $table->string('target_operation_name_snapshot', 160)->nullable()->after('target_operation_code_snapshot');
            $table->string('production_target_type', 30)->nullable()->after('target_operation_name_snapshot');
            $table->unsignedBigInteger('production_target_id')->nullable()->after('production_target_type');
            $table->index(['production_target_type', 'production_target_id'], 'erp_pick_line_prod_target_idx');
            $table->foreign('material_supply_rule_snapshot_id', 'erp_pick_line_supply_rule_fk')->references('id')->on('erp_work_order_material_supply_rules')->nullOnDelete();
        });

        Schema::table('erp_material_deliveries', function (Blueprint $table): void {
            $table->string('delivery_type', 30)->default('standard')->after('status');
            $table->unsignedBigInteger('source_delivery_id')->nullable()->after('delivery_type');
            $table->unsignedBigInteger('target_routing_operation_id_snapshot')->nullable()->after('picking_task_id');
            $table->string('target_operation_code_snapshot', 80)->nullable()->after('target_routing_operation_id_snapshot');
            $table->string('target_operation_name_snapshot', 160)->nullable()->after('target_operation_code_snapshot');
            $table->string('production_target_type', 30)->nullable()->after('target_operation_name_snapshot');
            $table->unsignedBigInteger('production_target_id')->nullable()->after('production_target_type');
            $table->unsignedBigInteger('expected_receiver_legacy_id')->nullable()->after('delivery_user_legacy_id');
            $table->index(['production_target_type', 'production_target_id', 'status'], 'erp_delivery_prod_target_idx');
            $table->foreign('source_delivery_id', 'erp_delivery_source_fk')->references('id')->on('erp_material_deliveries')->nullOnDelete();
        });

        Schema::create('erp_production_kitting_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->string('confirmation_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('target_routing_operation_id_snapshot')->nullable();
            $table->string('status', 30)->default('CONFIRMED');
            $table->json('required_materials_snapshot');
            $table->json('received_materials_snapshot');
            $table->json('shortage_materials_snapshot');
            $table->unsignedBigInteger('confirmed_by_legacy_id');
            $table->timestamp('confirmed_at');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['target_type', 'target_id', 'confirmed_at'], 'erp_kitting_target_idx');
            $table->foreign('work_order_id', 'erp_kitting_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('task_id', 'erp_kitting_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

        Schema::create('erp_production_kitting_confirmation_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('confirmation_id');
            $table->unsignedBigInteger('material_supply_rule_snapshot_id');
            $table->unsignedBigInteger('component_item_id');
            $table->decimal('required_base_qty_snapshot', 18, 8);
            $table->decimal('received_base_qty_snapshot', 18, 8);
            $table->decimal('shortage_base_qty_snapshot', 18, 8)->default(0);
            $table->json('source_facts_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('confirmation_id', 'erp_kitting_line_confirmation_fk')->references('id')->on('erp_production_kitting_confirmations')->cascadeOnDelete();
            $table->foreign('material_supply_rule_snapshot_id', 'erp_kitting_line_supply_rule_fk')->references('id')->on('erp_work_order_material_supply_rules')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_kitting_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
        });

        Schema::create('erp_production_operation_handovers', function (Blueprint $table): void {
            $table->id();
            $table->string('handover_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->string('source_target_type', 30);
            $table->unsignedBigInteger('source_target_id');
            $table->string('target_target_type', 30);
            $table->unsignedBigInteger('target_target_id');
            $table->unsignedBigInteger('output_record_id')->nullable();
            $table->string('status', 30)->default('WAIT_RECEIVE');
            $table->unsignedBigInteger('handed_over_by_legacy_id');
            $table->timestamp('handed_over_at');
            $table->unsignedBigInteger('expected_receiver_legacy_id')->nullable();
            $table->unsignedBigInteger('received_by_legacy_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('identity_snapshot');
            $table->json('completeness_snapshot')->nullable();
            $table->text('reject_reason')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['target_target_type', 'target_target_id', 'status'], 'erp_handover_target_status_idx');
            $table->foreign('work_order_id', 'erp_handover_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
        });

        Schema::create('erp_production_material_supplement_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('status', 30)->default('SUBMITTED');
            $table->boolean('blocking')->default(true);
            $table->text('reason');
            $table->unsignedBigInteger('requested_by_legacy_id');
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('decided_by_legacy_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['work_order_id', 'status'], 'erp_supplement_wo_status_idx');
            $table->foreign('work_order_id', 'erp_supplement_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('task_id', 'erp_supplement_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

        Schema::create('erp_production_material_supplement_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplement_request_id');
            $table->unsignedBigInteger('component_item_id');
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->decimal('standard_base_qty_snapshot', 18, 8);
            $table->decimal('additional_base_qty', 18, 8);
            $table->unsignedBigInteger('generated_material_requirement_id')->nullable();
            $table->timestamps();

            $table->foreign('supplement_request_id', 'erp_supplement_line_request_fk')->references('id')->on('erp_production_material_supplement_requests')->cascadeOnDelete();
            $table->foreign('component_item_id', 'erp_supplement_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('base_unit_id', 'erp_supplement_line_unit_fk')->references('id')->on('erp_units')->nullOnDelete();
            $table->foreign('generated_material_requirement_id', 'erp_supplement_line_requirement_fk')->references('id')->on('erp_work_order_material_requirements')->nullOnDelete();
        });

        Schema::create('erp_production_material_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('return_type', 30);
            $table->string('status', 30)->default('SUBMITTED');
            $table->text('reason');
            $table->unsignedBigInteger('requested_by_legacy_id');
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('warehouse_received_by_legacy_id')->nullable();
            $table->timestamp('warehouse_received_at')->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['work_order_id', 'status'], 'erp_prod_return_wo_status_idx');
            $table->foreign('work_order_id', 'erp_prod_return_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('task_id', 'erp_prod_return_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
            $table->foreign('inventory_transaction_id', 'erp_prod_return_tx_fk')->references('id')->on('erp_inventory_transactions')->nullOnDelete();
        });

        Schema::create('erp_production_material_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('material_requirement_id');
            $table->unsignedBigInteger('component_item_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id');
            $table->string('batch_no', 80)->nullable();
            $table->json('serial_snapshot')->nullable();
            $table->decimal('return_base_qty', 18, 8);
            $table->string('quality_disposition', 30)->default('not_required');
            $table->timestamps();

            $table->foreign('return_id', 'erp_prod_return_line_return_fk')->references('id')->on('erp_production_material_returns')->cascadeOnDelete();
            $table->foreign('material_requirement_id', 'erp_prod_return_line_requirement_fk')->references('id')->on('erp_work_order_material_requirements')->restrictOnDelete();
            $table->foreign('component_item_id', 'erp_prod_return_line_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('warehouse_id', 'erp_prod_return_line_wh_fk')->references('id')->on('erp_warehouses')->restrictOnDelete();
            $table->foreign('location_id', 'erp_prod_return_line_location_fk')->references('id')->on('erp_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_material_return_lines');
        Schema::dropIfExists('erp_production_material_returns');
        Schema::dropIfExists('erp_production_material_supplement_lines');
        Schema::dropIfExists('erp_production_material_supplement_requests');
        Schema::dropIfExists('erp_production_operation_handovers');
        Schema::dropIfExists('erp_production_kitting_confirmation_lines');
        Schema::dropIfExists('erp_production_kitting_confirmations');
        Schema::dropIfExists('erp_production_target_material_requirements');

        Schema::table('erp_material_deliveries', function (Blueprint $table): void {
            $table->dropForeign('erp_delivery_source_fk');
            $table->dropIndex('erp_delivery_prod_target_idx');
            $table->dropColumn(['delivery_type', 'source_delivery_id', 'target_routing_operation_id_snapshot', 'target_operation_code_snapshot', 'target_operation_name_snapshot', 'production_target_type', 'production_target_id', 'expected_receiver_legacy_id']);
        });
        Schema::table('erp_material_picking_task_lines', function (Blueprint $table): void {
            $table->dropForeign('erp_pick_line_supply_rule_fk');
            $table->dropIndex('erp_pick_line_prod_target_idx');
            $table->dropColumn(['material_supply_rule_snapshot_id', 'target_routing_operation_id_snapshot', 'target_operation_code_snapshot', 'target_operation_name_snapshot', 'production_target_type', 'production_target_id']);
        });
        Schema::dropIfExists('erp_work_order_material_supply_rules');
        Schema::dropIfExists('erp_routing_operation_material_supply_rules');
    }
};
