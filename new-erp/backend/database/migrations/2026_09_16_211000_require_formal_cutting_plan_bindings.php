<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The composite FK prevents mismatched identities. Mandatory bindings are
        // enforced by the next migration's NOT NULL, not CHECK (ignored on 8.0.12).
        Schema::table('erp_cutting_demands', fn (Blueprint $t) => $t->unique(['id','source_requirement_id'],'cut_demand_formal_identity_uq'));
        Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->foreign(['demand_id','target_material_requirement_id'],'cut_plan_demand_source_fk')
            ->references(['id','source_requirement_id'])->on('erp_cutting_demands'));
    }
    public function down(): void
    {
        Schema::table('erp_cutting_plan_allocations', fn (Blueprint $t) => $t->dropForeign('cut_plan_demand_source_fk'));
        Schema::table('erp_cutting_demands', fn (Blueprint $t) => $t->dropUnique('cut_demand_formal_identity_uq'));
    }
};
