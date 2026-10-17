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
        $rows = DB::table('erp_material_physicals')->where('item_id', $itemId)->where('current_holding_id', $source->id)
            ->where('status', 'PRODUCTION_RECEIVED')->orderBy('id')->lockForUpdate()->get();
        $input = DB::table('erp_production_input_holdings')->where('input_holding_id', $source->id)->first();
        $receipt = $input ? DB::table('erp_material_receipt_lines')->where('id', $input->material_receipt_line_id)->first() : null;
        $allocation = $receipt ? app(ProductionCuttingAllocationService::class)->decode($receipt->accepted_serial_snapshot)['cutting_allocation'] ?? null : null;
        $stock = $this->stockSources($itemId, $rows, $allocation);
        $selected = CuttingReturnAllocationPolicy::selectStockSources($stock['sources'], $quantity, null, $stock['stock_unit_kind']);
        $rows = $rows->whereIn('id', array_column($selected, 'physical_material_id'));
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
        $ids = []; $total = '0'; $actualQuantity = '0.00000000';
        $returnAllocation = app(ProductionCuttingAllocationService::class)->decode($returnLine->serial_snapshot)['cutting_allocation'] ?? null;
        foreach ($allocations as $allocation) {
            $physicalIds = json_decode($allocation->physical_material_ids ?: '[]', true, 512, JSON_THROW_ON_ERROR);
            if (! $physicalIds || count($physicalIds) !== count(array_unique($physicalIds))) return false;
            $cost = '0'; $physicals = [];
            foreach ($physicalIds as $id) {
                if (in_array($id, $ids, true)) return false;
                $physical = DB::table('erp_material_physicals')->where('id', $id)->lockForUpdate()->first();
                if (!$physical || $physical->status !== 'RETURN_PENDING' || (int) $physical->item_id !== $itemId
                    || (int) $physical->current_holding_id !== (int) $allocation->source_input_holding_id) return false;
                $ids[] = $id; $cost = bcadd($cost, (string) $physical->total_cost, 4);
                $physicals[] = $physical;
            }
            $stock = $this->stockSources($itemId, $physicals, $returnAllocation);
            $stockQuantity = CuttingStockAllocationPolicy::sum($stock['sources'], 'actual_stock_qty');
            if (bccomp($stockQuantity, (string) $allocation->quantity, 8) !== 0) return false;
            $actualQuantity = bcadd($actualQuantity, $stockQuantity, 8);
            if (bccomp($cost, (string) $allocation->total_cost, 4) !== 0) return false;
            $total = bcadd($total, $cost, 4);
        }
        return bccomp($actualQuantity, $quantity, 8) === 0 && bccomp($quantity, (string) $returnLine->return_base_qty, 8) === 0
            && bccomp($total, (string) ($line['cost_amount'] ?? '-1'), 4) === 0;
    }

    /** Existing identities prove area stock; a square-metre quantity is never a board count. */
    public function stockSources(int $itemId, iterable $physicals, ?array $allocation): array
    {
        $kind = $allocation['stock_unit_kind'] ?? null;
        if ($kind === null) {
            $item = Item::with('unit.standardUnit')->findOrFail($itemId);
            $unit = app(UnitConversionDomainService::class)->canonicalUnit($item->unit);
            if (! $unit) $this->fail('整板原料缺少真实库存单位。');
            $kind = app(CuttingQuantityCalculator::class)->unitKind($unit);
        }
        if (! in_array($kind, ['sheet', 'm2'], true)) $this->fail('整板原料库存单位必须为张或平方米。');
        $frozen = collect($allocation['sources'] ?? [])->keyBy('physical_material_id'); $sources = [];
        foreach ($physicals as $physical) {
            $source = $frozen->get((int) $physical->id);
            if ($allocation && ! $source) $this->fail('整板实物不属于原签收或退料的冻结来源。');
            if (! $source) {
                $dimensions = app(ProductionCuttingAllocationService::class)->decode($physical->dimensions);
                $length = FrozenCuttingStockPolicy::positiveDecimal($dimensions['length_mm'] ?? null);
                $width = FrozenCuttingStockPolicy::positiveDecimal($dimensions['width_mm'] ?? null);
                if ($kind === 'm2' && (! $length || ! $width)) $this->fail('平方米库存缺少已维护的实际板材长宽，不能估算数量。');
                $source = ['physical_material_id' => (int) $physical->id, 'actual_stock_qty' => $kind === 'sheet' ? '1.00000000' : bcdiv(bcmul($length, $width, 16), '1000000', 8)];
            }
            $source['actual_stock_qty'] = CuttingReturnAllocationPolicy::stockQuantity($source, $kind); $sources[] = $source;
        }
        return ['stock_unit_kind' => $kind, 'sources' => $sources];
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
