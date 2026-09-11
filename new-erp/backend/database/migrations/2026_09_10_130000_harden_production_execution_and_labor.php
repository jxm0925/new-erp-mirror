<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateActiveEmployees = DB::table('erp_production_labor_sessions')
            ->where('status', 'ACTIVE')
            ->selectRaw('employee_legacy_id, COUNT(*) as active_count')
            ->groupBy('employee_legacy_id')->havingRaw('COUNT(*) > 1')->get();
        if ($duplicateActiveEmployees->isNotEmpty()) {
            $details = $duplicateActiveEmployees
                ->map(fn ($row): string => $row->employee_legacy_id.'('.$row->active_count.')')->implode(', ');
            throw new \RuntimeException('Cannot enforce one ACTIVE labor session per employee; resolve duplicates first: '.$details);
        }

        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->string('work_mode', 20)->default('manual')->after('quality_mode');
        });

        foreach (['erp_production_unit_operations', 'erp_production_quantity_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('work_mode_snapshot', 20)->default('manual')->after('quality_mode_snapshot');
            });
        }

        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->string('end_reason', 30)->nullable()->after('ended_at');
            $table->unsignedBigInteger('previous_labor_session_id')->nullable()->after('end_reason');
            $table->unsignedBigInteger('active_employee_legacy_id')->nullable()
                ->storedAs("CASE WHEN status = 'ACTIVE' THEN employee_legacy_id ELSE NULL END")
                ->after('employee_legacy_id');
            $table->unique('active_employee_legacy_id', 'erp_prod_labor_one_active_employee_uq');
            $table->index(['employee_legacy_id', 'status', 'ended_at'], 'erp_prod_labor_employee_history_idx');
            $table->foreign('previous_labor_session_id', 'erp_prod_labor_previous_session_fk')
                ->references('id')->on('erp_production_labor_sessions')->nullOnDelete();
        });

        if (! Schema::hasIndex('erp_production_workstation_stock_confirmations', 'erp_workstation_stock_requirement_fk_idx')) {
            Schema::table('erp_production_workstation_stock_confirmations', function (Blueprint $table): void {
                // The old unique key also backs the requirement FK in MySQL. Create a
                // dedicated FK-supporting index before replacing uniqueness with history.
                $table->index('target_material_requirement_id', 'erp_workstation_stock_requirement_fk_idx');
            });
        }
        if (Schema::hasIndex('erp_production_workstation_stock_confirmations', 'erp_workstation_stock_requirement_uq')) {
            Schema::table('erp_production_workstation_stock_confirmations', function (Blueprint $table): void {
                $table->dropUnique('erp_workstation_stock_requirement_uq');
            });
        }
        Schema::table('erp_production_workstation_stock_confirmations', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_no')->default(1)->after('target_material_requirement_id');
            $table->string('client_command_id', 120)->nullable()->after('attempt_no');
            $table->string('request_hash', 64)->nullable()->after('client_command_id');
            $table->decimal('shortage_base_qty_snapshot', 18, 8)->default(0)->after('onsite_available_base_qty_snapshot');
            $table->string('result', 20)->default('SUFFICIENT')->after('shortage_base_qty_snapshot');
            $table->unique(['target_material_requirement_id', 'attempt_no'], 'erp_workstation_stock_requirement_attempt_uq');
            $table->unique(['client_command_id', 'target_material_requirement_id'], 'erp_workstation_stock_command_requirement_uq');
            $table->index(['target_material_requirement_id', 'confirmed_at', 'id'], 'erp_workstation_stock_requirement_history_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_production_workstation_stock_confirmations', function (Blueprint $table): void {
            $table->dropUnique('erp_workstation_stock_requirement_attempt_uq');
            $table->dropUnique('erp_workstation_stock_command_requirement_uq');
            $table->dropIndex('erp_workstation_stock_requirement_history_idx');
            $table->dropColumn(['attempt_no', 'client_command_id', 'request_hash', 'shortage_base_qty_snapshot', 'result']);
        });

        Schema::table('erp_production_labor_sessions', function (Blueprint $table): void {
            $table->dropForeign('erp_prod_labor_previous_session_fk');
            $table->dropUnique('erp_prod_labor_one_active_employee_uq');
            $table->dropIndex('erp_prod_labor_employee_history_idx');
            $table->dropColumn(['end_reason', 'previous_labor_session_id', 'active_employee_legacy_id']);
        });

        foreach (['erp_production_quantity_operations', 'erp_production_unit_operations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('work_mode_snapshot');
            });
        }

        Schema::table('erp_production_routing_operations', function (Blueprint $table): void {
            $table->dropColumn('work_mode');
        });
    }
};
