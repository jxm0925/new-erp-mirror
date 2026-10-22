<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\MfgMaterialDelivery;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 仓库配料配送：库管按工序配料（按先入先用给出拣货库位/批次/编号，不扣库存）→ 发出（扣库存、记已领、编号登记到台）
 * → 车间核对实物签收；签收前这批料不能开工。车间拒收后库管重新送达或整单收回入库。
 * 只配“专单配送”的料（工位库存、无需逐单配送的不配；板材管材走集中开料）。车间自领（收料）仍可用，会扣掉仓库正在配的量。
 * 配好即在生产内部占住拣货来源（库存行数量和编号），其他收料、配料不再分到；发出严格按原拣货单过账，来源失效须“重新拣货”。
 */
final class MaterialDeliveryService
{
    use ProductionActor;

    private const EPS = 0.00001;
    public const STATUS_LABELS = ['prepared' => '待发出', 'dispatched' => '待签收', 'signed' => '已签收', 'rejected' => '拒收待处理',
        'recalled' => '已收回', 'cancelled' => '已取消'];
    private const OPEN_WO = ['released', 'in_progress', 'paused'];
    private const NOT_DELIVERED = ['workstation_stock', 'no_per_order_delivery'];

    public function __construct(
        private readonly MaterialIssueService $issues,
        private readonly MaterialReturnService $returns,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
    ) {}

    /** 本道仓库正在配、还没发出的数量（按物料行）。 */
    public static function preparedQty(int $operationId): array { return self::sumLines($operationId, ['prepared']); }

    /** 本道已发出还没签收（含拒收待处理）的数量（按物料行）。 */
    public static function unsignedQty(int $operationId): array { return self::sumLines($operationId, ['dispatched', 'rejected']); }

    /**
     * 待发出配料单占住的拣货来源：库存行 ID => 数量，编号 ID => true。只算还在生产中的工单；$exceptId 为排除的配料单。
     * @return array{balances: array<int, float>, serials: array<int, bool>}
     */
    public static function holds(?int $exceptId = null): array
    {
        $out = ['balances' => [], 'serials' => []];
        $rows = MfgMaterialDelivery::query()->where('status', 'prepared')->when($exceptId, fn ($q, $v) => $q->where('id', '!=', $v))
            ->whereHas('workOrder', fn ($q) => $q->whereIn('status', self::OPEN_WO))->get(['id', 'lines']);
        foreach ($rows as $d) foreach ((array) $d->lines as $l) foreach ((array) ($l['picks'] ?? []) as $p) {
            $out['balances'][(int) $p['balance_id']] = round(($out['balances'][(int) $p['balance_id']] ?? 0) + (float) $p['qty'], 4);
            foreach ((array) ($p['serial_ids'] ?? []) as $sid) $out['serials'][(int) $sid] = true;
        }
        return $out;
    }

    private static function sumLines(int $operationId, array $statuses): array
    {
        $out = [];
        foreach (MfgMaterialDelivery::query()->where('work_order_operation_id', $operationId)->whereIn('status', $statuses)->get(['lines']) as $d) {
            foreach ((array) $d->lines as $l) $out[(int) $l['material_id']] = round(($out[(int) $l['material_id']] ?? 0) + (float) $l['qty'], 4);
        }
        return $out;
    }

    /** 本道要仓库配送的料。 */
    public function deliverable(MfgWorkOrderOperation $op, bool $lock = false): Collection
    {
        return $this->issues->receivable($op, $lock)->reject(fn ($m) => in_array($m->supply_mode, self::NOT_DELIVERED, true))->values();
    }

