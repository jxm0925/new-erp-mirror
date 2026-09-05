<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_production_operation_handovers', function (Blueprint $table): void {
            $table->unsignedBigInteger('target_material_requirement_id')->nullable()->after('target_target_id');
            $table->decimal('accepted_base_qty', 18, 8)->nullable()->after('completeness_snapshot');
            $table->foreign('target_material_requirement_id', 'erp_handover_target_material_fk')
                ->references('id')->on('erp_production_target_material_requirements')->nullOnDelete();
        });
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('target_material_requirement_id')->nullable()->after('output_record_id');
            $table->foreign('target_material_requirement_id', 'erp_issue_line_target_material_fk')
                ->references('id')->on('erp_production_target_material_requirements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_internal_issue_lines', function (Blueprint $table): void {
            $table->dropForeign('erp_issue_line_target_material_fk');
            $table->dropColumn('target_material_requirement_id');
        });
        Schema::table('erp_production_operation_handovers', function (Blueprint $table): void {
            $table->dropForeign('erp_handover_target_material_fk');
            $table->dropColumn(['target_material_requirement_id', 'accepted_base_qty']);
        });
    }
};
