<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_production_material_return_cost_allocations', function (Blueprint $table): void {
            $table->json('physical_material_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('erp_production_material_return_cost_allocations')->whereNotNull('physical_material_ids')->exists()) {
            throw new RuntimeException('已有整板退料事实，不能删除实物关联。');
        }
        Schema::table('erp_production_material_return_cost_allocations', fn (Blueprint $table) => $table->dropColumn('physical_material_ids'));
    }
};