    /** 待配料：已下达未结束工单的车间工序，还有专单配送的料没领、也没在配。车间已有台在等的排前面。 */
    public function pending(array $filters): array
    {
        $opIds = DB::table('erp_mfg_work_order_materials as m')->join('erp_mfg_work_orders as w', 'w.id', '=', 'm.work_order_id')
            ->join('erp_mfg_work_order_operations as o', 'o.id', '=', 'm.work_order_operation_id')
            ->whereIn('w.status', self::OPEN_WO)->where('o.execution_context', 'production')->where('m.participates_in_kitting', true)
            ->whereColumn('m.required_qty', '>', 'm.issued_qty')
            ->where(fn ($q) => $q->whereNull('m.supply_mode')->orWhereNotIn('m.supply_mode', self::NOT_DELIVERED))
            ->where(fn ($q) => $q->whereNull('m.cutting_mode')->orWhereNotIn('m.cutting_mode', ['sheet', 'length']))
            ->distinct()->pluck('m.work_order_operation_id');
        $ops = MfgWorkOrderOperation::query()->whereIn('id', $opIds->all() ?: [0])->get();
        $wos = MfgWorkOrder::query()->whereIn('id', $ops->pluck('work_order_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $rows = [];
        foreach ($ops as $op) {
            $wo = $wos->get($op->work_order_id);
            if (! $wo) continue;
            $prepared = self::preparedQty((int) $op->id);
            $lines = [];
            $covered = (float) $wo->planned_qty;
            foreach ($this->deliverable($op) as $m) {
                $left = round((float) $m->required_qty - (float) $m->issued_qty - (float) ($prepared[$m->id] ?? 0), 4);
                if ($left <= self::EPS) continue;
                $covered = min($covered, $this->issues->coveredUnits($m, $wo, (float) ($prepared[$m->id] ?? 0)));
                $lines[] = ['material_id' => (int) $m->id, 'component_item_name' => $m->component_item_name, 'unit_name' => $m->unit_name, 'qty' => $left];
            }
            if (! $lines) continue;
            $waiting = MfgWorkOrderUnit::query()->where('current_operation_id', $op->id)->whereIn('status', TaskService::SELECTABLE)->count();
            $rows[] = ['work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no, 'item_name' => $wo->product_name ?: $wo->item_name,
                'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status, 'is_urgent' => (bool) $wo->is_urgent,
                'planned_start_date' => $wo->planned_start_date?->toDateString(), 'planned_qty' => (float) $wo->planned_qty,
                'operation_id' => (int) $op->id, 'operation_name' => $op->operation_name, 'sequence' => (int) $op->sequence,
                'covered_units' => $covered, 'waiting_units' => $waiting, 'prepared' => (bool) $prepared, 'lines' => $lines,
                'summary' => collect($lines)->map(fn ($l) => $l['component_item_name'].' '.$this->num($l['qty']).($l['unit_name'] ?: ''))->implode('、')];
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') $rows = array_values(array_filter($rows, fn ($r) => str_contains($r['work_order_no'], $keyword) || str_contains((string) $r['item_name'], $keyword)
            || str_contains($r['operation_name'], $keyword) || str_contains($r['summary'], $keyword)));
        usort($rows, fn ($a, $b) => [$b['waiting_units'] > 0 ? 1 : 0, $b['is_urgent'] ? 1 : 0, $a['planned_start_date'] ?? '9999-12-31', $a['work_order_id'], $a['sequence']]
            <=> [$a['waiting_units'] > 0 ? 1 : 0, $a['is_urgent'] ? 1 : 0, $b['planned_start_date'] ?? '9999-12-31', $b['work_order_id'], $b['sequence']]);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        return ['data' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows), 'current_page' => $page, 'per_page' => $perPage];
    }

    /** 配料预览：这次配到第几台、每种料从哪个库位拿多少（不扣库存）。 */
    public function preview(int $workOrderId, int $operationId, ?int $units): array
    {
        [$wo, $op] = $this->context($workOrderId, $operationId, false);
        [$lines, $short, $done, $target] = $this->buildPlan($wo, $op, $units, false);
        return ['work_order_no' => $wo->work_order_no, 'operation_name' => $op->operation_name, 'planned_qty' => (float) $wo->planned_qty,
            'covered_units' => $done, 'target_units' => $target, 'lines' => $lines, 'short' => $short];
    }

