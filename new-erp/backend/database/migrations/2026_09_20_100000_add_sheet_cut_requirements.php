<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_bom_items', function (Blueprint $table): void {
            $table->decimal('cut_width_mm', 12, 2)->nullable();
            $table->decimal('cut_thickness_mm', 12, 2)->nullable();
            $table->boolean('allow_cut_rotation')->default(false);
        });
        foreach (['erp_work_order_material_requirements', 'erp_production_target_material_requirements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('cutting_requirement_snapshot')->nullable();
            });
        }
        Schema::table('erp_cutting_results', function (Blueprint $table): void {
            $table->json('cutting_requirement_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_cutting_results', fn (Blueprint $table) => $table->dropColumn('cutting_requirement_snapshot'));
        foreach (['erp_production_target_material_requirements', 'erp_work_order_material_requirements'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('cutting_requirement_snapshot'));
        }
        Schema::table('erp_bom_items', fn (Blueprint $table) => $table->dropColumn(['cut_width_mm', 'cut_thickness_mm', 'allow_cut_rotation']));
    }
};
