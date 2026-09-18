<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_production_input_holdings')) Schema::create('erp_production_input_holdings', function (Blueprint $t): void {
            $t->id();
            $t->string('target_type', 30);
            $t->unsignedBigInteger('target_id');
            $t->unsignedBigInteger('target_material_requirement_id')->nullable();
            $t->unsignedBigInteger('source_output_record_id');
            $t->unsignedBigInteger('source_holding_id');
            $t->unsignedBigInteger('input_holding_id')->nullable();
            $t->unsignedBigInteger('operation_handover_id')->nullable();
            $t->unsignedBigInteger('internal_issue_line_id')->nullable();
            $t->decimal('quantity', 18, 8);
            $t->decimal('total_cost', 18, 4);
            $t->string('status', 24)->default('ACTIVE');
            $t->timestamps();

            $t->index(['target_type', 'target_id', 'status'], 'prod_input_target_status_idx');
            $t->unique('input_holding_id', 'prod_input_holding_uq');
            $t->unique('operation_handover_id', 'prod_input_handover_uq');
            $t->unique('internal_issue_line_id', 'prod_input_issue_line_uq');
            $t->foreign('target_material_requirement_id', 'prod_input_requirement_fk')->references('id')->on('erp_production_target_material_requirements')->restrictOnDelete();
            $t->foreign('source_output_record_id', 'prod_input_output_fk')->references('id')->on('erp_production_output_records')->restrictOnDelete();
            $t->foreign('source_holding_id', 'prod_input_source_holding_fk')->references('id')->on('erp_material_holdings')->restrictOnDelete();
            $t->foreign('input_holding_id', 'prod_input_holding_fk')->references('id')->on('erp_material_holdings')->restrictOnDelete();
            $t->foreign('operation_handover_id', 'prod_input_handover_fk')->references('id')->on('erp_production_operation_handovers')->restrictOnDelete();
            $t->foreign('internal_issue_line_id', 'prod_input_issue_line_fk')->references('id')->on('erp_production_internal_issue_lines')->restrictOnDelete();
        });
        Schema::table('erp_production_material_consumptions', function (Blueprint $t): void {
            $t->unsignedBigInteger('target_material_requirement_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_input_holdings')->exists()) {
            throw new RuntimeException('已有跨工序生产投入事实，禁止删除投入桥接及成本追溯。');
        }
        Schema::table('erp_production_material_consumptions', function (Blueprint $t): void {
            $t->unsignedBigInteger('target_material_requirement_id')->nullable(false)->change();
        });
        Schema::dropIfExists('erp_production_input_holdings');
    }
};
