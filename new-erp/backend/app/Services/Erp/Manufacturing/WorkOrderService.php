<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\DocumentNumber;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLog;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderTech;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Support\Facades\DB;

/**
 * 新生产工单：订单生产确认后按“一行一张”自动建单并下达；生产主管只能调整；
 * 也可手动建备货工单。下达时按台生成序列号。BOM/路线缺失时停在“计划”，补齐后再下达。
 */
final class WorkOrderService
{
    public const UNIT_LIMIT = 1000;
    private const OPEN = ['planned', 'released', 'paused'];

    public function __construct(
        private readonly WorkOrderPlanBuilder $plans,
        private readonly DocumentNumberService $numbers,
    ) {}

    /** 订单生产确认的同一事务里调用：为本批 production 履约逐条建工单并尝试下达。 */
    public function createForSalesFulfillments(SalesOrder $order, array $fulfillmentIds, object $actor): array
    {
        $rows = SalesOrderFulfillment::query()->with(['line', 'item'])
            ->whereIn('id', $fulfillmentIds)->where('fulfillment_type', 'production')->where('demand_status', 'confirmed')
            ->orderBy('id')->get();
        $created = [];
        foreach ($rows as $fulfillment) {
            if (MfgWorkOrder::query()->where('sales_fulfillment_id', $fulfillment->id)->exists()) continue;
            $line = $fulfillment->line;
            $item = Item::query()->find($fulfillment->item_id ?: $line?->item_id);
            if (! $line || ! $item) continue;
            $qty = (float) ($fulfillment->item_base_qty ?: $fulfillment->fulfillment_qty);
            $wo = $this->newWorkOrder([
                'source_type' => 'sales_order', 'sales_order_id' => $order->id, 'sales_order_no' => $order->sales_order_no,
                'sales_order_line_id' => $line->id, 'sales_order_line_no' => $line->line_no, 'sales_fulfillment_id' => $fulfillment->id,
                'customer_name' => $order->customer_name_snapshot ?: $order->customer_name,
                'product_id' => $line->product_id, 'product_name' => $line->product_name, 'sku_id' => $line->sku_id,
                'sku_code' => $line->sku?->sku_code, 'sku_name' => $line->sku_name,
                'configuration_snapshot' => $line->configuration_snapshot,
                'special_requirement' => $this->specialRequirement($line),
                'is_urgent' => (bool) $order->is_urgent, 'required_date' => $order->required_delivery_date,
                'planned_start_date' => now()->toDateString(),
                'planned_finish_date' => $order->required_delivery_date ?: now()->addDays(7)->toDateString(),
            ], $item, $qty, $actor);
            // 定制产品：先由技术上传图纸、确认定制 BOM，再下发车间。
            $custom = (bool) $line->sku?->special_custom_description_required;
            if ($custom) MfgWorkOrderTech::create(['work_order_id' => $wo->id, 'status' => 'pending']);
            $this->applyPlan($wo, $item, null, null);
            if ($custom) MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->update(['base_bom_id' => $wo->bom_id]);
            $this->log($wo, 'created', null, 'planned', "订单 {$order->sales_order_no} 第 {$line->line_no} 行生产确认后自动生成工单".($custom ? '，定制产品等技术处理' : ''), $actor);
            if (! $wo->block_reason) $this->doRelease($wo, $actor, true);
            $created[] = $wo->id;
        }
        return $created;
    }

