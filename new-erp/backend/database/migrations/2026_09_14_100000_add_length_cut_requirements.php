<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->string('material_grade', 80)->nullable()->after('spec');
            $table->decimal('standard_stock_length_mm', 12, 2)->nullable()->after('material_grade');
            $table->boolean('is_length_cut_material')->default(false)->after('standard_stock_length_mm');
            $table->index('is_length_cut_material', 'erp_items_length_cut_idx');
        });

        Schema::table('erp_bom_items', function (Blueprint $table): void {
            $table->decimal('cut_length_mm', 12, 2)->nullable()->after('component_item_name');
            $table->unsignedInteger('piece_qty')->nullable()->after('cut_length_mm');
            $table->index(['bom_id', 'component_item_id', 'cut_length_mm'], 'erp_bom_item_cut_idx');
        });

        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->decimal('cut_length_mm_snapshot', 12, 2)->nullable()->after('component_spec_snapshot');
            $table->decimal('per_output_piece_qty', 18, 8)->nullable()->after('cut_length_mm_snapshot');
            $table->decimal('required_piece_qty', 18, 8)->nullable()->after('per_output_piece_qty');
        });

        Schema::table('erp_production_target_material_requirements', function (Blueprint $table): void {
            $table->decimal('cut_length_mm_snapshot', 12, 2)->nullable()->after('component_item_id');
            $table->decimal('required_piece_qty_snapshot', 18, 8)->nullable()->after('cut_length_mm_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_target_material_requirements', function (Blueprint $table): void {
            $table->dropColumn(['cut_length_mm_snapshot', 'required_piece_qty_snapshot']);
        });

        Schema::table('erp_work_order_material_requirements', function (Blueprint $table): void {
            $table->dropColumn(['cut_length_mm_snapshot', 'per_output_piece_qty', 'required_piece_qty']);
        });

        Schema::table('erp_bom_items', function (Blueprint $table): void {
            $table->dropIndex('erp_bom_item_cut_idx');
            $table->dropColumn(['cut_length_mm', 'piece_qty']);
        });

        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropIndex('erp_items_length_cut_idx');
            $table->dropColumn(['material_grade', 'standard_stock_length_mm', 'is_length_cut_material']);
        });
    }
};
