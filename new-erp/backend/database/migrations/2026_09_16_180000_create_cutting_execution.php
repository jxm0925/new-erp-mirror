<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $t): void {
            $t->string('material_management_mode', 16)->default('quantity');
            $t->string('cutting_mode', 16)->nullable();
        });
        Schema::create('erp_custom_configurations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('item_id')->constrained('erp_items');
            $t->string('configuration_no', 80); $t->unsignedInteger('version_no');
            $t->json('dimensions'); $t->string('drawing_reference', 255);
            $t->string('scope_mode', 16); $t->string('status', 16)->default('DRAFT');
            $t->unsignedInteger('business_version')->default(1);
            $t->unsignedBigInteger('created_by_legacy_id'); $t->timestamp('published_at')->nullable();
            $t->timestamps(); $t->unique(['configuration_no', 'version_no'], 'cut_config_version_uq');
        });
        Schema::create('erp_custom_configuration_scopes', function (Blueprint $t): void {
            $t->id(); $t->foreignId('configuration_id')->constrained('erp_custom_configurations');
            $t->string('source_type', 32); $t->unsignedBigInteger('source_id'); $t->timestamps();
            $t->unique(['configuration_id', 'source_type', 'source_id'], 'cut_config_scope_uq');
        });
        Schema::create('erp_cutting_orders', function (Blueprint $t): void {
            $t->id(); $t->string('cutting_order_no', 80)->unique();
            $t->string('status', 24)->default('PUBLISHED');
            $t->unsignedInteger('business_version')->default(1);
            $t->unsignedBigInteger('responsible_user_legacy_id');
            $t->unsignedBigInteger('created_by_legacy_id'); $t->timestamp('published_at'); $t->timestamps();
        });
        Schema::create('erp_cutting_tasks', function (Blueprint $t): void {
            $t->id(); $t->foreignId('cutting_order_id')->unique()->constrained('erp_cutting_orders');
            $t->string('task_no', 80)->unique(); $t->string('status', 24)->default('READY');
            $t->unsignedBigInteger('assignee_user_legacy_id')->nullable();
            $t->unsignedInteger('business_version')->default(1); $t->timestamps();
        });
        Schema::create('erp_cutting_plan_allocations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('cutting_order_id')->constrained('erp_cutting_orders');
            $t->foreignId('work_order_id')->constrained('erp_work_orders');
            $t->unsignedBigInteger('target_material_requirement_id')->nullable();
            $t->foreignId('output_item_id')->constrained('erp_items');
            $t->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations');
            $t->foreignId('stage_id')->constrained('erp_production_routing_operations');
            $t->decimal('planned_qty', 18, 8); $t->json('source_snapshot'); $t->timestamps();
            $t->unique(['cutting_order_id', 'work_order_id', 'stage_id'], 'cut_plan_source_uq');
            $t->foreign('target_material_requirement_id', 'cut_plan_target_fk')->references('id')->on('erp_production_target_material_requirements');
        });
        Schema::create('erp_cutting_allowed_outputs', function (Blueprint $t): void {
            $t->id(); $t->foreignId('cutting_order_id')->constrained('erp_cutting_orders');
            $t->foreignId('plan_id')->constrained('erp_cutting_plan_allocations');
            $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations');
            $t->foreignId('stage_id')->constrained('erp_production_routing_operations');
            $t->string('quality_mode', 24); $t->string('output_mode', 32); $t->string('work_mode', 24);
            $t->json('rule_snapshot'); $t->timestamps();
        });
        Schema::create('erp_material_lots', function (Blueprint $t): void {
            $t->id(); $t->string('lot_no', 80)->unique(); $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations');
            $t->foreignId('stage_id')->nullable()->constrained('erp_production_routing_operations');
            $t->string('material_form', 24); $t->decimal('cut_length_mm', 18, 2)->nullable();
            $t->string('source_type', 32); $t->unsignedBigInteger('source_id');
            $t->foreignId('parent_lot_id')->nullable()->constrained('erp_material_lots'); $t->timestamps();
        });
        Schema::create('erp_material_holdings', function (Blueprint $t): void {
            $t->id(); $t->foreignId('material_lot_id')->constrained('erp_material_lots');
            $t->string('position_type', 24); $t->unsignedBigInteger('position_id');
            $t->foreignId('inventory_balance_id')->nullable()->constrained('erp_inventory_balances');
            // Warehouse holdings are references, never a second stock/value ledger.
            $t->decimal('quantity', 18, 8)->nullable(); $t->decimal('total_cost', 18, 4)->nullable();
            $t->string('status', 24)->default('ACTIVE'); $t->unsignedInteger('business_version')->default(1);
            $t->timestamps(); $t->unique('inventory_balance_id', 'cut_holding_balance_uq');
        });
        DB::statement("ALTER TABLE erp_material_holdings ADD CONSTRAINT cut_holding_authority_ck CHECK ((position_type = 'WAREHOUSE' AND inventory_balance_id IS NOT NULL AND quantity IS NULL AND total_cost IS NULL) OR (position_type <> 'WAREHOUSE' AND inventory_balance_id IS NULL AND quantity IS NOT NULL AND total_cost IS NOT NULL AND quantity >= 0 AND total_cost >= 0))");
        Schema::create('erp_material_physicals', function (Blueprint $t): void {
            $t->id(); $t->string('physical_no', 80)->unique(); $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('source_transaction_item_id')->nullable()->constrained('erp_inventory_transaction_items');
            $t->foreignId('material_lot_id')->constrained('erp_material_lots');
            $t->foreignId('current_holding_id')->constrained('erp_material_holdings');
            $t->foreignId('root_physical_id')->nullable()->constrained('erp_material_physicals');
            $t->foreignId('parent_physical_id')->nullable()->constrained('erp_material_physicals');
            $t->string('material_form', 24); $t->string('shape', 24); $t->json('dimensions');
            $t->decimal('total_cost', 18, 4); $t->string('status', 24)->default('AVAILABLE');
            $t->timestamp('first_cut_at')->nullable(); $t->unsignedInteger('business_version')->default(1); $t->timestamps();
        });
        Schema::create('erp_material_physical_reservations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('physical_material_id')->constrained('erp_material_physicals');
            $t->foreignId('cutting_order_id')->constrained('erp_cutting_orders');
            $t->string('status', 16)->default('ACTIVE');
            $t->unsignedBigInteger('active_physical_id')->nullable()->storedAs("CASE WHEN status = 'ACTIVE' THEN physical_material_id ELSE NULL END");
            $t->unique('active_physical_id', 'cut_one_active_physical_uq'); $t->timestamps();
        });
        Schema::create('erp_cutting_settlement_batches', function (Blueprint $t): void {
            $t->id(); $t->string('batch_no', 80)->unique();
            $t->foreignId('cutting_order_id')->constrained('erp_cutting_orders');
            $t->foreignId('cutting_task_id')->constrained('erp_cutting_tasks');
            $t->foreignId('input_item_id')->constrained('erp_items');
            $t->foreignId('physical_material_id')->nullable()->constrained('erp_material_physicals');
            $t->foreignId('source_holding_id')->constrained('erp_material_holdings');
            $t->foreignId('wip_holding_id')->nullable()->constrained('erp_material_holdings');
            $t->foreignId('issue_transaction_id')->nullable()->constrained('erp_inventory_transactions');
            $t->decimal('input_qty', 18, 8); $t->decimal('original_total_cost', 18, 4);
            $t->decimal('standard_stock_length_mm', 18, 2)->nullable();
            $t->string('status', 24)->default('PROCESSING'); $t->unsignedInteger('business_version')->default(1);
            $t->timestamp('first_cut_at')->nullable(); $t->timestamp('submitted_at')->nullable();
            $t->timestamp('confirmed_at')->nullable(); $t->unsignedBigInteger('confirmed_by_legacy_id')->nullable();
            $t->unsignedBigInteger('active_physical_id')->nullable()->storedAs("CASE WHEN status NOT IN ('RETURNED','CONFIRMED','REVERSED') THEN physical_material_id ELSE NULL END");
            $t->unique('active_physical_id', 'cut_one_active_issue_uq'); $t->timestamps();
        });
        Schema::create('erp_cutting_results', function (Blueprint $t): void {
            $t->id(); $t->foreignId('settlement_batch_id')->constrained('erp_cutting_settlement_batches');
            $t->string('client_row_id', 80); $t->string('result_type', 32);
            $t->foreignId('allowed_output_id')->nullable()->constrained('erp_cutting_allowed_outputs');
            $t->foreignId('item_id')->nullable()->constrained('erp_items');
            $t->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations');
            $t->foreignId('stage_id')->nullable()->constrained('erp_production_routing_operations');
            $t->decimal('actual_qty', 18, 8)->nullable(); $t->decimal('piece_qty', 18, 8)->nullable();
            $t->decimal('cut_length_mm', 18, 2)->nullable(); $t->json('measurements')->nullable();
            $t->string('measurement_status', 24)->default('NOT_RECORDED');
            $t->string('quality_status', 24)->default('NOT_REQUIRED'); $t->string('reported_quality', 24)->nullable();
            $t->decimal('total_cost', 18, 4)->nullable(); $t->string('status', 24)->default('DRAFT');
            $t->foreignId('material_lot_id')->nullable()->constrained('erp_material_lots');
            $t->foreignId('physical_material_id')->nullable()->constrained('erp_material_physicals');
            $t->unsignedInteger('business_version')->default(1); $t->timestamps();
            $t->unique(['settlement_batch_id', 'client_row_id'], 'cut_result_row_uq');
        });
        Schema::create('erp_cutting_result_routes', function (Blueprint $t): void {
            $t->id(); $t->foreignId('result_id')->constrained('erp_cutting_results');
            $t->string('route_type', 24); $t->unsignedBigInteger('target_material_requirement_id')->nullable();
            $t->decimal('quantity', 18, 8); $t->decimal('total_cost', 18, 4)->nullable();
            $t->decimal('received_qty', 18, 8)->default(0); $t->decimal('received_cost', 18, 4)->default(0);
            $t->string('status', 24)->default('PLANNED');
            $t->foreignId('holding_id')->nullable()->constrained('erp_material_holdings');
            $t->unsignedInteger('business_version')->default(1); $t->timestamps();
            $t->foreign('target_material_requirement_id', 'cut_route_target_fk')->references('id')->on('erp_production_target_material_requirements');
        });
        Schema::create('erp_material_movements', function (Blueprint $t): void {
            $t->id(); $t->string('movement_no', 80)->unique();
            $t->foreignId('route_id')->nullable()->constrained('erp_cutting_result_routes');
            $t->foreignId('source_holding_id')->constrained('erp_material_holdings');
            $t->foreignId('target_holding_id')->constrained('erp_material_holdings');
            $t->string('action', 24); $t->decimal('quantity', 18, 8); $t->decimal('total_cost', 18, 4);
            $t->foreignId('inventory_transaction_id')->nullable()->constrained('erp_inventory_transactions');
            $t->unsignedBigInteger('operator_legacy_id'); $t->timestamps();
        });
        Schema::create('erp_cutting_commands', function (Blueprint $t): void {
            $t->id(); $t->string('client_command_id', 120)->unique(); $t->string('command_type', 48);
            $t->unsignedBigInteger('actor_legacy_id'); $t->string('request_hash', 64);
            $t->string('status', 16); $t->json('response')->nullable(); $t->timestamps();
        });
        Schema::create('erp_cutting_events', function (Blueprint $t): void {
            $t->id(); $t->string('aggregate_type', 32); $t->unsignedBigInteger('aggregate_id');
            $t->string('action', 48); $t->unsignedBigInteger('operator_legacy_id');
            $t->json('before_snapshot')->nullable(); $t->json('after_snapshot'); $t->timestamp('occurred_at');
            $t->index(['aggregate_type', 'aggregate_id'], 'cut_event_aggregate_idx');
        });
        foreach (['erp_inventory_balances', 'erp_inventory_batches', 'erp_inventory_transaction_items'] as $name) {
            Schema::table($name, function (Blueprint $t): void {
                $t->foreignId('material_lot_id')->nullable()->constrained('erp_material_lots');
            });
        }
    }

    public function down(): void
    {
        foreach (['erp_inventory_transaction_items', 'erp_inventory_batches', 'erp_inventory_balances'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('material_lot_id'));
        }
        foreach (['erp_cutting_events','erp_cutting_commands','erp_material_movements','erp_cutting_result_routes','erp_cutting_results','erp_cutting_settlement_batches','erp_material_physical_reservations','erp_material_physicals','erp_material_holdings','erp_material_lots','erp_cutting_allowed_outputs','erp_cutting_plan_allocations','erp_cutting_tasks','erp_cutting_orders','erp_custom_configuration_scopes','erp_custom_configurations'] as $name) Schema::dropIfExists($name);
        Schema::table('erp_items', fn (Blueprint $t) => $t->dropColumn(['material_management_mode', 'cutting_mode']));
    }
};
