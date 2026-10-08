<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $duplicates = DB::table('erp_shipment_packing_labor_sessions')->where('status', 'ACTIVE')
            ->selectRaw('employee_legacy_id, COUNT(*) AS active_count')->groupBy('employee_legacy_id')
            ->havingRaw('COUNT(*) > 1')->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('包装计时存在同一员工多条进行中记录，请先核对并暂停重复计时：'.
                $duplicates->map(fn ($row) => $row->employee_legacy_id.'('.$row->active_count.')')->implode(', '));
        }
        Schema::table('erp_shipment_packing_labor_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_employee_legacy_id')->nullable()
                ->storedAs("CASE WHEN status = 'ACTIVE' THEN employee_legacy_id ELSE NULL END");
            $table->unique('active_employee_legacy_id', 'erp_packing_labor_one_active_employee_uq');
            $table->index(['employee_legacy_id', 'status', 'started_at'], 'erp_packing_labor_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('erp_shipment_packing_labor_sessions', function (Blueprint $table): void {
            $table->dropUnique('erp_packing_labor_one_active_employee_uq');
            $table->dropIndex('erp_packing_labor_employee_status_idx');
            $table->dropColumn('active_employee_legacy_id');
        });
    }
};
