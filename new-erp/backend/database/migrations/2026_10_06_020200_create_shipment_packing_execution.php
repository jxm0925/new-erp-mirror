<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_sales_shipments', function (Blueprint $table): void {
            $table->unsignedInteger('packing_version')->default(1);
            $table->timestamp('packing_configured_at')->nullable();
        });
        Schema::table('erp_sales_shipment_lines', function (Blueprint $table): void {
            $table->json('packing_routing_snapshot')->nullable();
            $table->json('packing_source_snapshot')->nullable();
        });
        Schema::create('erp_shipment_packing_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('erp_sales_shipment_packages')->cascadeOnDelete();
            $table->foreignId('shipment_line_id')->constrained('erp_sales_shipment_lines')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('erp_sales_order_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->unsignedBigInteger('packaging_scheme_id')->nullable();
            $table->string('packaging_scheme_name_snapshot', 120)->nullable();
            $table->decimal('base_qty', 18, 8);
            $table->json('serial_snapshot')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->json('routing_snapshot')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'shipment_line_id'], 'erp_packing_content_line_idx');
        });
        Schema::create('erp_shipment_packing_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('erp_sales_shipment_packages')->cascadeOnDelete();
            $table->unsignedBigInteger('routing_id')->nullable();
            $table->unsignedInteger('routing_version_snapshot')->nullable();
            $table->unsignedBigInteger('routing_operation_id')->nullable();
            $table->unsignedBigInteger('operation_id');
            $table->string('operation_code_snapshot', 80)->nullable();
            $table->string('operation_name_snapshot', 120);
            $table->unsignedBigInteger('production_stage_id')->nullable();
            $table->string('stage_code_snapshot', 80)->nullable();
            $table->string('stage_name_snapshot', 120)->nullable();
            $table->decimal('performance_rate_snapshot', 12, 8)->nullable();
            $table->unsignedInteger('sequence');
            $table->string('chain_key', 100);
            $table->json('packing_content_ids');
            $table->json('packaging_materials_snapshot')->nullable();
            $table->decimal('planned_base_qty', 18, 8);
            $table->decimal('completed_base_qty', 18, 8)->default(0);
            $table->decimal('setup_standard_minutes_snapshot', 12, 2)->nullable();
            $table->decimal('unit_standard_minutes_snapshot', 12, 2)->nullable();
            $table->string('quality_mode_snapshot', 40)->default('none');
            $table->string('quality_result', 30)->nullable();
            $table->unsignedBigInteger('inspected_by_legacy_id')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->string('inspection_remark', 500)->nullable();
            $table->string('status', 30)->default('WAITING')->index();
            $table->unsignedBigInteger('owner_legacy_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->unique(['package_id', 'chain_key', 'sequence'], 'erp_packing_operation_chain_unique');
        });
        Schema::create('erp_shipment_packing_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('erp_shipment_packing_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('employee_name_snapshot', 120);
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('selected_by_legacy_id');
            $table->timestamps();
            $table->unique(['operation_id', 'employee_legacy_id'], 'erp_packing_participant_unique');
        });
        Schema::create('erp_shipment_packing_labor_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('erp_shipment_packing_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_legacy_id');
            $table->string('status', 30);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('actual_labor_minutes', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['operation_id', 'employee_legacy_id', 'status'], 'erp_packing_labor_actor_idx');
        });
        Schema::create('erp_shipment_packing_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('erp_shipment_packing_operations')->restrictOnDelete();
            $table->foreignId('inventory_balance_id')->constrained('erp_inventory_balances')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('erp_items')->restrictOnDelete();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('base_qty', 18, 8);
            $table->json('serial_snapshot')->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedBigInteger('inventory_transaction_item_id')->nullable();
            $table->decimal('cost_amount_snapshot', 18, 4)->nullable();
            $table->unsignedBigInteger('posted_by_legacy_id');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('erp_shipment_packing_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('erp_sales_shipments')->cascadeOnDelete();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('action', 60);
            $table->unsignedBigInteger('operator_legacy_id');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::create('erp_shipment_packing_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operator_legacy_id');
            $table->string('client_command_id', 100);
            $table->string('request_hash', 64);
            $table->json('response');
            $table->timestamps();
            $table->unique(['operator_legacy_id', 'client_command_id'], 'erp_packing_command_unique');
        });
    }

    public function down(): void
    {
        foreach (['commands', 'logs', 'materials', 'labor_sessions', 'participants', 'operations', 'contents'] as $suffix) {
            Schema::dropIfExists('erp_shipment_packing_'.$suffix);
        }
        Schema::table('erp_sales_shipment_lines', fn (Blueprint $table) => $table->dropColumn(['packing_routing_snapshot', 'packing_source_snapshot']));
        Schema::table('erp_sales_shipments', fn (Blueprint $table) => $table->dropColumn(['packing_version', 'packing_configured_at']));
    }
};
