<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionOutputRecord;
use App\Models\Erp\ProductionUnit;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;

class ProductionUnitTraceService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function units(int $workOrderId, object $user, array $permissions, bool $superAdmin): array
    { $this->permission($permissions, 'production.unit.view'); $this->visible($workOrderId, $user, $permissions, $superAdmin, 'production.unit.view'); return ProductionUnit::where('work_order_id', $workOrderId)->orderBy('sequence_no')->get()->map(fn ($unit) => $this->projection($unit))->all(); }

    public function unit(int $id, object $user, array $permissions, bool $superAdmin): array
    { $this->permission($permissions, 'production.unit.view'); $unit = ProductionUnit::find($id); if (! $unit) $this->fail('production_unit_not_found', '生产单元不存在。', 404); $this->visible($unit->work_order_id, $user, $permissions, $superAdmin, 'production.unit.view'); return $this->projection($unit) + ['operations' => $this->timeline($unit)]; }

    public function trace(string $keyword, object $user, array $permissions, bool $superAdmin): array
    {
        $this->permission($permissions, 'production.trace.view'); $keyword = trim($keyword);
        if ($keyword === '') $this->fail('trace_keyword_required', '请输入设备编号、生产单元号或半成品编号。');
        $unit = ProductionUnit::query()->where('unit_no', $keyword)->orWhere('device_no_snapshot', $keyword)
            ->orWhereHas('deviceSerial', fn ($query) => $query->where('serial_no', $keyword))->first();
        if (! $unit) { $output = ProductionOutputRecord::where('serial_no_snapshot', $keyword)->first(); $unit = $output?->production_unit_id ? ProductionUnit::find($output->production_unit_id) : null; }
        if (! $unit) $this->fail('trace_not_found', '未找到该编号对应的生产链路。', 404);
        $this->visible($unit->work_order_id, $user, $permissions, $superAdmin, 'production.trace.view');
        $operationIds = $unit->operations()->pluck('id');
        return $this->projection($unit) + ['operations' => $this->timeline($unit),
            'outputs' => DB::table('erp_production_output_records')->where('production_unit_id', $unit->id)->orderBy('produced_at')->get()->map(fn ($row) => (array) $row)->all(),
            'handovers' => DB::table('erp_production_operation_handovers')->where('work_order_id', $unit->work_order_id)->whereIn('source_target_id', $operationIds)->orderBy('handed_over_at')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function timeline(ProductionUnit $unit): array
    {
        return $unit->operations()->get()->map(function ($operation): array {
            return ['id' => (int) $operation->id, 'sequence' => (int) $operation->sequence_no_snapshot,
                'operation_code' => $operation->operation_code_snapshot, 'operation_name' => $operation->operation_name_snapshot,
                'status' => $operation->status, 'claimed_at' => optional($operation->claimed_at)->toISOString(),
                'kitting_confirmed_at' => optional($operation->kitting_confirmed_at)->toISOString(), 'started_at' => optional($operation->started_at)->toISOString(),
                'completed_at' => optional($operation->completed_at)->toISOString(), 'actual_labor_minutes' => (float) $operation->actual_labor_minutes,
                'labor_sessions' => DB::table('erp_production_labor_sessions')->where('target_type', 'unit_operation')->where('target_id', $operation->id)->orderBy('started_at')->get()->map(fn ($row) => (array) $row)->all(),
                'quality_inspections' => DB::table('erp_production_quality_inspections as inspection')->join('erp_production_output_records as output', 'output.id', '=', 'inspection.output_record_id')
                    ->where('output.source_target_type', 'unit_operation')->where('output.source_target_id', $operation->id)->select('inspection.*')->get()->map(fn ($row) => (array) $row)->all()];
        })->all();
    }
    private function projection(ProductionUnit $unit): array { return ['id' => (int) $unit->id, 'unit_no' => $unit->unit_no, 'work_order_id' => (int) $unit->work_order_id, 'sequence_no' => (int) $unit->sequence_no, 'status' => $unit->status, 'device_no' => $unit->device_no_snapshot, 'current_operation_code' => $unit->current_operation_code_snapshot, 'current_operation_name' => $unit->current_operation_name_snapshot, 'business_version' => (int) $unit->business_version]; }
    private function visible(int $workOrderId, object $user, array $permissions, bool $superAdmin, string $permission): void { $workOrder = WorkOrder::find($workOrderId); if (! $workOrder || ! $this->scopeResolver->workOrderVisible($workOrder, $this->scopeResolver->resolve($user, $permission, $permissions, $superAdmin))) $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