    /** 手工新建备货工单：保存为计划，或保存并下达。 */
    public function createStock(array $data, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $item = Item::query()->where('status', 'enabled')->findOrFail($data['item_id']);
            if ($item->isPurchaseFulfilledItem()) $this->fail('purchase_item', '这个物料是外购件，不能建生产工单，请走采购。');
            $wo = $this->newWorkOrder([
                'source_type' => 'stock', 'stock_reason' => $data['stock_reason'] ?? 'regular',
                'product_id' => $data['product_id'] ?? null, 'sku_id' => $data['sku_id'] ?? null,
                'product_name' => $data['product_name'] ?? null, 'sku_name' => $data['sku_name'] ?? null,
                'is_urgent' => (bool) ($data['is_urgent'] ?? false),
                'planned_start_date' => $data['planned_start_date'], 'planned_finish_date' => $data['planned_finish_date'],
                'required_date' => $data['planned_finish_date'],
                'owner_legacy_id' => $data['owner_legacy_id'] ?? null, 'owner_name' => $data['owner_name'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null, 'remark' => $data['remark'] ?? null,
            ], $item, (float) $data['planned_qty'], $actor);
            $this->applyPlan($wo, $item, $data['bom_id'] ?? null, $data['routing_id'] ?? null);
            $this->log($wo, 'created', null, 'planned', '新建备货工单', $actor);
            if (! empty($data['release'])) {
                if ($wo->block_reason) $this->fail('release_blocked', '不能下达：'.$wo->block_reason);
                $this->doRelease($wo, $actor);
            }
            return $wo->fresh();
        }, 5);
    }

    public function release(int $id, int $expectedVersion, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($id, $expectedVersion, $actor) {
            $wo = $this->locked($id, $expectedVersion);
            if ($wo->status !== 'planned') $this->fail('status_invalid', '只有“计划”状态的工单可以下达。');
            // 缺失的资料可补齐；已有 BOM 内容始终沿用本工单快照，下达时另验当前资格。
            $this->applyPlan($wo, Item::query()->findOrFail($wo->item_id), $wo->bom_id, $wo->routing_id, true);
            if ($wo->block_reason) $this->fail('release_blocked', '不能下达：'.$wo->block_reason);
            $this->doRelease($wo, $actor);
            return $wo->fresh();
        }, 5);
    }

    /** 生产主管能调整的：负责人、计划日期、加急、备注；未开工前可换 BOM/路线版本；备货工单下达前可改数量。 */
    public function adjust(int $id, array $data, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $wo = $this->locked($id, (int) $data['expected_version']);
            if (in_array($wo->status, ['completed', 'closed', 'cancelled'], true)) $this->fail('status_invalid', '工单已结束，不能再调整。');
            $changes = [];
            foreach (['owner_legacy_id', 'owner_name', 'planned_start_date', 'planned_finish_date', 'is_urgent', 'remark', 'warehouse_id'] as $field) {
                if (! array_key_exists($field, $data)) continue;
                $old = $field === 'is_urgent' ? (bool) $wo->is_urgent : ($wo->{$field} instanceof \DateTimeInterface ? $wo->{$field}->format('Y-m-d') : $wo->{$field});
                $new = $field === 'is_urgent' ? (bool) $data[$field] : $data[$field];
                if ((string) $old !== (string) $new) { $changes[$field] = [$old, $new]; $wo->{$field} = $new; }
            }
            if ($wo->planned_start_date && $wo->planned_finish_date && $wo->planned_finish_date->lt($wo->planned_start_date)) $this->fail('date_invalid', '计划完工不能早于计划开工。');
            $replan = false;
            foreach (['bom_id', 'routing_id'] as $field) {
                if (! array_key_exists($field, $data) || (int) $data[$field] === (int) $wo->{$field}) continue;
                if ($wo->started_at) $this->fail('started', '工单已开工，不能再换 BOM 或工艺路线。');
                if ($field === 'bom_id' && ! $data[$field] && $wo->bom_id)
                    $this->fail('bom_required', '不能清空已选 BOM，请明确选择需要改用的 BOM。');
                $replan = true;
                $changes[$field] = [$wo->{$field}, $data[$field]];
            }
            if (array_key_exists('planned_qty', $data) && abs((float) $data['planned_qty'] - (float) $wo->planned_qty) > 0.00001) {
                if ($wo->source_type !== 'stock' || $wo->status !== 'planned') $this->fail('qty_locked', '订单工单的数量跟着订单走；备货工单下达后也不能改数量。');
                $changes['planned_qty'] = [(float) $wo->planned_qty, (float) $data['planned_qty']];
                $wo->planned_qty = $data['planned_qty'];
                $replan = true;
            }
            if (! $changes) $this->fail('no_change', '没有需要保存的修改。');
            $wo->business_version = (int) $wo->business_version + 1;
            $wo->updated_by_legacy_id = $this->actorId($actor);
            $wo->save();
            if ($replan) {
                $this->applyPlan($wo, Item::query()->findOrFail($wo->item_id),
                    array_key_exists('bom_id', $data) ? ($data['bom_id'] ?: null) : $wo->bom_id,
                    array_key_exists('routing_id', $data) ? ($data['routing_id'] ?: null) : $wo->routing_id, true);
                if ($wo->status !== 'planned' && $wo->block_reason) $this->fail('release_blocked', '换版本后资料不全：'.$wo->block_reason);
            }
            $this->log($wo, 'adjusted', $wo->status, $wo->status, '调整工单：'.$this->changeText($changes), $actor, ['changes' => $changes]);
            return $wo->fresh();
        }, 5);
    }

    public function pause(int $id, int $expectedVersion, string $reason, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($id, $expectedVersion, $reason, $actor) {
            $wo = $this->locked($id, $expectedVersion);
            if (! in_array($wo->status, ['released', 'in_progress'], true)) $this->fail('status_invalid', '只有已下达或生产中的工单可以暂停。');
            $before = $wo->status;
            foreach (MfgLaborSession::query()->where('work_order_id', $wo->id)->where('status', 'active')->lockForUpdate()->get() as $session) {
                TaskService::closeSession($session, 'paused');
            }
            $wo->update(['status' => 'paused', 'pause_reason' => $reason, 'paused_at' => now(), 'business_version' => (int) $wo->business_version + 1, 'updated_by_legacy_id' => $this->actorId($actor)]);
            $this->log($wo, 'paused', $before, 'paused', '暂停工单：'.$reason, $actor);
            return $wo->fresh();
        }, 5);
    }

    public function resume(int $id, int $expectedVersion, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($id, $expectedVersion, $actor) {
            $wo = $this->locked($id, $expectedVersion);
            if ($wo->status !== 'paused') $this->fail('status_invalid', '只有暂停的工单可以恢复。');
            $after = $wo->started_at ? 'in_progress' : 'released';
            $wo->update(['status' => $after, 'pause_reason' => null, 'paused_at' => null, 'business_version' => (int) $wo->business_version + 1, 'updated_by_legacy_id' => $this->actorId($actor)]);
            $this->log($wo, 'resumed', 'paused', $after, '恢复工单', $actor);
            return $wo->fresh();
        }, 5);
    }

    public function cancel(int $id, int $expectedVersion, string $reason, object $actor): MfgWorkOrder
    {
        return DB::transaction(function () use ($id, $expectedVersion, $reason, $actor) {
            $wo = $this->locked($id, $expectedVersion);
            if ($wo->source_type === 'sales_order' && SalesOrder::query()->whereKey($wo->sales_order_id)->where('order_status', 'confirmed')->exists())
                $this->fail('sales_order_open', '订单工单跟着订单走：订单不要做了请取消订单，数量变了请改订单。');
            $this->doCancel($wo, $reason, $actor);
            return $wo->fresh();
        }, 5);
    }

    /** 订单取消或变更时：未开工的工单一起取消；已开工的留给生产主管处理并记一笔。 */
    public function cancelForSalesOrder(SalesOrder $order, string $reason, ?object $actor): array
    {
        $result = ['cancelled' => [], 'kept' => []];
        $orders = MfgWorkOrder::query()->where('sales_order_id', $order->id)->whereNotIn('status', ['completed', 'closed', 'cancelled'])->lockForUpdate()->get();
        foreach ($orders as $wo) {
            if ($wo->started_at) {
                $this->log($wo, 'sales_order_changed', $wo->status, $wo->status, $reason.'；工单已开工，请生产主管决定继续做成库存还是暂停。', $actor);
                $result['kept'][] = $wo->work_order_no;
                continue;
            }
            $this->doCancel($wo, $reason, $actor);
            $result['cancelled'][] = $wo->work_order_no;
        }
        return $result;
    }

    /** 订单有已开工工单时，订单修改要走审核。 */
    public function salesOrderHasStartedWork(int $orderId): bool
    {
        return MfgWorkOrder::query()->where('sales_order_id', $orderId)->whereNotNull('started_at')->where('status', '!=', 'cancelled')->exists();
    }

    /** 每台都已入库或报废，工单就完工。 */
    public function refreshCompletion(MfgWorkOrder $wo, ?object $actor): void
    {
        if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) return;
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        if ($units->isEmpty() || $units->keys()->diff(['stored', 'scrapped', 'cancelled'])->isNotEmpty()) return;
        $before = $wo->status;
        $wo->update(['status' => 'completed', 'completed_at' => now(), 'business_version' => (int) $wo->business_version + 1]);
        $this->log($wo, 'completed', $before, 'completed', '每台都已入库或报废，工单完工（入库 '.(int) ($units['stored'] ?? 0).' 台，报废 '.(int) ($units['scrapped'] ?? 0).' 台）', $actor);
    }

    /** 定制技术下发车间：用定制 BOM 快照重算用料，再下达生成序列号。调用方已锁工单。 */
    public function releaseCustom(MfgWorkOrder $wo, array $bomSnapshot, object $actor): void
    {
        if ($wo->status !== 'planned') $this->fail('status_invalid', '只有“计划”状态的工单可以下发车间。');
        $wo->update(['bom_snapshot' => $bomSnapshot]);
        $this->applyPlan($wo, Item::query()->findOrFail($wo->item_id), $wo->bom_id, $wo->routing_id, true);
        if ($wo->block_reason) $this->fail('release_blocked', '不能下发：'.$wo->block_reason);
        $this->doRelease($wo, $actor);
    }

    private function doCancel(MfgWorkOrder $wo, string $reason, ?object $actor): void
    {
        if (! in_array($wo->status, self::OPEN, true) || $wo->started_at) $this->fail('status_invalid', '工单已开工或已结束，不能取消。');
        $before = $wo->status;
        $wo->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'business_version' => (int) $wo->business_version + 1, 'updated_by_legacy_id' => $this->actorId($actor)]);
        MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', ['waiting'])->update(['status' => 'cancelled']);
        $this->log($wo, 'cancelled', $before, 'cancelled', '取消工单：'.$reason, $actor);
    }

    private function doRelease(MfgWorkOrder $wo, object $actor, bool $automatic = false): void
    {
        if (! $wo->bom_id || ! $this->plans->isBomEligible((int) $wo->bom_id, (int) $wo->item_id))
            $this->fail('release_blocked', '不能下达：所选 BOM 已失效或不是这个产品的 BOM，请调整工单明确选择有效的 BOM。');
        $serials = $this->generateUnits($wo);
        $wo->update(['status' => 'released', 'released_at' => now(), 'released_by_legacy_id' => $this->actorId($actor),
            'block_reason' => null, 'business_version' => (int) $wo->business_version + 1]);
        $range = $serials ? '，生成序列号 '.(count($serials) > 1 ? $serials[0].' ~ '.end($serials) : $serials[0]) : '';
        $this->log($wo, 'released', 'planned', 'released', ($automatic ? '自动下达' : '下达工单').$range, $actor, ['serials' => $serials]);
    }

    private function newWorkOrder(array $attributes, Item $item, float $qty, object $actor): MfgWorkOrder
    {
        if ($qty <= 0) $this->fail('qty_invalid', '工单数量必须大于 0。');
        return MfgWorkOrder::create($attributes + [
            'work_order_no' => $this->numbers->next('work_order', 'WO'),
            'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
            'unit_name' => $item->unit?->unit_name, 'planned_qty' => $qty,
            'warehouse_id' => $attributes['warehouse_id'] ?? $item->default_warehouse_id,
            'status' => 'planned', 'cost_status' => 'open', 'business_version' => 1,
            'created_by_legacy_id' => $this->actorId($actor), 'created_by_name' => $this->actorName($actor),
            'updated_by_legacy_id' => $this->actorId($actor),
        ]);
    }

    /** 写入 BOM/路线快照、工序、用料；已下达工单换版本时只重建工序和用料，序列号不变。 */
    private function applyPlan(MfgWorkOrder $wo, Item $item, ?int $bomId, ?int $routingId, bool $replace = false): void
    {
        $bomSnapshot = null;
        if ($replace && (int) $bomId === (int) $wo->bom_id) {
            $snapshot = $wo->bom_snapshot;
            // 空明细或缺用量不能被转成零需求后覆盖原工单用料。
            $validSnapshot = $wo->bom_id && is_array($snapshot) && (int) ($snapshot['id'] ?? 0) === (int) $wo->bom_id
                && is_array($snapshot['items'] ?? null) && $snapshot['items'];
            if ($validSnapshot) {
                foreach ($snapshot['items'] as $line) {
                    if (! is_array($line)) {
                        $validSnapshot = false;
                        break;
                    }
                    $qty = data_get($line, 'cutting_quantity_snapshot.theoretical_qty') ?? ($line['qty'] ?? null);
                    if ((int) ($line['component_item_id'] ?? 0) <= 0 || ! is_numeric($qty) || (float) $qty < 0
                        || ! is_numeric($line['loss_rate'] ?? 0) || (float) ($line['loss_rate'] ?? 0) < 0
                        || ! is_numeric($line['fixed_qty'] ?? 0) || (float) ($line['fixed_qty'] ?? 0) < 0) {
                        $validSnapshot = false;
                        break;
                    }
                }
            }
            if ($validSnapshot) {
                $bomSnapshot = $snapshot;
            } elseif ($wo->status !== 'planned' || $snapshot !== null) {
                $this->fail('bom_snapshot_missing', '工单的 BOM 快照不完整，不能重新计算用料，请先核实原工单资料。');
            }
        }
        $plan = $this->plans->build($item, $wo->product_id, $wo->sku_id, $wo->configuration_snapshot, (float) $wo->planned_qty, $bomId, $routingId, $bomSnapshot);
        if ($replace) {
            MfgWorkOrderMaterial::query()->where('work_order_id', $wo->id)->delete();
            MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->delete();
        }
        $bom = $plan['bom'];
        $routing = $plan['routing'];
        if (MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->where('status', 'pending')->exists())
            $plan['block_reasons'][] = '定制产品：等技术上传图纸、确认定制 BOM 后下发车间';
        $wo->update([
            'bom_id' => $bom['id'] ?? $bomId, 'bom_no' => $bom['bom_no'] ?? null, 'bom_version' => isset($bom['version']) ? (string) $bom['version'] : null,
            'bom_snapshot' => $bom, 'routing_id' => $routing['routing_id'] ?? $routingId, 'routing_no' => $routing['routing_no'] ?? null,
            'routing_name' => $routing['routing_name'] ?? null, 'routing_version' => $routing['version'] ?? null,
            'routing_snapshot' => $routing,
            'block_reason' => $plan['block_reasons'] ? implode('；', $plan['block_reasons']) : null,
        ]);
        $opIds = [];
        foreach ($plan['operations'] as $op) {
            $opIds[$op['key']] = MfgWorkOrderOperation::create([
                'work_order_id' => $wo->id, 'routing_operation_id' => $op['routing_operation_id'], 'operation_id' => $op['operation_id'],
                'sequence' => $op['sequence'], 'operation_no' => $op['operation_no'], 'operation_name' => $op['operation_name'] ?: '工序',
                'execution_context' => $op['execution_context'], 'production_stage_id' => $op['production_stage_id'], 'stage_name' => $op['stage_name'],
                'work_mode' => $op['work_mode'], 'output_mode' => $op['output_mode'], 'quality_mode' => $op['quality_mode'],
                'is_key_operation' => $op['is_key_operation'], 'setup_standard_minutes' => $op['setup_standard_minutes'] ?? 0,
                'unit_standard_minutes' => $op['unit_standard_minutes'] ?? 0, 'performance_rate' => $op['performance_rate'],
                'packaging_scheme_name' => $op['packaging_scheme_name'], 'status' => 'pending',
            ])->id;
        }
        foreach ($plan['materials'] as $m) {
            MfgWorkOrderMaterial::create(collect($m)->except(['operation_key'])->all() + [
                'work_order_id' => $wo->id, 'work_order_operation_id' => $opIds[$m['operation_key']] ?? null,
            ]);
        }
        // 未开工前换了路线：已生成的台回到新的第一道车间工序。
        if ($replace && ! $wo->started_at) {
            MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', ['waiting'])->update(['current_operation_id' => $this->firstProductionOperationId($wo)]);
        }
    }

    /** 第 1 阶段下达的工单台没有记当前工序：未开工的统一放到第一道车间工序。 */
    public function placeWaitingUnits(): void
    {
        $woIds = MfgWorkOrderUnit::query()->where('status', 'waiting')->whereNull('current_operation_id')->distinct()->limit(200)->pluck('work_order_id');
        foreach (MfgWorkOrder::query()->whereIn('id', $woIds)->whereNull('started_at')->whereIn('status', ['released', 'paused'])->get() as $wo) {
            $first = $this->firstProductionOperationId($wo);
            if ($first) MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->where('status', 'waiting')->whereNull('current_operation_id')->update(['current_operation_id' => $first]);
        }
    }

    private function firstProductionOperationId(MfgWorkOrder $wo): ?int
    {
        $id = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')->orderBy('sequence')->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** 下达时按台生成序列号：整数台数才逐台跟踪；格式 前缀+年月-流水，如 SN2610-0015。 */
    private function generateUnits(MfgWorkOrder $wo): array
    {
        $qty = (float) $wo->planned_qty;
        if (MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->exists()) return MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->orderBy('unit_no')->pluck('serial_no')->all();
        if (abs($qty - round($qty)) > 0.00001 || $qty > self::UNIT_LIMIT) return [];
        $item = Item::query()->find($wo->item_id);
        $prefix = strtoupper((string) ($item?->serial_number_prefix ?: 'SN'));
        $prefix = substr(trim((string) preg_replace('/[^A-Z0-9_-]+/', '-', $prefix), '-_') ?: 'SN', 0, 30);
        $period = now()->format('ym');
        $type = substr('mfg_sn:'.$prefix, 0, 40);
        $date = now()->startOfMonth()->toDateString();
        $counter = DocumentNumber::query()->where('document_type', $type)->whereDate('number_date', $date)->lockForUpdate()->first()
            ?: DocumentNumber::create(['document_type' => $type, 'number_date' => $date, 'current_sequence' => 0]);
        $sequence = (int) $counter->current_sequence;
        $serials = [];
        $firstOp = $this->firstProductionOperationId($wo);
        for ($unit = 1; $unit <= (int) round($qty); $unit++) {
            do {
                $sequence++;
                $serial = $prefix.$period.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            } while (MfgWorkOrderUnit::query()->where('serial_no', $serial)->exists() || InventorySerial::query()->where('serial_no', $serial)->exists());
            MfgWorkOrderUnit::create(['work_order_id' => $wo->id, 'unit_no' => $unit, 'serial_no' => $serial, 'status' => 'waiting', 'current_operation_id' => $firstOp, 'warehouse_id' => $wo->warehouse_id]);
            $serials[] = $serial;
        }
        $counter->update(['current_sequence' => $sequence]);
        return $serials;
    }

    private function specialRequirement($line): ?string
    {
        $parts = array_filter([
            $line->customization_description,
            $line->electric ? '电压：'.$line->electric : null,
            $line->need_pump === null ? null : ($line->need_pump ? '配水泵' : null),
            $line->remark,
        ], fn ($v) => filled($v));
        return $parts ? implode('；', $parts) : null;
    }

    private function changeText(array $changes): string
    {
        $labels = ['owner_legacy_id' => null, 'owner_name' => '负责人', 'planned_start_date' => '计划开工', 'planned_finish_date' => '计划完工',
            'is_urgent' => '加急', 'remark' => '备注', 'warehouse_id' => '入库仓库', 'bom_id' => '换 BOM', 'routing_id' => '换工艺路线', 'planned_qty' => '数量'];
        $parts = [];
        foreach ($changes as $field => [$old, $new]) {
            if (! ($labels[$field] ?? null)) continue;
            if ($field === 'is_urgent') { $parts[] = $new ? '设为加急' : '取消加急'; continue; }
            if (in_array($field, ['bom_id', 'routing_id', 'warehouse_id'], true)) { $parts[] = $labels[$field]; continue; }
            $parts[] = $labels[$field].' '.($old === null || $old === '' ? '空' : $old).' → '.($new === null || $new === '' ? '空' : $new);
        }
        return $parts ? implode('，', $parts) : '更新信息';
    }

    private function locked(int $id, int $expectedVersion): MfgWorkOrder
    {
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($id);
        if ((int) $wo->business_version !== $expectedVersion) $this->fail('version_conflict', '工单已被其他人修改，请刷新后再操作。', 409);
        return $wo;
    }

    public function log(MfgWorkOrder $wo, string $action, ?string $before, ?string $after, string $content, ?object $actor, array $payload = [], ?int $unitId = null): void
    {
        MfgWorkOrderLog::create(['work_order_id' => $wo->id, 'unit_id' => $unitId, 'action' => $action, 'before_status' => $before, 'after_status' => $after,
            'content' => mb_substr($content, 0, 1000), 'payload' => $payload ?: null,
            'operator_legacy_id' => $actor ? $this->actorId($actor) : null, 'operator_name' => $actor ? $this->actorName($actor) : '系统']);
    }

    private function actorId(object $actor): ?int { $id = (int) ($actor->legacy_id ?? $actor->id ?? 0); return $id ?: null; }
    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? null) ?: '系统'); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
