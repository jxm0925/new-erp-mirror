<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;

/** Physical identities and their original amounts travel with the existing return cost allocations. */
final class ProductionPhysicalMaterialReturnService
{
    public function prepare(object $source, int $itemId, string $quantity): ?array
    {
        if (Item::findOrFail($itemId)->materialManagementMode() !== 'physical') return null;
        if (bccomp($quantity, bcadd($quantity, '0', 0), 8) !== 0) $this->fail('整板退料必须为整数张。');
        $rows = DB::table('erp_material_physicals')->where('item_id', $itemId)->where('current_holding_id', $source->id)
            ->where('status', 'PRODUCTION_RECEIVED')->orderBy('id')->limit((int) $quantity)->lockForUpdate()->get();
        if ($rows->count() !== (int) $quantity) $this->fail('退料数量没有对应的未加工整板实物。');
        $total = '0'; foreach ($rows as $row) $total = bcadd($total, (string) $row->total_cost, 4);
        if (bccomp($total, (string) $source->total_cost, 4) > 0) $this->fail('整板金额超过该工序剩余投入金额。');
        DB::table('erp_material_physicals')->whereIn('id', $rows->pluck('id'))->update([
            'status'=>'RETURN_PENDING', 'business_version'=>DB::raw('business_version+1'), 'updated_at'=>now()]);
        return ['ids'=>$rows->pluck('id')->map(fn ($id) => (int) $id)->all(), 'cost'=>$total];
    }

    public function validPosting(object $transaction, array $line, string $quantity, int $itemId): bool
    {
        if ($transaction->source_type !== 'production_material_return' || !in_array($transaction->transaction_type,
            ['production_material_return_receipt', 'production_material_quality_return_quarantine'], true)) return false;
        $returnLine = DB::table('erp_production_material_return_lines')->where('id', $line['source_item_id'] ?? 0)
            ->where('return_id', $transaction->source_id)->where('component_item_id', $itemId)->first();
        if (!$returnLine || (int) $returnLine->warehouse_id !== (int) $line['warehouse_id'] || (int) $returnLine->location_id !== (int) $line['location_id']
            || (string) ($returnLine->batch_no ?: 'PROD-RETURN-'.$transaction->source_id) !== (string) $line['batch_no']) return false;
        $allocations = DB::table('erp_production_material_return_cost_allocations')->where('return_line_id', $returnLine->id)->lockForUpdate()->get();
        $ids = []; $total = '0';
        foreach ($allocations as $allocation) {
            $physicalIds = json_decode($allocation->physical_material_ids ?: '[]', true, 512, JSON_THROW_ON_ERROR);
            if (bccomp((string) count($physicalIds), (string) $allocation->quantity, 8) !== 0) return false;
            $cost = '0';
            foreach ($physicalIds as $id) {
                if (in_array($id, $ids, true)) return false;
                $physical = DB::table('erp_material_physicals')->where('id', $id)->lockForUpdate()->first();
                if (!$physical || $physical->status !== 'RETURN_PENDING' || (int) $physical->item_id !== $itemId
                    || (int) $physical->current_holding_id !== (int) $allocation->source_input_holding_id) return false;
                $ids[] = $id; $cost = bcadd($cost, (string) $physical->total_cost, 4);
            }
            if (bccomp($cost, (string) $allocation->total_cost, 4) !== 0) return false;
            $total = bcadd($total, $cost, 4);
        }
        return bccomp((string) count($ids), $quantity, 8) === 0 && bccomp($quantity, (string) $returnLine->return_base_qty, 8) === 0
            && bccomp($total, (string) ($line['cost_amount'] ?? '-1'), 4) === 0;
    }

    public function finalize(object $line, object $posted, object $transaction): void
    {
        $allocations = DB::table('erp_production_material_return_cost_allocations')->where('return_line_id', $line->id)->whereNotNull('physical_material_ids')->get();
        if ($allocations->isEmpty()) return;
        $balance = DB::table('erp_inventory_balances')->where('item_id', $line->component_item_id)->where('warehouse_id', $posted->warehouse_id)
            ->where('location_id', $posted->location_id)->where('batch_no', $posted->batch_no)->lockForUpdate()->first();
        if (!$balance) $this->fail('整板退料库存过账缺少实际库存余额。');
        $warehouse = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->lockForUpdate()->first();
        if (!$warehouse) {
            $source = DB::table('erp_material_holdings')->where('id', $allocations->first()->source_input_holding_id)->first();
            $holdingId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id'=>$source->material_lot_id, 'position_type'=>'WAREHOUSE',
                'position_id'=>$balance->warehouse_id, 'inventory_balance_id'=>$balance->id, 'quantity'=>null, 'total_cost'=>null,
                'status'=>'ACTIVE', 'business_version'=>1, 'created_at'=>now(), 'updated_at'=>now()]);
        } else $holdingId = $warehouse->id;
        $quarantine = $transaction->transaction_type === 'production_material_quality_return_quarantine';
        foreach ($allocations as $allocation) {
            $ids = json_decode($allocation->physical_material_ids, true, 512, JSON_THROW_ON_ERROR);
            $count = DB::table('erp_material_physicals')->whereIn('id', $ids)->where('status', 'RETURN_PENDING')
                ->where('current_holding_id', $allocation->source_input_holding_id)->update([
                    'current_holding_id'=>$holdingId, 'status'=>$quarantine ? 'QUARANTINED' : 'AVAILABLE',
                    'business_version'=>DB::raw('business_version+1'), 'updated_at'=>now()]);
            if ($count !== count($ids)) $this->fail('整板实物状态已变化，退料必须全部回滚。');
            DB::table('erp_material_movements')->insert(['movement_no'=>app(DocumentNumberService::class)->next('material_movement', 'MM'),
                'source_holding_id'=>$allocation->source_input_holding_id, 'target_holding_id'=>$holdingId, 'action'=>'PROD_PHYSICAL_RETURN',
                'quantity'=>$allocation->quantity, 'total_cost'=>$allocation->total_cost, 'inventory_transaction_id'=>$transaction->id,
                'operator_legacy_id'=>$transaction->posted_by, 'created_at'=>now(), 'updated_at'=>now()]);
        }
    }

    public function release(object $line, int $balanceId): void
    {
        $allocations = DB::table('erp_production_material_return_cost_allocations')->where('return_line_id', $line->id)->whereNotNull('physical_material_ids')->get();
        foreach ($allocations as $allocation) {
            $ids = json_decode($allocation->physical_material_ids, true, 512, JSON_THROW_ON_ERROR);
            $holdingId = DB::table('erp_material_holdings')->where('inventory_balance_id', $balanceId)->value('id');
            $changed = DB::table('erp_material_physicals')->whereIn('id', $ids)->where('current_holding_id', $holdingId)->where('status', 'QUARANTINED')
                ->update(['status'=>'AVAILABLE', 'business_version'=>DB::raw('business_version+1'), 'updated_at'=>now()]);
            if ($changed !== count($ids)) $this->fail('质量退料实物不在原隔离库存，不能解除隔离。');
        }
    }

    private function fail(string $message): never
    {
        throw new \App\Exceptions\Erp\WorkOrderDomainException('production_physical_return_invalid', $message, 409);
    }
}
