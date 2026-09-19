<?php

namespace App\Services\Erp;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CuttingMaterialEligibilityService
{
    /** Read and command paths share exact plan -> input line -> frozen stage qualification. */
    public function plans(int $orderId): Builder
    {
        return DB::table('erp_cutting_plan_allocations as p')->join('erp_cutting_demands as d','d.id','=','p.demand_id')
            ->join('erp_work_order_material_requirements as r','r.id','=','p.input_material_requirement_id')
            ->join('erp_items as raw','raw.id','=','r.component_item_id')
            ->where('p.cutting_order_id',$orderId)->where('d.status','ACTIVE')->whereColumn('d.source_requirement_id','p.target_material_requirement_id')
            ->whereColumn('d.item_id','p.output_item_id')->whereColumn('d.stage_id','p.stage_id')
            ->whereRaw('d.configuration_id <=> p.configuration_id')->whereColumn('r.work_order_id','p.work_order_id')
            ->where('raw.status','enabled')->where('raw.item_type','raw_material')
            ->where(fn (Builder $q) => $q->whereIn('raw.cutting_mode',['sheet','length'])->orWhere(fn (Builder $q) => $q->whereNull('raw.cutting_mode')->where('raw.is_length_cut_material',true)))
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('erp_work_order_material_supply_rules as s')
                ->whereColumn('s.work_order_id','p.work_order_id')->whereColumn('s.material_requirement_id','r.id')
                ->whereColumn('s.component_item_id','r.component_item_id')->whereColumn('s.target_routing_operation_id_snapshot','p.stage_id'));
    }

    public function assertItem(int $orderId, int $itemId): void
    {
        if (DB::table('erp_cutting_orders')->where('id', $orderId)->where('purpose', 'WORKER')->exists()) {
            if (! $this->workerMaterials()->where('id', $itemId)->exists())
                app(CuttingCommandService::class)->fail('input_not_allowed', '请选择已启用的钢板或定长下料原料。');
            return;
        }
        if (! $this->plans($orderId)->where('r.component_item_id',$itemId)->exists())
            app(CuttingCommandService::class)->fail('input_not_allowed','用料不属于当前正式下料需求及冻结工序的具体原料。');
    }

    public function itemIds(int $orderId): Builder
    {
        return DB::table('erp_cutting_orders')->where('id', $orderId)->where('purpose', 'WORKER')->exists()
            ? $this->workerMaterials()->select('id') : $this->plans($orderId)->select('r.component_item_id');
    }

    public function workerMaterials(): Builder
    {
        return DB::table('erp_items')->where('status', 'enabled')->where('item_type', 'raw_material')
            ->where(fn (Builder $q) => $q->where(fn (Builder $sheet) => $sheet->where('cutting_mode','sheet')->where('material_management_mode','physical'))
                ->orWhere(fn (Builder $length) => $length->where('material_management_mode','quantity')
                    ->where(fn (Builder $mode) => $mode->where('cutting_mode','length')->orWhere(fn (Builder $legacy) => $legacy->whereNull('cutting_mode')->where('is_length_cut_material',true)))));
    }
}
