<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->string('production_execution_mode', 20)->default('unit')->after('serial_number_prefix');
            $table->string('serial_generation_stage', 40)->default('before_finished_goods_posting')->after('production_execution_mode');
            $table->unsignedBigInteger('serial_generation_routing_operation_id')->nullable()->after('serial_generation_stage');
            $table->index('production_execution_mode', 'erp_items_execution_mode_idx');
            $table->foreign('serial_generation_routing_operation_id', 'erp_items_serial_route_operation_fk')
                ->references('id')->on('erp_production_routing_operations')->nullOnDelete();
        });

        Schema::table('erp_item_material_policies', function (Blueprint $table): void {
            $table->string('production_execution_mode', 20)->default('unit')->after('serial_tracking_mode');
            $table->string('serial_generation_stage', 40)->default('before_finished_goods_posting')->after('production_execution_mode');
            $table->unsignedBigInteger('serial_generation_routing_operation_id')->nullable()->after('serial_generation_stage');
            $table->index(['production_execution_mode', 'status'], 'erp_item_policy_exec_mode_idx');
            $table->foreign('serial_generation_routing_operation_id', 'erp_item_policy_serial_route_op_fk')
                ->references('id')->on('erp_production_routing_operations')->nullOnDelete();
        });

        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->string('production_execution_mode_snapshot', 20)->nullable()->after('routing_snapshot');
            $table->json('serial_policy_snapshot')->nullable()->after('production_execution_mode_snapshot');
            $table->boolean('collaboration_enabled')->default(false)->after('responsible_user_legacy_id');
            $table->index(['production_execution_mode_snapshot', 'status'], 'erp_wo_exec_mode_status_idx');
        });

        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->decimal('standard_minutes', 12, 2)->nullable()->after('is_key_operation');
            $table->unsignedBigInteger('output_item_id')->nullable()->after('standard_minutes');
            $table->string('output_mode', 30)->default('flow_only')->after('output_item_id');
            $table->string('quality_mode', 20)->default('none')->after('output_mode');
            $table->boolean('allow_continue_without_warehouse')->default(true)->after('quality_mode');
            $table->foreign('output_item_id', 'erp_routing_op_output_item_fk')
                ->references('id')->on('erp_items')->nullOnDelete();
        });

        Schema::create('erp_production_serials', function (Blueprint $table): void {
            $table->id();
            $table->string('serial_no', 120)->unique();
            $table->unsignedBigInteger('item_id');
            $table->string('serial_type', 30);
            $table->string('generation_stage', 40);
            $table->string('status', 30)->default('generated');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('inventory_serial_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'erp_prod_serial_source_idx');
            $table->foreign('item_id', 'erp_prod_serial_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('inventory_serial_id', 'erp_prod_serial_inventory_fk')->references('id')->on('erp_inventory_serials')->nullOnDelete();
        });

        Schema::create('erp_production_units', function (Blueprint $table): void {
            $table->id();
            $table->string('unit_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedInteger('sequence_no');
            $table->unsignedBigInteger('output_item_id');
            $table->unsignedBigInteger('device_serial_id')->nullable();
            $table->string('device_no_snapshot', 120)->nullable();
            $table->string('status', 30)->default('WAITING');
            $table->unsignedBigInteger('routing_id_snapshot')->nullable();
            $table->unsignedInteger('routing_version_snapshot')->nullable();
            $table->json('routing_snapshot');
            $table->unsignedBigInteger('current_routing_operation_id')->nullable();
            $table->string('current_operation_code_snapshot', 80)->nullable();
            $table->string('current_operation_name_snapshot', 160)->nullable();
            $table->string('production_location_name_snapshot', 160)->nullable();
            $table->string('organization_code', 80)->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['work_order_id', 'sequence_no'], 'erp_prod_unit_wo_seq_uq');
            $table->index(['work_order_id', 'status'], 'erp_prod_unit_wo_status_idx');
            $table->index(['current_routing_operation_id', 'status'], 'erp_prod_unit_route_status_idx');
            $table->foreign('work_order_id', 'erp_prod_unit_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('output_item_id', 'erp_prod_unit_item_fk')->references('id')->on('erp_items')->restrictOnDelete();
            $table->foreign('device_serial_id', 'erp_prod_unit_serial_fk')->references('id')->on('erp_production_serials')->nullOnDelete();
            $table->foreign('routing_id_snapshot', 'erp_prod_unit_route_fk')->references('id')->on('erp_production_routings')->nullOnDelete();
            $table->foreign('current_routing_operation_id', 'erp_prod_unit_current_op_fk')->references('id')->on('erp_production_routing_operations')->nullOnDelete();
        });

        Schema::create('erp_production_unit_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('production_unit_id');
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('routing_operation_id_snapshot')->nullable();
            $table->unsignedBigInteger('operation_id_snapshot')->nullable();
            $table->string('operation_code_snapshot', 80);
            $table->string('operation_name_snapshot', 160);
            $table->unsignedInteger('sequence_no_snapshot');
            $table->string('status', 30)->default('WAIT_PREVIOUS');
            $table->unsignedBigInteger('responsible_user_legacy_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('kitting_confirmed_at')->nullable();
            $table->unsignedBigInteger('kitting_confirmed_by_legacy_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('standard_minutes_snapshot', 12, 2)->nullable();
            $table->decimal('actual_labor_minutes', 14, 2)->default(0);
            $table->boolean('kitting_required')->default(false);
            $table->unsignedBigInteger('output_item_id_snapshot')->nullable();
            $table->string('output_mode_snapshot', 30)->default('flow_only');
            $table->string('quality_mode_snapshot', 20)->default('none');
            $table->boolean('allow_continue_without_warehouse_snapshot')->default(true);
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['production_unit_id', 'sequence_no_snapshot'], 'erp_prod_unit_op_seq_uq');
            $table->index(['work_order_id', 'status'], 'erp_prod_unit_op_wo_status_idx');
            $table->index(['routing_operation_id_snapshot', 'status'], 'erp_prod_unit_op_route_status_idx');
            $table->foreign('production_unit_id', 'erp_prod_unit_op_unit_fk')->references('id')->on('erp_production_units')->restrictOnDelete();
            $table->foreign('work_order_id', 'erp_prod_unit_op_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('routing_operation_id_snapshot', 'erp_prod_unit_op_route_op_fk')->references('id')->on('erp_production_routing_operations')->nullOnDelete();
            $table->foreign('operation_id_snapshot', 'erp_prod_unit_op_operation_fk')->references('id')->on('erp_production_operations')->nullOnDelete();
            $table->foreign('output_item_id_snapshot', 'erp_prod_unit_op_output_item_fk')->references('id')->on('erp_items')->nullOnDelete();
        });

        Schema::create('erp_production_quantity_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('routing_operation_id_snapshot')->nullable();
            $table->unsignedBigInteger('operation_id_snapshot')->nullable();
            $table->string('operation_code_snapshot', 80);
            $table->string('operation_name_snapshot', 160);
            $table->unsignedInteger('sequence_no_snapshot');
            $table->string('status', 30)->default('WAIT_PREVIOUS');
            $table->decimal('planned_base_qty', 18, 8);
            $table->decimal('completed_base_qty', 18, 8)->default(0);
            $table->decimal('scrapped_base_qty', 18, 8)->default(0);
            $table->decimal('remaining_base_qty', 18, 8);
            $table->unsignedBigInteger('responsible_user_legacy_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('kitting_confirmed_at')->nullable();
            $table->unsignedBigInteger('kitting_confirmed_by_legacy_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('standard_minutes_snapshot', 12, 2)->nullable();
            $table->decimal('actual_labor_minutes', 14, 2)->default(0);
            $table->boolean('kitting_required')->default(false);
            $table->unsignedBigInteger('output_item_id_snapshot')->nullable();
            $table->string('output_mode_snapshot', 30)->default('flow_only');
            $table->string('quality_mode_snapshot', 20)->default('none');
            $table->boolean('allow_continue_without_warehouse_snapshot')->default(true);
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->unique(['work_order_id', 'sequence_no_snapshot'], 'erp_prod_qty_op_wo_seq_uq');
            $table->index(['routing_operation_id_snapshot', 'status'], 'erp_prod_qty_op_route_status_idx');
            $table->foreign('work_order_id', 'erp_prod_qty_op_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('routing_operation_id_snapshot', 'erp_prod_qty_op_route_op_fk')->references('id')->on('erp_production_routing_operations')->nullOnDelete();
            $table->foreign('operation_id_snapshot', 'erp_prod_qty_op_operation_fk')->references('id')->on('erp_production_operations')->nullOnDelete();
            $table->foreign('output_item_id_snapshot', 'erp_prod_qty_op_output_item_fk')->references('id')->on('erp_items')->nullOnDelete();
        });

        Schema::create('erp_production_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_no', 80)->unique();
            $table->unsignedBigInteger('work_order_id');
            $table->string('execution_mode', 20);
            $table->unsignedBigInteger('routing_operation_id_snapshot')->nullable();
            $table->string('operation_code_snapshot', 80);
            $table->string('operation_name_snapshot', 160);
            $table->unsignedInteger('sequence_no_snapshot');
            $table->string('status', 30)->default('WAIT_CLAIM');
            $table->unsignedBigInteger('assignee_user_legacy_id')->nullable();
            $table->string('assignment_mode', 30)->nullable();
            $table->json('assignment_score_snapshot')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->string('organization_code', 80)->nullable();
            $table->timestamps();

            $table->index(['status', 'assignee_user_legacy_id'], 'erp_prod_task_status_user_idx');
            $table->index(['work_order_id', 'sequence_no_snapshot', 'status'], 'erp_prod_task_wo_op_status_idx');
            $table->foreign('work_order_id', 'erp_prod_task_wo_fk')->references('id')->on('erp_work_orders')->restrictOnDelete();
            $table->foreign('routing_operation_id_snapshot', 'erp_prod_task_route_op_fk')->references('id')->on('erp_production_routing_operations')->nullOnDelete();
        });

        Schema::create('erp_production_task_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('status_snapshot', 30);
            $table->timestamps();

            $table->unique(['target_type', 'target_id'], 'erp_prod_task_target_once_uq');
            $table->index(['task_id', 'status_snapshot'], 'erp_prod_task_target_status_idx');
            $table->foreign('task_id', 'erp_prod_task_target_task_fk')->references('id')->on('erp_production_tasks')->cascadeOnDelete();
        });

        Schema::create('erp_production_task_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('role', 20)->default('collaborator');
            $table->decimal('responsibility_weight', 8, 4)->default(0);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();

            $table->index(['task_id', 'employee_legacy_id', 'left_at'], 'erp_prod_collab_active_idx');
            $table->foreign('task_id', 'erp_prod_collab_task_fk')->references('id')->on('erp_production_tasks')->cascadeOnDelete();
        });

        Schema::create('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('role', 20)->default('owner');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('actual_labor_minutes', 14, 2)->default(0);
            $table->decimal('responsibility_weight_snapshot', 8, 4)->default(1);
            $table->decimal('credited_labor_minutes', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['target_type', 'target_id', 'employee_legacy_id', 'status'], 'erp_prod_labor_target_user_idx');
            $table->foreign('task_id', 'erp_prod_labor_task_fk')->references('id')->on('erp_production_tasks')->restrictOnDelete();
        });

        Schema::create('erp_production_execution_commands', function (Blueprint $table): void {
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

            $table->index(['aggregate_type', 'aggregate_id'], 'erp_prod_exec_cmd_aggregate_idx');
        });

        Schema::create('erp_production_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('action', 60);
            $table->string('before_status', 30)->nullable();
            $table->string('after_status', 30)->nullable();
            $table->unsignedInteger('before_version');
            $table->unsignedInteger('after_version');
            $table->json('fact_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('operator_legacy_id')->nullable();
            $table->string('operator_name', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'erp_prod_exec_event_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_production_execution_events');
        Schema::dropIfExists('erp_production_execution_commands');
        Schema::dropIfExists('erp_production_labor_sessions');
        Schema::dropIfExists('erp_production_task_collaborators');
        Schema::dropIfExists('erp_production_task_targets');
        Schema::dropIfExists('erp_production_tasks');
        Schema::dropIfExists('erp_production_quantity_operations');
        Schema::dropIfExists('erp_production_unit_operations');
        Schema::dropIfExists('erp_production_units');
        Schema::dropIfExists('erp_production_serials');

        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->dropForeign('erp_routing_op_output_item_fk');
            $table->dropColumn(['standard_minutes', 'output_item_id', 'output_mode', 'quality_mode', 'allow_continue_without_warehouse']);
        });
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->dropIndex('erp_wo_exec_mode_status_idx');
            $table->dropColumn(['production_execution_mode_snapshot', 'serial_policy_snapshot', 'collaboration_enabled']);
        });
        Schema::table('erp_item_material_policies', function (Blueprint $table): void {
            $table->dropForeign('erp_item_policy_serial_route_op_fk');
            $table->dropIndex('erp_item_policy_exec_mode_idx');
            $table->dropColumn(['production_execution_mode', 'serial_generation_stage', 'serial_generation_routing_operation_id']);
        });
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropForeign('erp_items_serial_route_operation_fk');
            $table->dropIndex('erp_items_execution_mode_idx');
            $table->dropColumn(['production_execution_mode', 'serial_generation_stage', 'serial_generation_routing_operation_id']);
        });
    }
};
