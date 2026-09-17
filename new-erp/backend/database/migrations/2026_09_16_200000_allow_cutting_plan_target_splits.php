<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cutting_plan_allocations', function (Blueprint $t): void {
            $t->unsignedBigInteger('target_identity_id')->storedAs('COALESCE(target_material_requirement_id,0)');
            $t->unique(['cutting_order_id','work_order_id','stage_id','target_identity_id'],'cut_plan_target_source_uq');
            // Keep an order-leading index available throughout DDL for its FK.
            $t->dropUnique('cut_plan_source_uq');
        });
    }
    public function down(): void
    {
        // Reinstating the old unique index fails safely if real multi-target plans exist.
        Schema::table('erp_cutting_plan_allocations', function (Blueprint $t): void {
            $t->unique(['cutting_order_id','work_order_id','stage_id'],'cut_plan_source_uq');
            $t->dropUnique('cut_plan_target_source_uq'); $t->dropColumn('target_identity_id');
        });
    }
};