    /** 生成配料单（待发出）。库存不够就整单不配，提示缺多少。 */
    public function prepare(int $workOrderId, int $operationId, ?int $units, ?string $remark, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($workOrderId, $operationId, $units, $remark, $actor) {
            [$wo, $op] = $this->context($workOrderId, $operationId, true);
            [$lines, $short, $done, $target] = $this->buildPlan($wo, $op, $units, true);
            if ($short) $this->fail('stock_short', '库存不够，这次配不了：'.implode('、', $short).'。可以少配几台，或找采购。');
            if (! $lines) $this->fail('nothing_to_deliver', $op->operation_name.'这些台的料已经收齐或正在配送。');
            $delivery = MfgMaterialDelivery::create([
                'delivery_no' => $this->numbers->next('material_delivery', 'MDL'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
                'status' => 'prepared', 'unit_qty' => round($target - $done, 4), 'lines' => $lines, 'remark' => $remark ? mb_substr($remark, 0, 500) : null,
                'prepared_by_legacy_id' => $this->actorId($actor), 'prepared_by_name' => $this->actorName($actor), 'prepared_at' => now(),
            ]);
            $this->workOrders->log($wo, 'material_delivery_prepared', $wo->status, $wo->status,
                "{$op->operation_name}仓库配料（{$delivery->delivery_no}，第 ".$this->num($done + 1).'–'.$this->num($target).' 台）：'.$this->summary($lines), $actor, ['delivery_id' => $delivery->id]);
            return $delivery;
        }, 5);
    }

