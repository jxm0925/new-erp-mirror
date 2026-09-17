<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use Illuminate\Support\Facades\DB;

final class CuttingInputService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly InventoryService $inventory,
        private readonly DocumentNumberService $numbers) {}

    public function registerPhysical(array $p, object $user, array $permissions): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.material_manage');
        return $c->run('register_material_physical', 0, $p, $user, function () use ($c, $p, $user): array {
            if (($p['expected_version'] ?? null) !== 0) $c->fail('version_required', '新实物登记版本必须为0。');
            $line = DB::table('erp_inventory_transaction_items')->where('id', (int) ($p['source_transaction_item_id'] ?? 0))->lockForUpdate()->first();
            $tx = $line ? DB::table('erp_inventory_transactions')->where('id', $line->transaction_id)->first() : null;
            if (! $line || ! $tx || $tx->posting_status !== 'posted' || $tx->transaction_type !== 'purchase_receipt_posting')
                $c->fail('physical_source_invalid', '整板实物只能登记自真实已过账采购入库明细。');
            $item = Item::find($line->item_id);
            if ($item->materialManagementMode() !== 'physical' || $item->cuttingMode() !== 'sheet') $c->fail('physical_item_invalid', '物料尚未配置为实物管理的板材。');
            if (bccomp((string) $line->change_qty, '1', 8) < 0 || bccomp((string) $line->change_qty, bcadd((string) $line->change_qty, '0', 0), 8) !== 0)
                $c->fail('physical_quantity_invalid', '采购入库板材数量必须为整数实物数量。');
            $balance = InventoryBalance::query()->where(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id,
                'location_id' => $line->location_id, 'batch_no' => $line->batch_no])->lockForUpdate()->first();
            if (! $balance) $c->fail('physical_balance_missing', '采购明细没有对应的正式库存余额。');
            $existing = DB::table('erp_material_physicals')->where('source_transaction_item_id', $line->id)->orderBy('id')->lockForUpdate()->get();
            $count = (string) $existing->count();
            if (bccomp($count, (string) $line->change_qty, 8) >= 0) $c->fail('physical_source_exhausted', '该入库明细的实物已全部登记。', 409);
            $registeredHere = (string) DB::table('erp_material_physicals as p')->join('erp_material_holdings as h','h.id','=','p.current_holding_id')
                ->where('h.inventory_balance_id',$balance->id)->whereIn('p.status',['AVAILABLE','RESERVED'])->count();
            if (bccomp(bcadd($registeredHere,'1',8),(string) $balance->quantity_on_hand,8) > 0)
                $c->fail('physical_registration_exceeds_stock','实物登记不能超过当前仓库真实持有数量。',409);
            $usedCost = '0'; foreach ($existing as $row) $usedCost = bcadd($usedCost, (string) $row->total_cost, 4);
            $cost = CuttingDecimal::share(bcsub((string) $line->cost_amount, $usedCost, 4), bcsub((string) $line->change_qty, $count, 8), '1');
            if (bccomp($cost,'0',4) < 0) $c->fail('physical_cost_invalid','采购入库总金额不足，不能登记负金额实物。');
            $dimensions = $this->dimensions($p['dimensions'] ?? null, false);
            $lotId = $this->warehouseLot($balance, 'FULL_STOCK');
            $holdingId = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->value('id');
            $id = DB::table('erp_material_physicals')->insertGetId(['physical_no' => $this->numbers->next('material_physical', 'PLATE'),
                'item_id' => $item->id, 'source_transaction_item_id' => $line->id, 'material_lot_id' => $lotId,
                'current_holding_id' => $holdingId, 'material_form' => 'FULL_STOCK', 'shape' => 'RECTANGLE',
                'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR), 'total_cost' => $cost,
                'status' => 'AVAILABLE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_material_physicals')->where('id', $id)->update(['root_physical_id' => $id]);
            $response = ['physical_material_id' => $id, 'business_version' => 1, 'status' => 'AVAILABLE'];
            $c->event('physical', $id, 'register', $user, null, $response + ['source_transaction_item_id' => $line->id, 'total_cost' => $cost]); return $response;
        });
    }

    public function reserve(int $orderId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.issue');
        $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue');
        return $c->run('reserve_cutting_physical', $orderId, $p, $user, function () use ($c, $orderId, $p, $user, $permissions, $super): array {
            $order = $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue', true); $c->version($order, $p); $this->activeOrder($order);
            $ids = $p['physical_material_ids'] ?? []; if (! is_array($ids) || count($ids) < 1 || count($ids) > 100) $c->fail('physical_ids_invalid', '请选择具体钢板实物。');
            $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids);
            foreach ($ids as $id) {
                $row = DB::table('erp_material_physicals')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'AVAILABLE') $c->fail('physical_unavailable', '所选实物不存在或已被其他作业占用。', 409);
                $this->inputAllowed($orderId, $row->item_id);
                DB::table('erp_material_physical_reservations')->insert(['physical_material_id' => $id, 'cutting_order_id' => $orderId,
                    'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_material_physicals')->where('id', $id)->update(['status' => 'RESERVED', 'business_version' => $row->business_version + 1, 'updated_at' => now()]);
                $c->event('physical', $id, 'reserve', $user, $row, ['cutting_order_id' => $orderId, 'status' => 'RESERVED']);
            }
            DB::table('erp_cutting_orders')->where('id', $orderId)->update(['business_version' => $order->business_version + 1, 'updated_at' => now()]);
            return ['cutting_order_id' => $orderId, 'physical_material_ids' => $ids, 'business_version' => $order->business_version + 1];
        });
    }

    public function release(int $orderId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.issue');
        $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue');
        return $c->run('release_cutting_physical', $orderId, $p, $user, function () use ($c, $orderId, $p, $user, $permissions, $super): array {
            $order = $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue', true); $c->version($order, $p);
            $id = (int) ($p['physical_material_id'] ?? 0);
            $row = DB::table('erp_material_physicals')->where('id', $id)->lockForUpdate()->first();
            $reservation = DB::table('erp_material_physical_reservations')->where('physical_material_id', $id)->where('cutting_order_id', $orderId)->where('status', 'ACTIVE')->lockForUpdate()->first();
            if (! $row || $row->status !== 'RESERVED' || ! $reservation) $c->fail('physical_release_invalid', '只能释放本下料单尚未领出的实物占用。', 409);
            DB::table('erp_material_physical_reservations')->where('id', $reservation->id)->update(['status' => 'RELEASED', 'updated_at' => now()]);
            DB::table('erp_material_physicals')->where('id', $id)->update(['status' => 'AVAILABLE', 'business_version' => $row->business_version + 1, 'updated_at' => now()]);
            DB::table('erp_cutting_orders')->where('id', $orderId)->update(['business_version' => $order->business_version + 1, 'updated_at' => now()]);
            $response = ['physical_material_id' => $id, 'status' => 'AVAILABLE', 'business_version' => $order->business_version + 1];
            $c->event('physical', $id, 'release', $user, $row, $response); return $response;
        });
    }

    public function issue(int $orderId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.issue');
        $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue');
        return $c->run('issue_cutting_material', $orderId, $p, $user, function () use ($c, $orderId, $p, $user, $permissions, $super): array {
            $order = $c->order($orderId, $user, $permissions, $super, 'production.cutting.issue', true); $c->version($order, $p); $this->activeOrder($order);
            $physicalId = isset($p['physical_material_id']) ? (int) $p['physical_material_id'] : null; $physical = null;
            if ($physicalId) {
                if (isset($p['input_qty']) || isset($p['inventory_balance_id'])) $c->fail('physical_quantity_forbidden', '钢板按具体实物领用，不能填写数量或替换来源余额。');
                $physical = DB::table('erp_material_physicals')->where('id', $physicalId)->lockForUpdate()->first();
                $reservation = DB::table('erp_material_physical_reservations')->where('physical_material_id', $physicalId)->where('cutting_order_id', $orderId)->where('status', 'ACTIVE')->lockForUpdate()->first();
                if (! $physical || $physical->status !== 'RESERVED' || ! $reservation) $c->fail('physical_not_reserved', '该实物未由当前下料单有效占用。', 409);
                $source = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
                if (! $source || $source->status !== 'ACTIVE') $c->fail('source_holding_invalid', '实物来源持有份额无效。', 409);
                $qty = '1.00000000'; $cost = (string) $physical->total_cost;
                $balanceId = $source->inventory_balance_id;
            } else {
                $balanceId = (int) ($p['inventory_balance_id'] ?? 0); $qty = CuttingDecimal::value($p['input_qty'] ?? null);
            }
            $balance = $balanceId ? InventoryBalance::query()->whereKey($balanceId)->lockForUpdate()->first() : null;
            if (! $balance) $c->fail('input_source_invalid', '第一版领料必须引用真实仓库来源余额。');
            $item = Item::find($balance->item_id); $this->inputAllowed($orderId, $item->id);
            if ($physical === null && $item->materialManagementMode() === 'physical') $c->fail('physical_required', '实物管理钢板必须选择具体钢板，不接受数量领料。');
            if ($physical === null && $item->cuttingMode() !== 'length') $c->fail('length_material_required', '数量下料须使用已配置标准长度的原料。');
            if ($physical === null && (bccomp($qty, bcadd($qty, '0', 0), 8) !== 0 || ! $item->standard_stock_length_mm)) $c->fail('root_quantity_invalid', '方管必须明确实际根数及标准原料长度，不能按产出段数倒推。');
            if (bccomp((string) $balance->quantity_available, $qty, 8) < 0) $c->fail('input_stock_insufficient', '来源批次可用数量不足。', 409);
            $lotId = $this->warehouseLot($balance, $physical ? $physical->material_form : 'STANDARD_LENGTH');
            $sourceId = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->value('id');
            $lot = DB::table('erp_material_lots')->where('id', $lotId)->first();
            if ($lot->configuration_id || $lot->stage_id) $c->fail('processed_input_requires_adapter', '已配置或已加工批次不能冒充普通原材料领料。');
            if (! $physical) $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $qty);
            if (bccomp((string) $balance->inventory_value, $cost, 4) < 0) $c->fail('input_cost_insufficient', '实物总金额超过来源库存剩余金额。', 409);
            $taskId = DB::table('erp_cutting_tasks')->where('cutting_order_id', $orderId)->value('id');
            $id = DB::table('erp_cutting_settlement_batches')->insertGetId(['batch_no' => $this->numbers->next('cutting_settlement', 'CB'),
                'cutting_order_id' => $orderId, 'cutting_task_id' => $taskId, 'input_item_id' => $item->id,
                'physical_material_id' => $physicalId, 'source_holding_id' => $sourceId, 'input_qty' => $qty,
                'original_total_cost' => $cost, 'standard_stock_length_mm' => $physical ? null : $item->standard_stock_length_mm,
                'status' => 'PROCESSING', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $batch = DB::table('erp_cutting_settlement_batches')->where('id', $id)->first();
            $tx = $this->inventory->postCuttingIssue($batch, $balance, $user);
            $wipId = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lotId, 'position_type' => 'CUTTING_WIP',
                'position_id' => $id, 'quantity' => $qty, 'total_cost' => $cost, 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_cutting_settlement_batches')->where('id', $id)->update(['issue_transaction_id' => $tx->id, 'wip_holding_id' => $wipId]);
            DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'),
                'source_holding_id' => $sourceId, 'target_holding_id' => $wipId, 'action' => 'ISSUE', 'quantity' => $qty, 'total_cost' => $cost,
                'inventory_transaction_id' => $tx->id, 'operator_legacy_id' => $c->actor($user), 'created_at' => now(), 'updated_at' => now()]);
            if ($physical) DB::table('erp_material_physicals')->where('id', $physicalId)->update(['status' => 'ISSUED', 'current_holding_id' => $wipId,
                'business_version' => $physical->business_version + 1, 'updated_at' => now()]);
            DB::table('erp_cutting_orders')->where('id', $orderId)->update(['business_version' => $order->business_version + 1, 'updated_at' => now()]);
            $response = ['settlement_batch_id' => $id, 'status' => 'PROCESSING', 'business_version' => 1, 'order_business_version' => $order->business_version + 1,
                'physical_material_id' => $physicalId, 'input_qty' => $qty, 'original_total_cost' => $cost];
            $c->event('batch', $id, 'issue', $user, null, $response + ['inventory_transaction_id' => $tx->id]); return $response;
        });
    }

    public function markFirstCut(int $batchId, array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands; $c->permission($permissions, 'production.cutting.record');
        $c->assertBatchVisible($batchId, $user, $permissions, $super, 'production.cutting.record');
        return $c->run('mark_cutting_first_cut', $batchId, $p, $user, function () use ($c, $batchId, $p, $user, $permissions, $super): array {
            $batch = $c->batch($batchId, $user, $permissions, $super, 'production.cutting.record'); $c->version($batch, $p);
            if ($batch->status !== 'PROCESSING' || $batch->first_cut_at) $c->fail('first_cut_invalid', '该用料批次不能重复登记首次实际切割。', 409);
            DB::table('erp_cutting_settlement_batches')->where('id', $batchId)->update(['first_cut_at' => now(), 'business_version' => $batch->business_version + 1, 'updated_at' => now()]);
            if ($batch->physical_material_id) DB::table('erp_material_physicals')->where('id', $batch->physical_material_id)->update(['first_cut_at' => now(),
                'business_version' => DB::raw('business_version + 1'), 'updated_at' => now()]);
            $response = ['settlement_batch_id' => $batchId, 'business_version' => $batch->business_version + 1];
            $c->event('batch', $batchId, 'first_cut', $user, $batch, $response); return $response;
        });
    }

    private function inputAllowed(int $orderId, int $itemId): void
    {
        app(CuttingMaterialEligibilityService::class)->assertItem($orderId,$itemId);
    }

    private function warehouseLot(InventoryBalance $balance, string $form): int
    {
        if ($balance->material_lot_id) return (int) $balance->material_lot_id;
        $id = DB::table('erp_material_lots')->insertGetId(['lot_no' => $this->numbers->next('material_lot', 'ML'), 'item_id' => $balance->item_id,
            'material_form' => $form, 'source_type' => 'inventory_balance', 'source_id' => $balance->id, 'created_at' => now(), 'updated_at' => now()]);
        $balance->update(['material_lot_id' => $id]);
        DB::table('erp_inventory_batches')->where('item_id', $balance->item_id)->where('batch_no', $balance->batch_no)->update(['material_lot_id' => $id]);
        DB::table('erp_material_holdings')->insert(['material_lot_id' => $id, 'position_type' => 'WAREHOUSE', 'position_id' => $balance->warehouse_id,
            'inventory_balance_id' => $balance->id, 'quantity' => null, 'total_cost' => null, 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function activeOrder(object $order): void
    { if (! in_array($order->status, ['PUBLISHED','IN_PROGRESS'], true)) $this->commands->fail('order_not_active', '该下料单不处于可执行状态。', 409); }

    private function dimensions(mixed $value, bool $irregular): array
    {
        if (! is_array($value) || ! isset($value['length_mm'], $value['width_mm'], $value['thickness_mm'])) $this->commands->fail('dimensions_required', '须维护真实板材尺寸或明确外包尺寸。');
        if (array_diff(array_keys($value), ['length_mm','width_mm','thickness_mm'])) $this->commands->fail('dimensions_invalid', '板材尺寸字段不合法。');
        foreach ($value as $key => $v) $value[$key] = CuttingDecimal::value($v, 2); return $value;
    }
}
