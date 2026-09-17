<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_quality_inspections', function (Blueprint $t): void {
            $t->unsignedBigInteger('output_record_id')->nullable()->change();
            $t->foreignId('cutting_result_id')->nullable()->constrained('erp_cutting_results');
        });
        // Both execution types use the same formal quality facts, without fake PT outputs.
        DB::statement('ALTER TABLE erp_production_quality_inspections ADD CONSTRAINT cut_quality_subject_ck CHECK ((output_record_id IS NOT NULL AND cutting_result_id IS NULL) OR (output_record_id IS NULL AND cutting_result_id IS NOT NULL))');
        Schema::create('erp_cutting_output_allocations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('result_id')->constrained('erp_cutting_results');
            $t->foreignId('route_id')->constrained('erp_cutting_result_routes');
            $t->foreignId('plan_id')->nullable()->constrained('erp_cutting_plan_allocations');
            $t->string('disposition', 32); $t->decimal('quantity', 18, 8); $t->decimal('total_cost', 18, 4);
            $t->string('status', 16)->default('EFFECTIVE'); $t->timestamps();
            $t->unique(['route_id','plan_id'], 'cut_allocation_route_plan_uq');
        });
        Schema::create('erp_cutting_cost_dispositions', function (Blueprint $t): void {
            $t->id(); $t->foreignId('result_id')->unique()->constrained('erp_cutting_results');
            // Loss/scrap values are not a second stock ledger; unknown measures stay unknown.
            $t->string('disposition', 32); $t->decimal('total_cost', 18, 4);
            $t->decimal('measured_qty', 18, 8)->nullable(); $t->string('status', 24)->default('RECORDED');
            $t->unsignedBigInteger('operator_legacy_id'); $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_production_quality_inspections')->whereNotNull('cutting_result_id')->exists()
            || DB::table('erp_cutting_output_allocations')->exists() || DB::table('erp_cutting_cost_dispositions')->exists())
            throw new RuntimeException('已有正式下料质量或核算事实，不允许通过结构回退删除业务历史。');
        Schema::dropIfExists('erp_cutting_cost_dispositions');
        Schema::dropIfExists('erp_cutting_output_allocations');
        DB::statement('ALTER TABLE erp_production_quality_inspections DROP CHECK cut_quality_subject_ck');
        Schema::table('erp_production_quality_inspections', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('cutting_result_id');
            $t->unsignedBigInteger('output_record_id')->nullable(false)->change();
        });
    }
};
