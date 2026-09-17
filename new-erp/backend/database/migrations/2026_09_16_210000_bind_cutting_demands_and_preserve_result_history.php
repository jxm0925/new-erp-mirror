<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_cutting_demands')) Schema::create('erp_cutting_demands', function (Blueprint $t): void {
            $t->id(); $t->string('demand_no',80)->unique();
            $t->string('source_type',40); $t->foreignId('source_requirement_id')->constrained('erp_production_target_material_requirements');
            $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('configuration_id')->nullable()->constrained('erp_custom_configurations');
            $t->foreignId('stage_id')->constrained('erp_production_routing_operations');
            $t->decimal('required_base_qty_snapshot',18,8); $t->decimal('cut_length_mm_snapshot',18,2)->nullable();
            $t->decimal('required_piece_qty_snapshot',18,8)->nullable(); $t->unsignedInteger('source_business_version');
            $t->json('source_snapshot'); $t->string('status',24)->default('ACTIVE');
            $t->unsignedInteger('business_version')->default(1); $t->unsignedBigInteger('created_by_legacy_id'); $t->timestamps();
            $t->unique(['source_type','source_requirement_id'],'cut_demand_formal_source_uq');
        });
        // MySQL DDL is not transactional. Keep re-entry safe after an interrupted
        // structure-only deployment; never infer/backfill old business associations.
        if (! Schema::hasColumn('erp_cutting_plan_allocations','demand_id'))
            Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->foreignId('demand_id')->nullable()->constrained('erp_cutting_demands'));
        if (! Schema::hasColumn('erp_cutting_plan_allocations','input_material_requirement_id'))
            Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->unsignedBigInteger('input_material_requirement_id')->nullable());
        if (! collect(Schema::getForeignKeys('erp_cutting_plan_allocations'))->contains('name','cut_plan_input_requirement_fk'))
            Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->foreign('input_material_requirement_id','cut_plan_input_requirement_fk')->references('id')->on('erp_work_order_material_requirements'));
        if (! Schema::hasIndex('erp_cutting_plan_allocations','cut_plan_demand_order_idx'))
            Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->index(['demand_id','cutting_order_id'],'cut_plan_demand_order_idx'));
        Schema::table('erp_cutting_results', function (Blueprint $t): void {
            $t->string('active_client_row_id',80)->nullable()->storedAs("CASE WHEN status IN ('VOIDED','SUPERSEDED') THEN NULL ELSE client_row_id END");
            $t->unique(['settlement_batch_id','active_client_row_id'],'cut_result_active_row_uq');
            $t->dropUnique('cut_result_row_uq');
            $t->foreignId('supersedes_result_id')->nullable()->constrained('erp_cutting_results');
            $t->timestamp('voided_at')->nullable(); $t->unsignedBigInteger('voided_by_legacy_id')->nullable();
        });
    }
    public function down(): void
    {
        // A historical client key may have several retained revisions. The old unique index
        // then rejects rollback instead of deleting inspected business history.
        Schema::table('erp_cutting_results', function (Blueprint $t): void {
            $t->unique(['settlement_batch_id','client_row_id'],'cut_result_row_uq');
            $t->dropUnique('cut_result_active_row_uq'); $t->dropColumn('active_client_row_id');
            $t->dropConstrainedForeignId('supersedes_result_id'); $t->dropColumn(['voided_at','voided_by_legacy_id']);
        });
        Schema::table('erp_cutting_plan_allocations', function (Blueprint $t): void {
            $t->dropIndex('cut_plan_demand_order_idx'); $t->dropForeign('cut_plan_input_requirement_fk'); $t->dropColumn('input_material_requirement_id'); $t->dropConstrainedForeignId('demand_id');
        });
        Schema::dropIfExists('erp_cutting_demands');
    }
};
