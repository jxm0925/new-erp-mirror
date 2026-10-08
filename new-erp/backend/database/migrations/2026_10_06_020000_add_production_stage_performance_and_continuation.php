<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_production_stages', 'erp_production_packaging_schemes'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 60)->unique();
                $table->string('name', 120);
                $table->string('status', 20)->default('enabled');
                $table->unsignedInteger('sort')->default(0);
                $table->text('description')->nullable();
                $table->unsignedInteger('business_version')->default(1);
                $table->unsignedBigInteger('created_by_legacy_id')->nullable();
                $table->unsignedBigInteger('updated_by_legacy_id')->nullable();
                $table->timestamps();
            });
        }
        $now = now();
        DB::table('erp_production_stages')->insert([
            ['code' => 'WELDING', 'name' => '焊接', 'sort' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ASSEMBLY', 'name' => '总装', 'sort' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SHIPPING', 'name' => '发货', 'sort' => 30, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('erp_production_packaging_schemes')->insert([
            ['code' => 'WOODEN_CASE', 'name' => '木箱', 'sort' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CARTON', 'name' => '纸箱', 'sort' => 20, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Schema::create('erp_production_catalog_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('client_command_id', 120)->unique();
            $table->string('request_hash', 64);
            $table->string('catalog_type', 30);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->unsignedBigInteger('operator_legacy_id');
            $table->timestamps();
        });
        Schema::table('erp_production_operations', function (Blueprint $table): void {
            $table->boolean('auto_assignment_enabled')->default(false);
        });
        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->foreignId('production_stage_id')->nullable()->constrained('erp_production_stages')->restrictOnDelete();
            $table->string('execution_context', 20)->default('production');
            $table->foreignId('packaging_scheme_id')->nullable()->constrained('erp_production_packaging_schemes')->restrictOnDelete();
            $table->decimal('performance_rate', 10, 6)->nullable();
            $table->json('packaging_materials')->nullable();
        });
        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations', 'erp_production_tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('production_stage_id_snapshot')->nullable();
                $table->string('stage_code_snapshot', 60)->nullable();
                $table->string('stage_name_snapshot', 120)->nullable();
                $table->decimal('performance_rate_snapshot', 10, 6)->nullable();
            });
        }
        Schema::table('erp_work_orders', function (Blueprint $table): void {
            $table->json('inventory_continuation_plan')->nullable();
        });
        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->unsignedBigInteger('bom_item_id')->nullable()->change();
            $table->string('requirement_kind', 30)->default('standard');
            $table->json('remaining_supply_snapshot')->nullable();
        });
        Schema::create('erp_work_order_inventory_continuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('erp_work_orders')->restrictOnDelete();
            $table->unsignedBigInteger('source_output_record_id');
            $table->unsignedBigInteger('inventory_balance_id');
            $table->unsignedBigInteger('inventory_serial_id')->nullable();
            $table->unsignedBigInteger('production_unit_id')->nullable();
            $table->unsignedBigInteger('start_routing_operation_id');
            $table->unsignedInteger('start_sequence');
            $table->decimal('base_qty', 18, 8);
            $table->decimal('received_base_qty', 18, 8)->default(0);
            $table->string('status', 30)->default('RESERVED');
            $table->json('source_snapshot');
            $table->unsignedBigInteger('internal_issue_task_id')->nullable();
            $table->unsignedBigInteger('created_by_legacy_id');
            $table->unsignedInteger('business_version')->default(1);
            $table->timestamps();
            $table->index(['work_order_id', 'start_routing_operation_id', 'status'], 'erp_wo_continuation_target_idx');
            $table->index(['source_output_record_id', 'status'], 'erp_wo_continuation_source_idx');
            $table->foreign('source_output_record_id', 'erp_wo_cont_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $table->foreign('inventory_balance_id', 'erp_wo_cont_balance_fk')->references('id')->on('erp_inventory_balances')->restrictOnDelete();
            $table->foreign('inventory_serial_id', 'erp_wo_cont_serial_fk')->references('id')->on('erp_inventory_serials')->restrictOnDelete();
            $table->foreign('production_unit_id', 'erp_wo_cont_unit_fk')->references('id')->on('erp_production_units')->restrictOnDelete();
        });
        Schema::table('erp_production_output_lineage_links', function (Blueprint $table): void {
            // 原父子关系不足以表达一批半成品被多个订单分用。数量只从正式投入/领用事实填写；
            // 旧链接保留NULL，统计显示资料不足，不能猜测其数量或追认当前员工。
            $table->decimal('parent_base_qty', 18, 8)->nullable();
            $table->decimal('child_base_qty', 18, 8)->nullable();
        });
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('inventory_continuation_id')->nullable();
            $table->foreign('inventory_continuation_id', 'erp_prod_issue_cont_fk')->references('id')->on('erp_work_order_inventory_continuations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_output_lineage_links', function (Blueprint $table): void {
            $table->dropColumn(['parent_base_qty', 'child_base_qty']);
        });
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_issue_cont_fk');
            $table->dropColumn('inventory_continuation_id');
        });
        Schema::dropIfExists('erp_work_order_inventory_continuations');
        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->dropColumn(['requirement_kind', 'remaining_supply_snapshot']);
        });
        Schema::table('erp_work_orders', fn (Blueprint $table) => $table->dropColumn('inventory_continuation_plan'));
        foreach (['erp_production_tasks', 'erp_production_quantity_operations', 'erp_production_unit_operations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn([
                'production_stage_id_snapshot', 'stage_code_snapshot', 'stage_name_snapshot', 'performance_rate_snapshot',
            ]));
        }
        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('production_stage_id');
            $table->dropConstrainedForeignId('packaging_scheme_id');
            $table->dropColumn(['execution_context', 'performance_rate', 'packaging_materials']);
        });
        Schema::table('erp_production_operations', fn (Blueprint $table) => $table->dropColumn('auto_assignment_enabled'));
        Schema::dropIfExists('erp_production_packaging_schemes');
        Schema::dropIfExists('erp_production_stages');
        Schema::dropIfExists('erp_production_catalog_commands');
    }
};