    /**
     * 重新拣货：原拣货来源失效（库位停用、被出库、编号被占）时，按先入先用重新分配同样的数量，单号不变。
     */
    public function repick(int $id, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $actor) {
            $delivery = $this->locked($id, ['prepared']);
            [$wo] = $this->context((int) $delivery->work_order_id, (int) $delivery->work_order_operation_id, true);
            $materials = MfgWorkOrderMaterial::query()->whereIn('id', collect($delivery->lines)->pluck('material_id')->all() ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->issues->lockItems($materials->pluck('component_item_id'));
            $lines = [];
            $short = [];
            $changes = [];
            foreach ((array) $delivery->lines as $line) {
                $m = $materials->get((int) $line['material_id']);
                $qty = round((float) $line['qty'], 4);
                if (! $m || $qty > (float) $m->required_qty - (float) $m->issued_qty + self::EPS)
                    $this->fail('delivery_stale', ($line['component_item_name'] ?? '物料').' 车间已经自己收过料，这张配料单多了，请取消后重配。');
                [$picks, $left] = $this->issues->allocate($m, $qty, $wo, (int) $delivery->id);
                if ($left > self::EPS) { $short[] = $m->component_item_name.' 要 '.$this->num($qty).'，可用 '.$this->num($qty - $left); continue; }
                $new = $this->lineOf($m, $qty, $picks);
                $place = fn ($l) => collect($l['picks'] ?? [])->map(fn ($p) => ($p['location_code'] ?? '').($p['batch_no'] ? '/'.$p['batch_no'] : '').' '.$this->num((float) $p['qty']))->implode('，');
                if ($place($line) !== $place($new)) $changes[] = $m->component_item_name.'：'.($place($line) ?: '无').' → '.$place($new);
                $lines[] = $new;
            }
            if ($short) $this->fail('stock_short', '库存不够，重新拣货失败：'.implode('、', $short).'。可以取消这张配料单少配几台。');
            $delivery->update(['lines' => $lines]);
            $this->logFor($delivery, 'material_delivery_repicked', "配料单 {$delivery->delivery_no} 重新拣货".($changes ? '：'.implode('；', $changes) : '（来源未变）'), $actor);
            return $delivery->fresh();
        }, 5);
    }

    /** 发出：严格按拣货单的库位、批次、编号扣库存、记已领、编号登记到台；来源失效时拒绝发出，提示重新拣货。 */
    public function dispatch(int $id, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $actor) {
            $delivery = $this->locked($id, ['prepared']);
            [$wo, $op] = $this->context((int) $delivery->work_order_id, (int) $delivery->work_order_operation_id, true);
            $materials = MfgWorkOrderMaterial::query()->whereIn('id', collect($delivery->lines)->pluck('material_id')->all() ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->issues->lockItems($materials->pluck('component_item_id'));
            $plan = [];
            $problems = [];
            foreach ((array) $delivery->lines as $line) {
                $m = $materials->get((int) $line['material_id']);
                $qty = round((float) $line['qty'], 4);
                if (! $m || $qty > (float) $m->required_qty - (float) $m->issued_qty + self::EPS)
                    $this->fail('delivery_stale', ($line['component_item_name'] ?? '物料').' 车间已经自己收过料，这张配料单多了，请取消后重配。');
                $picks = array_map(fn ($p) => ['balance_id' => (int) $p['balance_id'], 'qty' => round((float) $p['qty'], 4),
                    'serial_ids' => array_map('intval', (array) ($p['serial_ids'] ?? []))], (array) ($line['picks'] ?? []));
                if (abs(array_sum(array_column($picks, 'qty')) - $qty) > self::EPS) $this->fail('delivery_source_invalid', ($line['component_item_name'] ?? '物料').' 拣货明细与配料数量不一致，请点“重新拣货”。');
                $problems = array_merge($problems ?? [], $this->issues->verifyPicks($m, $wo, $picks, (int) $delivery->id, true));
                $plan[] = ['material' => $m, 'qty' => $qty, 'picks' => $picks];
            }
            // 照单过账：实物从哪个库位、批次拿的，库存和成本就扣哪里；来源失效不自动换，避免账实不符。
            if (! empty($problems)) $this->fail('delivery_source_invalid', '拣货来源已变化，不能照单发出：'.implode('；', $problems).'。请点“重新拣货”按新库位拣货后再发出。');
            $issue = $this->issues->post($wo, $op, $plan, (float) $delivery->unit_qty, '仓库配送 '.$delivery->delivery_no, $actor);
            $delivery->update(['status' => 'dispatched', 'material_issue_id' => $issue->id,
                'dispatched_by_legacy_id' => $this->actorId($actor), 'dispatched_by_name' => $this->actorName($actor), 'dispatched_at' => now()]);
            $this->workOrders->log($wo, 'material_delivery_dispatched', $wo->status, $wo->status,
                "配料单 {$delivery->delivery_no} 已发出（领料单 {$issue->issue_no}，按拣货单扣库存），等{$op->operation_name}签收：".$this->summary((array) $delivery->lines), $actor,
                ['delivery_id' => $delivery->id, 'issue_id' => $issue->id]);
            return $delivery->fresh();
        }, 5);
    }

    /** 车间签收：核对实物无误。 */
    public function sign(int $id, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $actor) {
            $delivery = $this->locked($id, ['dispatched']);
            $delivery->update(['status' => 'signed', 'signed_by_legacy_id' => $this->actorId($actor), 'signed_by_name' => $this->actorName($actor), 'signed_at' => now()]);
            $this->logFor($delivery, 'material_delivery_signed', "配料单 {$delivery->delivery_no} 已签收", $actor);
            $this->refreshCompletion($delivery, $actor);
            return $delivery->fresh();
        }, 5);
    }

    /** 车间拒收：数量或物料不对，写明原因等库管处理。 */
    public function reject(int $id, string $reason, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $delivery = $this->locked($id, ['dispatched']);
            $delivery->update(['status' => 'rejected', 'reject_reason' => mb_substr(trim($reason), 0, 500), 'rejected_by_name' => $this->actorName($actor), 'rejected_at' => now()]);
            $this->logFor($delivery, 'material_delivery_rejected', "配料单 {$delivery->delivery_no} 被拒收：{$delivery->reject_reason}", $actor);
            return $delivery->fresh();
        }, 5);
    }

    /** 库管补齐/换对实物后重新送达，等车间再签收。 */
    public function redeliver(int $id, ?string $remark, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $remark, $actor) {
            $delivery = $this->locked($id, ['rejected']);
            $delivery->update(['status' => 'dispatched', 'close_remark' => $remark ? mb_substr($remark, 0, 500) : null,
                'dispatched_by_legacy_id' => $this->actorId($actor), 'dispatched_by_name' => $this->actorName($actor), 'dispatched_at' => now()]);
            $this->logFor($delivery, 'material_delivery_redelivered', "配料单 {$delivery->delivery_no} 重新送达".($remark ? "：{$remark}" : ''), $actor);
            return $delivery->fresh();
        }, 5);
    }

    /** 整单收回入库：按原领料单批次、单价、库位冲回，冲减已领，编号从台上拆下。 */
    public function recall(int $id, string $reason, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $delivery = $this->locked($id, ['dispatched', 'rejected']);
            [$wo, $op] = $this->context((int) $delivery->work_order_id, (int) $delivery->work_order_operation_id, true);
            $issue = MfgMaterialIssue::query()->lockForUpdate()->findOrFail($delivery->material_issue_id);
            $return = $this->returns->recallIssue($wo, $op, $issue, '配料单 '.$delivery->delivery_no.' 收回：'.trim($reason), $actor);
            $delivery->update(['status' => 'recalled', 'material_return_id' => $return->id, 'close_remark' => mb_substr(trim($reason), 0, 500),
                'closed_by_name' => $this->actorName($actor), 'closed_at' => now()]);
            $this->workOrders->log($wo, 'material_delivery_recalled', $wo->status, $wo->status,
                "配料单 {$delivery->delivery_no} 已收回入库（退料单 {$return->return_no}）：".trim($reason), $actor, ['delivery_id' => $delivery->id, 'return_id' => $return->id]);
            $this->workOrders->refreshCompletion($wo, $actor);
            return $delivery->fresh();
        }, 5);
    }

    /** 还没发出的配料单可以取消，不动库存。 */
    public function cancel(int $id, ?string $remark, object $actor): MfgMaterialDelivery
    {
        return DB::transaction(function () use ($id, $remark, $actor) {
            $delivery = $this->locked($id, ['prepared']);
            $delivery->update(['status' => 'cancelled', 'close_remark' => $remark ? mb_substr($remark, 0, 500) : null, 'closed_by_name' => $this->actorName($actor), 'closed_at' => now()]);
            $this->logFor($delivery, 'material_delivery_cancelled', "取消配料单 {$delivery->delivery_no}".($remark ? "：{$remark}" : ''), $actor);
            return $delivery->fresh();
        }, 5);
    }

    public function paginate(array $filters): array
    {
        $query = MfgMaterialDelivery::query()->with(['workOrder:id,work_order_no,item_name,product_name,is_urgent', 'operation:id,operation_name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['work_order_id'] ?? null, fn ($q, $v) => $q->where('work_order_id', $v))
            ->when(trim((string) ($filters['keyword'] ?? '')), fn ($q, $kw) => $q->where(fn ($w) => $w->where('delivery_no', 'like', "%{$kw}%")
                ->orWhereHas('workOrder', fn ($o) => $o->where('work_order_no', 'like', "%{$kw}%")->orWhere('item_name', 'like', "%{$kw}%"))))
            ->orderByDesc('id');
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $page->getCollection()->transform(fn (MfgMaterialDelivery $d) => $this->row($d));
        $counts = MfgMaterialDelivery::query()->whereIn('status', ['prepared', 'dispatched', 'rejected'])->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        return $page->toArray() + ['summary' => ['prepared' => (int) ($counts['prepared'] ?? 0), 'dispatched' => (int) ($counts['dispatched'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0)]];
    }

    public function forWorkOrder(int $workOrderId): array
    {
        return MfgMaterialDelivery::query()->with('operation:id,operation_name')->where('work_order_id', $workOrderId)->where('status', '!=', 'cancelled')
            ->orderByDesc('id')->limit(50)->get()->map(fn ($d) => $this->row($d))->values()->all();
    }

    /** 工人任务页：本道等签收（含拒收待处理）的配料单。 */
    public function forOperation(int $operationId): array
    {
        return MfgMaterialDelivery::query()->with('operation:id,operation_name')->where('work_order_operation_id', $operationId)
            ->whereIn('status', ['dispatched', 'rejected'])->orderBy('id')->get()->map(fn ($d) => $this->row($d))->values()->all();
    }

    public function row(MfgMaterialDelivery $d): array
    {
        $issueNo = $d->material_issue_id ? MfgMaterialIssue::query()->whereKey($d->material_issue_id)->value('issue_no') : null;
        $returnNo = $d->material_return_id ? MfgMaterialReturn::query()->whereKey($d->material_return_id)->value('return_no') : null;
        return [
            'id' => $d->id, 'delivery_no' => $d->delivery_no, 'status' => $d->status, 'status_label' => self::STATUS_LABELS[$d->status] ?? $d->status,
            'work_order_id' => (int) $d->work_order_id, 'work_order_no' => $d->workOrder?->work_order_no,
            'item_name' => $d->workOrder ? ($d->workOrder->product_name ?: $d->workOrder->item_name) : null, 'is_urgent' => (bool) $d->workOrder?->is_urgent,
            'operation_id' => (int) $d->work_order_operation_id, 'operation_name' => $d->operation?->operation_name,
            'unit_qty' => (float) $d->unit_qty, 'lines' => array_values((array) $d->lines), 'summary' => $this->summary((array) $d->lines), 'remark' => $d->remark,
            'prepared_by_name' => $d->prepared_by_name, 'prepared_at' => $d->prepared_at?->toDateTimeString(),
            'dispatched_by_name' => $d->dispatched_by_name, 'dispatched_at' => $d->dispatched_at?->toDateTimeString(),
            'signed_by_name' => $d->signed_by_name, 'signed_at' => $d->signed_at?->toDateTimeString(),
            'reject_reason' => $d->reject_reason, 'rejected_by_name' => $d->rejected_by_name, 'rejected_at' => $d->rejected_at?->toDateTimeString(),
            'close_remark' => $d->close_remark, 'closed_by_name' => $d->closed_by_name, 'closed_at' => $d->closed_at?->toDateTimeString(),
            'issue_no' => $issueNo, 'return_no' => $returnNo,
            'source_problems' => $d->status === 'prepared' ? $this->sourceProblems($d) : [],
        ];
    }

    /** @return array{0: array, 1: string[], 2: float, 3: float} 配料明细、缺货提示、已收够台数、配到第几台 */
    private function buildPlan(MfgWorkOrder $wo, MfgWorkOrderOperation $op, ?int $units, bool $lock): array
    {
        $materials = $this->deliverable($op, $lock);
        if ($materials->isEmpty()) $this->fail('no_material', $op->operation_name.'没有要仓库配送的料（板材管材走集中开料，工位库存的料不逐单配送）。');
        $planned = (float) $wo->planned_qty;
        $prepared = self::preparedQty((int) $op->id);
        $done = $materials->map(fn ($m) => $this->issues->coveredUnits($m, $wo, (float) ($prepared[$m->id] ?? 0)))->min();
        $target = $units ? min($planned, $done + max(1, $units)) : $planned;
        $lines = [];
        $short = [];
        foreach ($materials as $m) {
            $qty = round($this->issues->neededFor($m, $wo, $target) - (float) $m->issued_qty - (float) ($prepared[$m->id] ?? 0), 4);
            if ($qty <= self::EPS) continue;
            [$picks, $left] = $this->issues->allocate($m, $qty, $wo);
            if ($left > self::EPS) $short[] = $m->component_item_name.' 要 '.$this->num($qty).'，仓库可用 '.$this->num($qty - $left).($m->unit_name ?: '');
            $lines[] = $this->lineOf($m, $qty, $picks) + ['short_qty' => round($left, 4)];
        }
        return [$lines, $short, (float) $done, (float) $target];
    }

    /** 待发出配料单的拣货来源现在是否仍能照单出库（列表提示“来源已失效”）。 */
    private function sourceProblems(MfgMaterialDelivery $d): array
    {
        $wo = $d->workOrder ?: MfgWorkOrder::query()->find($d->work_order_id);
        if (! $wo || ! in_array($wo->status, self::OPEN_WO, true)) return [];
        $materials = MfgWorkOrderMaterial::query()->whereIn('id', collect($d->lines)->pluck('material_id')->all() ?: [0])->get()->keyBy('id');
        $out = [];
        foreach ((array) $d->lines as $line) {
            $m = $materials->get((int) $line['material_id']);
            if ($m) $out = array_merge($out, $this->issues->verifyPicks($m, $wo, (array) ($line['picks'] ?? []), (int) $d->id));
        }
        return $out;
    }

    /** 明细行：带上拣货库位、批次、编号，方便库管照单拣货。 */
    private function lineOf(MfgWorkOrderMaterial $m, float $qty, array $picks): array
    {
        $balances = InventoryBalance::query()->with(['warehouse:id,warehouse_name', 'location:id,location_code,location_name'])
            ->whereIn('id', collect($picks)->pluck('balance_id')->all() ?: [0])->get()->keyBy('id');
        $serialNos = InventorySerial::query()->whereIn('id', collect($picks)->flatMap(fn ($p) => $p['serial_ids'] ?? [])->all() ?: [0])->pluck('serial_no', 'id');
        return ['material_id' => (int) $m->id, 'component_item_id' => (int) $m->component_item_id, 'component_item_name' => $m->component_item_name,
            'component_item_code' => $m->component_item_code, 'unit_name' => $m->unit_name, 'qty' => round($qty, 4),
            'picks' => array_map(function ($p) use ($balances, $serialNos) {
                $b = $balances->get($p['balance_id']);
                return ['balance_id' => (int) $p['balance_id'], 'qty' => (float) $p['qty'], 'serial_ids' => array_map('intval', $p['serial_ids'] ?? []),
                    'serial_nos' => array_values(array_filter(array_map(fn ($sid) => $serialNos[$sid] ?? null, $p['serial_ids'] ?? []))),
                    'warehouse_name' => $b?->warehouse?->warehouse_name, 'location_code' => $b?->location?->location_code,
                    'location_name' => $b?->location?->location_name, 'batch_no' => $b?->batch_no];
            }, $picks)];
    }

    private function context(int $workOrderId, int $operationId, bool $lock): array
    {
        $wo = MfgWorkOrder::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($workOrderId);
        if (! in_array($wo->status, self::OPEN_WO, true)) $this->fail('status_invalid', '工单不在生产中，不能配送。');
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->when($lock, fn ($q) => $q->lockForUpdate())->find($operationId);
        if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
        return [$wo, $op];
    }

    private function locked(int $id, array $statuses): MfgMaterialDelivery
    {
        $delivery = MfgMaterialDelivery::query()->lockForUpdate()->findOrFail($id);
        if (! in_array($delivery->status, $statuses, true))
            $this->fail('status_invalid', '配料单 '.$delivery->delivery_no.' 现在是“'.(self::STATUS_LABELS[$delivery->status] ?? $delivery->status).'”，不能这样操作，请刷新。');
        return $delivery;
    }

    /** 已发出的配料单签收/收回后，可能不再挡住完工。 */
    private function refreshCompletion(MfgMaterialDelivery $delivery, object $actor): void
    {
        $wo = MfgWorkOrder::query()->lockForUpdate()->find($delivery->work_order_id);
        if ($wo) $this->workOrders->refreshCompletion($wo, $actor);
    }

    private function logFor(MfgMaterialDelivery $delivery, string $action, string $text, object $actor): void
    {
        $wo = MfgWorkOrder::query()->findOrFail($delivery->work_order_id);
        $this->workOrders->log($wo, $action, $wo->status, $wo->status, $text, $actor, ['delivery_id' => $delivery->id]);
    }

    private function summary(array $lines): string
    {
        return collect($lines)->map(fn ($l) => ($l['component_item_name'] ?? '').' '.$this->num((float) $l['qty']).($l['unit_name'] ?? ''))->implode('、');
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
