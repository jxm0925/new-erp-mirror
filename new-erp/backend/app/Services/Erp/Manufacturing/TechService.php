<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgDrawing;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLog;
use App\Models\Erp\MfgWorkOrderTech;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\InventoryAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * 定制技术：定制产品的订单工单先停在“计划”，技术上传设计图、确认定制 BOM 后直接下发车间（不走审核）。
 * 定制 BOM 只给这张工单用，不改 BOM 主档；下发后改图只提示车间“图纸已更新”。
 */
final class TechService
{
    use ProductionActor;

    public const STATUS_LABELS = ['pending' => '待技术处理', 'released' => '已下发车间'];

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function paginate(array $filters): array
    {
        $query = MfgWorkOrderTech::query()->with('workOrder')
            ->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'erp_mfg_work_order_techs.work_order_id')
            ->where('wo.status', '!=', 'cancelled')
            ->select('erp_mfg_work_order_techs.*');
        if ($status = $filters['status'] ?? null) $query->where('erp_mfg_work_order_techs.status', $status);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(fn ($q) => $q->where('wo.work_order_no', 'like', $like)->orWhere('wo.sales_order_no', 'like', $like)->orWhere('wo.customer_name', 'like', $like)
                ->orWhere('wo.product_name', 'like', $like)->orWhere('wo.item_name', 'like', $like)->orWhere('wo.sku_code', 'like', $like));
        }
        $counts = MfgWorkOrderTech::query()->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'erp_mfg_work_order_techs.work_order_id')
            ->where('wo.status', '!=', 'cancelled')->selectRaw('erp_mfg_work_order_techs.status AS s, COUNT(*) AS c')->groupBy('erp_mfg_work_order_techs.status')->pluck('c', 's');
        $page = $query->orderByRaw("FIELD(erp_mfg_work_order_techs.status, 'pending', 'released')")->orderByDesc('wo.is_urgent')
            ->orderBy('wo.required_date')->orderByDesc('erp_mfg_work_order_techs.id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $drawings = MfgDrawing::query()->whereIn('work_order_id', $page->getCollection()->pluck('work_order_id'))->where('status', 'active')
            ->selectRaw('work_order_id, COUNT(*) AS c')->groupBy('work_order_id')->pluck('c', 'work_order_id');
        $page->getCollection()->transform(function (MfgWorkOrderTech $tech) use ($drawings) {
            $wo = $tech->workOrder;
            return [
                'work_order_id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'status' => $tech->status, 'status_label' => self::STATUS_LABELS[$tech->status] ?? $tech->status,
                'work_order_status' => $wo->status, 'work_order_status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
                'sales_order_no' => $wo->sales_order_no, 'customer_name' => $wo->customer_name, 'product_name' => $wo->product_name ?: $wo->item_name,
                'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name, 'planned_qty' => (float) $wo->planned_qty, 'unit_name' => $wo->unit_name,
                'required_date' => $wo->required_date?->toDateString(), 'is_urgent' => (bool) $wo->is_urgent,
                'custom_description' => $this->customDescription($wo), 'drawing_count' => (int) ($drawings[$wo->id] ?? 0),
                'bom_confirmed' => $this->bomConfirmed($tech, $wo), 'tech_owner_name' => $tech->tech_owner_name,
                'released_at' => $tech->released_at?->toDateTimeString(), 'drawings_updated_at' => $tech->drawings_updated_at?->toDateTimeString(),
                'created_at' => $tech->created_at?->toDateTimeString(),
            ];
        });
        return $page->toArray() + ['summary' => ['pending' => (int) ($counts['pending'] ?? 0), 'released' => (int) ($counts['released'] ?? 0)]];
    }

    public function detail(int $workOrderId): array
    {
        $wo = MfgWorkOrder::query()->with('operations')->findOrFail($workOrderId);
        $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->first();
        if (! $tech) $this->fail('not_custom', '这张工单不是定制工单。', 404);
        $base = $this->baseItems($tech, $wo);
        $lines = $this->currentLines($tech, $wo, $base);
        $ops = $wo->operations->where('execution_context', 'production')->values();
        $opNames = $ops->pluck('operation_name', 'routing_operation_id');
        $itemIds = array_merge(array_column($base, 'component_item_id'), array_column($lines, 'component_item_id'));
        $items = Item::query()->with('unit')->whereIn('id', $itemIds ?: [0])->get()->keyBy('id');
        $available = $this->availability->availableBaseQuantities(array_values(array_unique($itemIds)));
        $baseById = collect($base)->keyBy('id');
        $rows = [];
        $usedBase = [];
        foreach ($lines as $line) {
            $b = $line['base_line_id'] ? $baseById->get($line['base_line_id']) : null;
            if ($b) $usedBase[] = $b['id'];
            $changed = $b && ((int) $b['component_item_id'] !== (int) $line['component_item_id'] || abs($b['qty'] - $line['qty']) > 0.00001
                || abs($b['loss_rate'] - $line['loss_rate']) > 0.00001 || abs($b['fixed_qty'] - $line['fixed_qty']) > 0.00001
                || (int) ($b['target_routing_operation_id'] ?? 0) !== (int) ($line['target_routing_operation_id'] ?? 0));
            $rows[] = $this->lineRow($line, $items, $available, $opNames, $b, $b ? ($changed ? 'changed' : 'same') : 'added', (float) $wo->planned_qty);
        }
        foreach ($base as $b) {
            if (! in_array($b['id'], $usedBase, true)) $rows[] = $this->lineRow(array_merge($b, ['qty' => 0, 'base_line_id' => $b['id']]), $items, $available, $opNames, $b, 'removed', (float) $wo->planned_qty);
        }
        $requiredByItem = collect($rows)->where('change', '!=', 'removed')->groupBy('component_item_id')
            ->map(fn ($itemRows) => round($itemRows->sum('required_qty'), 4));
        foreach ($rows as &$row) {
            if ($row['change'] !== 'removed') $row['required_qty'] = $requiredByItem[$row['component_item_id']];
        }
        unset($row);
        $drawings = MfgDrawing::query()->where('work_order_id', $wo->id)->whereIn('status', ['active', 'replaced'])->orderByDesc('id')->get();
        $line = $wo->sales_order_line_id ? SalesOrderLine::query()->find($wo->sales_order_line_id) : null;
        $attachments = SalesOrderAttachment::query()->where('status', 'active')
            ->where(fn ($q) => $q->when($wo->sales_order_line_id, fn ($w) => $w->where('sales_order_line_id', $wo->sales_order_line_id))
                ->orWhere(fn ($w) => $w->where('sales_order_id', $wo->sales_order_id ?: 0)->where('attachment_scope', 'order')
                    ->whereIn('attachment_type', ['design_drawing', 'customer_drawing', 'technical_agreement', 'configuration_note'])))
            ->orderBy('id')->get(['id', 'original_name', 'url', 'attachment_type', 'mime_type', 'uploaded_by', 'uploaded_at']);
        $activeDrawings = $drawings->where('status', 'active')->values();
        $shortages = collect($rows)->where('change', '!=', 'removed')->unique('component_item_id')
            ->filter(fn ($r) => $r['available_qty'] < $r['required_qty'] - 0.00001)
            ->map(fn ($r) => $r['component_item_name'].' 可用 '.$this->num($r['available_qty']).($r['unit_name'] ?: ''))->values()->all();
        $routingBlock = $wo->block_reason && str_contains($wo->block_reason, '路线');
        return [
            'work_order' => [
                'id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'status' => $wo->status, 'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
                'item_name' => $wo->item_name, 'item_code' => $wo->item_code, 'product_name' => $wo->product_name ?: $wo->item_name, 'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
                'planned_qty' => (float) $wo->planned_qty, 'unit_name' => $wo->unit_name, 'required_date' => $wo->required_date?->toDateString(), 'is_urgent' => (bool) $wo->is_urgent,
                'customer_name' => $wo->customer_name, 'sales_order_id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'sales_order_line_no' => $wo->sales_order_line_no,
                'owner_name' => $wo->owner_name, 'block_reason' => $wo->block_reason, 'started' => (bool) $wo->started_at,
            ],
            'tech' => [
                'status' => $tech->status, 'status_label' => self::STATUS_LABELS[$tech->status] ?? $tech->status, 'bom_confirmed' => $this->bomConfirmed($tech, $wo),
                'tech_owner_name' => $tech->tech_owner_name, 'released_by_name' => $tech->released_by_name, 'released_at' => $tech->released_at?->toDateTimeString(),
                'drawings_updated_at' => $tech->drawings_updated_at?->toDateTimeString(), 'remark' => $tech->remark,
            ],
            'custom_description' => $this->customDescription($wo, $line),
            'configuration' => $line ? array_values(array_filter([$line->electric ? '电压：'.$line->electric : null, $line->need_pump ? '配水泵' : null, $line->remark ?: null])) : [],
            'reference_attachments' => $attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => $a->url, 'type' => $a->attachment_type,
                'mime_type' => $a->mime_type, 'uploaded_by' => $a->uploaded_by, 'uploaded_at' => $a->uploaded_at?->toDateTimeString()])->values()->all(),
            'drawings' => $activeDrawings->map(fn ($d) => $this->drawingRow($d))->values()->all(),
            'drawing_history' => $drawings->where('status', 'replaced')->map(fn ($d) => $this->drawingRow($d))->values()->all(),
            'base_bom' => $wo->bom_id ? ['id' => $wo->bom_id, 'bom_no' => $wo->bom_no, 'version' => $wo->bom_version,
                'bom_name' => data_get($wo->bom_snapshot, 'bom_name')] : null,
            'bom_lines' => $rows,
            'bom_changes' => ['changed' => collect($rows)->where('change', 'changed')->count(), 'added' => collect($rows)->where('change', 'added')->count(),
                'removed' => collect($rows)->where('change', 'removed')->count(), 'same' => collect($rows)->where('change', 'same')->count()],
            'operations' => $ops->map(fn ($op) => ['routing_operation_id' => $op->routing_operation_id, 'operation_name' => $op->operation_name, 'sequence' => $op->sequence])->values()->all(),
            'routing' => $wo->routing_id ? ['id' => $wo->routing_id, 'routing_no' => $wo->routing_no, 'routing_name' => $wo->routing_name, 'version' => $wo->routing_version,
                'path' => $wo->operations->pluck('operation_name')->implode(' › ')] : null,
            'checks' => [
                ['ok' => $activeDrawings->isNotEmpty(), 'text' => $activeDrawings->isNotEmpty() ? '已上传设计图（'.$activeDrawings->count().' 份）' : '还没上传设计图'],
                ['ok' => $this->bomConfirmed($tech, $wo), 'text' => $this->bomConfirmed($tech, $wo) ? '定制 BOM 已确认' : '定制 BOM 还没确认'],
                ['ok' => $wo->bom_id && $wo->routing_id && ! $routingBlock, 'text' => ! $wo->bom_id ? '没有基础 BOM，请在工单里选 BOM' : ($wo->routing_id && ! $routingBlock ? '工艺路线已选' : '工艺路线没选好')],
                ...array_map(fn ($s) => ['ok' => null, 'text' => $s.'，缺料只提醒'], array_slice($shortages, 0, 5)),
            ],
            'logs' => MfgWorkOrderLog::query()->where('work_order_id', $wo->id)->whereIn('action', ['created', 'tech_bom', 'tech_drawing', 'tech_released', 'released', 'adjusted'])
                ->orderByDesc('id')->limit(20)->get()->map(fn ($l) => ['content' => $l->content, 'operator_name' => $l->operator_name, 'created_at' => $l->created_at?->toDateTimeString()])->values()->all(),
        ];
    }

    /** 保存定制 BOM；confirmed=true 表示技术确认可以按这份做。下发后不能再改。 */
    public function saveBom(int $workOrderId, array $rows, bool $confirmed, ?string $remark, object $actor): void
    {
        DB::transaction(function () use ($workOrderId, $rows, $confirmed, $remark, $actor) {
            [$wo, $tech] = $this->lock($workOrderId);
            if ($tech->status !== 'pending' || $wo->status !== 'planned') $this->fail('released', '已下发车间，定制 BOM 不能再改；要改请找生产主管调整工单。');
            $base = $this->baseItems($tech, $wo);
            $baseIds = array_column($base, 'id');
            $routingOps = $wo->operations()->where('execution_context', 'production')->pluck('routing_operation_id')->map(fn ($v) => (int) $v)->all();
            $items = Item::query()->with('unit')->whereIn('id', array_map(fn ($r) => (int) ($r['component_item_id'] ?? 0), $rows) ?: [0])->get()->keyBy('id');
            $lines = [];
            $seenBase = [];
            foreach (array_values($rows) as $i => $row) {
                $n = $i + 1;
                $item = $items->get((int) ($row['component_item_id'] ?? 0));
                if (! $item) $this->fail('item_invalid', "第 {$n} 行物料不存在。");
                $qty = (float) ($row['qty'] ?? 0);
                $loss = (float) ($row['loss_rate'] ?? 0);
                $fixed = (float) ($row['fixed_qty'] ?? 0);
                if ($qty <= 0) $this->fail('qty_invalid', "第 {$n} 行（{$item->item_name}）用量要大于 0；不要的料直接删掉这行。");
                if ($loss < 0 || $loss >= 100 || $fixed < 0) $this->fail('qty_invalid', "第 {$n} 行损耗率或固定用量不对。");
                $baseLine = isset($row['base_line_id']) && $row['base_line_id'] !== null ? (int) $row['base_line_id'] : null;
                if ($baseLine !== null && (! in_array($baseLine, $baseIds, true) || in_array($baseLine, $seenBase, true))) $this->fail('base_invalid', "第 {$n} 行对应的标准 BOM 行不对，请刷新后重试。");
                if ($baseLine !== null) $seenBase[] = $baseLine;
                $target = (int) ($row['target_routing_operation_id'] ?? 0) ?: null;
                if ($target && ! in_array($target, $routingOps, true)) $this->fail('operation_invalid', "第 {$n} 行用在的工序不在这张工单的路线里。");
                $lines[] = ['base_line_id' => $baseLine, 'component_item_id' => $item->id, 'component_item_code' => $item->item_code, 'component_item_name' => $item->item_name,
                    'qty' => round($qty, 6), 'loss_rate' => round($loss, 4), 'fixed_qty' => round($fixed, 6), 'target_routing_operation_id' => $target,
                    'remark' => mb_substr(trim((string) ($row['remark'] ?? '')), 0, 200) ?: null];
            }
            if (! $lines) $this->fail('lines_required', '定制 BOM 至少要有一行物料。');
            $tech->update(['custom_bom' => ['base_bom_id' => $wo->bom_id, 'base_routing_id' => $wo->routing_id, 'base_routing_version' => $wo->routing_version,
                'base_items' => $base, 'lines' => $lines], 'bom_confirmed' => $confirmed,
                'remark' => $remark !== null ? (mb_substr($remark, 0, 500) ?: null) : $tech->remark] + $this->ownerFields($tech, $actor));
            $byBase = collect($lines)->whereNotNull('base_line_id')->keyBy('base_line_id');
            $changed = collect($base)->filter(fn ($b) => ($l = $byBase->get($b['id'])) && ((int) $l['component_item_id'] !== (int) $b['component_item_id']
                || abs($l['qty'] - $b['qty']) > 0.00001 || abs($l['loss_rate'] - $b['loss_rate']) > 0.00001 || abs($l['fixed_qty'] - $b['fixed_qty']) > 0.00001
                || (int) ($l['target_routing_operation_id'] ?? 0) !== (int) ($b['target_routing_operation_id'] ?? 0)))->count();
            $added = collect($lines)->whereNull('base_line_id')->count();
            $removed = count($base) - $byBase->count();
            $this->workOrders->log($wo, 'tech_bom', null, null, ($confirmed ? '确认' : '保存').'定制 BOM（改 '.$changed.' 行、加 '.$added.' 行、删 '.$removed.' 行）', $actor);
        }, 5);
    }

    /** @param array{original_name:string, storage_path:string, url:string, mime_type:?string, file_size:int, file_hash:?string} $file */
    public function uploadDrawing(int $workOrderId, array $file, ?string $drawingName, ?int $replaceId, object $actor): MfgDrawing
    {
        return DB::transaction(function () use ($workOrderId, $file, $drawingName, $replaceId, $actor) {
            [$wo, $tech] = $this->lock($workOrderId);
            if (in_array($wo->status, ['completed', 'closed', 'cancelled'], true)) $this->fail('status_invalid', '工单已结束，不能再传图纸。');
            $old = null;
            if ($replaceId) {
                $old = MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->lockForUpdate()->find($replaceId);
                if (! $old) $this->fail('drawing_invalid', '要替换的图纸不存在或已被替换。');
                $old->update(['status' => 'replaced']);
            }
            $name = trim((string) $drawingName) ?: ($old?->drawing_name ?: pathinfo($file['original_name'], PATHINFO_FILENAME));
            $drawing = MfgDrawing::create($file + [
                'work_order_id' => $wo->id, 'drawing_name' => mb_substr($name, 0, 200), 'version_no' => $old ? $old->version_no + 1 : 1,
                'replaced_drawing_id' => $old?->id, 'storage_disk' => 'public', 'status' => 'active',
                'uploaded_by_legacy_id' => $this->actorId($actor), 'uploaded_by' => $this->actorName($actor), 'uploaded_at' => now(),
            ]);
            $released = $tech->status === 'released';
            $tech->update($this->ownerFields($tech, $actor) + ($released ? ['drawings_updated_at' => now()] : []));
            $text = ($old ? "替换图纸 {$drawing->drawing_name} V{$drawing->version_no}（原 V{$old->version_no}）" : "上传图纸 {$drawing->drawing_name} V{$drawing->version_no}").($released ? '，车间会看到“图纸已更新”' : '');
            $this->workOrders->log($wo, 'tech_drawing', null, null, $text, $actor);
            return $drawing;
        }, 5);
    }

    public function deleteDrawing(int $drawingId, object $actor): void
    {
        DB::transaction(function () use ($drawingId, $actor) {
            $woId = MfgDrawing::query()->whereKey($drawingId)->value('work_order_id');
            if (! $woId) $this->fail('not_found', '图纸不存在。', 404);
            [$wo, $tech] = $this->lock((int) $woId);
            $drawing = MfgDrawing::query()->where('status', 'active')->lockForUpdate()->find($drawingId);
            if (! $drawing) $this->fail('drawing_invalid', '图纸已删除或已被替换。');
            $released = $tech->status === 'released';
            if ($released && MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->count() <= 1)
                $this->fail('last_drawing', '已下发车间，至少要留一张图纸；要换图请用“替换”。');
            $drawing->update(['status' => 'deleted']);
            if ($released) $tech->update(['drawings_updated_at' => now()]);
            $this->workOrders->log($wo, 'tech_drawing', null, null, "删除图纸 {$drawing->drawing_name} V{$drawing->version_no}", $actor);
        }, 5);
    }

    /** 下发车间：至少一张图纸 + 定制 BOM 已确认；用定制 BOM 重算用料后下达、生成序列号。 */
    public function release(int $workOrderId, ?string $remark, object $actor): void
    {
        DB::transaction(function () use ($workOrderId, $remark, $actor) {
            [$wo, $tech] = $this->lock($workOrderId);
            if ($tech->status !== 'pending') $this->fail('released', '已经下发车间了。');
            if (! MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->exists()) $this->fail('drawing_required', '请先上传设计图。');
            if (! $this->bomConfirmed($tech, $wo)) $this->fail('bom_unconfirmed', '请先确认定制 BOM。');
            if (! $wo->bom_id || ! is_array($wo->bom_snapshot)) $this->fail('bom_required', '工单没有基础 BOM，请先让生产主管在工单里选 BOM。');
            $snapshot = $wo->bom_snapshot;
            if ($tech->custom_bom) {
                $base = collect($tech->custom_bom['base_items'] ?? [])->keyBy('id');
                $raw = collect($snapshot['items'] ?? [])->keyBy(fn ($l) => (string) ($l['id'] ?? ''));
                $snapshot['items'] = array_map(function ($line) use ($base, $raw) {
                    $orig = $line['base_line_id'] ? ($raw->get((string) $line['base_line_id']) ?? []) : [];
                    $b = $line['base_line_id'] ? $base->get($line['base_line_id']) : null;
                    $sameItem = $b && (int) $b['component_item_id'] === (int) $line['component_item_id'];
                    $out = array_merge($sameItem ? $orig : [], [
                        'id' => $line['base_line_id'], 'component_item_id' => $line['component_item_id'], 'component_item_code' => $line['component_item_code'],
                        'component_item_name' => $line['component_item_name'], 'qty' => $line['qty'], 'loss_rate' => $line['loss_rate'], 'fixed_qty' => $line['fixed_qty'],
                        'remark' => $line['remark'], 'target_routing_operation_id' => $line['target_routing_operation_id'], 'custom' => true,
                    ]);
                    if (isset($out['cutting_quantity_snapshot']) && is_array($out['cutting_quantity_snapshot'])) $out['cutting_quantity_snapshot']['theoretical_qty'] = $line['qty'];
                    return $out;
                }, $tech->custom_bom['lines'] ?? []);
            }
            $snapshot['custom'] = true;
            $tech->update(['status' => 'released', 'released_by_legacy_id' => $this->actorId($actor), 'released_by_name' => $this->actorName($actor),
                'released_at' => now(), 'remark' => $remark !== null && $remark !== '' ? mb_substr($remark, 0, 500) : $tech->remark] + $this->ownerFields($tech, $actor));
            $this->workOrders->releaseCustom($wo, $snapshot, $actor);
            $this->workOrders->log($wo, 'tech_released', 'planned', 'released', '技术下发车间'.($remark ? '：'.$remark : ''), $actor);
        }, 5);
    }

    private function lock(int $workOrderId): array
    {
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
        $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->lockForUpdate()->first();
        if (! $tech) $this->fail('not_custom', '这张工单不是定制工单。', 404);
        return [$wo, $tech];
    }

    private function ownerFields(MfgWorkOrderTech $tech, object $actor): array
    {
        return $tech->tech_owner_legacy_id ? [] : ['tech_owner_legacy_id' => $this->actorId($actor), 'tech_owner_name' => $this->actorName($actor)];
    }

    /** 定制 BOM 的比对基准：第一次保存时冻结；工单换了 BOM 后以新 BOM 为准、需重新确认。 */
    private function baseItems(MfgWorkOrderTech $tech, MfgWorkOrder $wo): array
    {
        $custom = $tech->custom_bom;
        if (is_array($custom) && (int) ($custom['base_bom_id'] ?? 0) === (int) $wo->bom_id && isset($custom['base_items'])) return $custom['base_items'];
        if ($tech->status === 'released') return $custom['base_items'] ?? [];
        // 标准 BOM 行按工序主档指定的用料工序，换算成本工单路线里的车间节点，作为定制 BOM 的默认工序。
        $byOperation = collect((array) data_get($wo->routing_snapshot, 'operations', []))
            ->filter(fn ($op) => ($op['execution_context'] ?? 'production') === 'production')->sortBy('sequence')
            ->groupBy(fn ($op) => (int) ($op['operation_id'] ?? 0))->map(fn ($ops) => (int) $ops->first()['routing_operation_id']);
        return array_values(array_map(fn ($l) => [
            'id' => isset($l['id']) ? (int) $l['id'] : null, 'component_item_id' => (int) ($l['component_item_id'] ?? 0),
            'component_item_code' => $l['component_item_code'] ?? null, 'component_item_name' => $l['component_item_name'] ?? null,
            'qty' => (float) (data_get($l, 'cutting_quantity_snapshot.theoretical_qty') ?? ($l['qty'] ?? 0)),
            'loss_rate' => (float) ($l['loss_rate'] ?? 0), 'fixed_qty' => (float) ($l['fixed_qty'] ?? 0),
            'target_routing_operation_id' => isset($l['target_routing_operation_id']) ? (int) $l['target_routing_operation_id']
                : (! empty($l['operation_id']) ? ($byOperation[(int) $l['operation_id']] ?? null) : null), 'remark' => $l['remark'] ?? null,
        ], array_filter((array) data_get($wo->bom_snapshot, 'items', []), fn ($l) => is_array($l) && isset($l['id']))));
    }

    private function currentLines(MfgWorkOrderTech $tech, MfgWorkOrder $wo, array $base): array
    {
        $custom = $tech->custom_bom;
        if (is_array($custom) && ($tech->status === 'released' || (int) ($custom['base_bom_id'] ?? 0) === (int) $wo->bom_id)) return $custom['lines'] ?? [];
        return array_map(fn ($b) => array_merge($b, ['base_line_id' => $b['id']]), $base);
    }

    private function bomConfirmed(MfgWorkOrderTech $tech, MfgWorkOrder $wo): bool
    {
        if ($tech->status === 'released') return true;
        if (! $tech->bom_confirmed) return false;
        $custom = $tech->custom_bom;
        // 旧待下发确认缺少路线身份时重新签认，保留原定制行供技术核对。
        return is_array($custom) && (int) ($custom['base_bom_id'] ?? 0) === (int) $wo->bom_id
            && isset($custom['base_routing_id'], $custom['base_routing_version'])
            && (int) $custom['base_routing_id'] === (int) $wo->routing_id
            && (int) $custom['base_routing_version'] === (int) $wo->routing_version;
    }

    private function lineRow(array $line, $items, array $available, $opNames, ?array $base, string $change, float $plannedQty): array
    {
        $item = $items->get((int) $line['component_item_id']);
        $target = $line['target_routing_operation_id'] ?? null;
        return [
            'key' => ($line['base_line_id'] ?? 'n').'-'.$line['component_item_id'], 'base_line_id' => $line['base_line_id'] ?? null,
            'component_item_id' => (int) $line['component_item_id'], 'component_item_code' => $item?->item_code ?? $line['component_item_code'],
            'component_item_name' => $item?->item_name ?? $line['component_item_name'], 'spec' => $item?->spec, 'unit_name' => $item?->unit?->unit_name,
            'base_qty' => $base ? (float) $base['qty'] : null, 'qty' => (float) $line['qty'], 'loss_rate' => (float) ($line['loss_rate'] ?? 0), 'fixed_qty' => (float) ($line['fixed_qty'] ?? 0),
            'base_item_name' => $base && (int) $base['component_item_id'] !== (int) $line['component_item_id'] ? $base['component_item_name'] : null,
            'target_routing_operation_id' => $target, 'operation_name' => $target ? ($opNames[$target] ?? null) : null,
            'remark' => $line['remark'] ?? null, 'change' => $change,
            'required_qty' => $change === 'removed' ? 0 : WorkOrderPlanBuilder::requiredQuantity($line, $plannedQty),
            'available_qty' => round((float) ($available[(int) $line['component_item_id']] ?? 0), 4),
        ];
    }

    private function drawingRow(MfgDrawing $d): array
    {
        return ['id' => $d->id, 'drawing_name' => $d->drawing_name, 'version_no' => $d->version_no, 'original_name' => $d->original_name, 'url' => $d->url,
            'mime_type' => $d->mime_type, 'file_size' => $d->file_size, 'status' => $d->status, 'replaced_drawing_id' => $d->replaced_drawing_id,
            'uploaded_by' => $d->uploaded_by, 'uploaded_at' => $d->uploaded_at?->toDateTimeString()];
    }

    public function customDescription(MfgWorkOrder $wo, ?SalesOrderLine $line = null): ?string
    {
        $line ??= $wo->sales_order_line_id ? SalesOrderLine::query()->find($wo->sales_order_line_id, ['id', 'customization_description', 'configuration_snapshot']) : null;
        return ($line?->customization_description ?: data_get($line?->configuration_snapshot, 'special_custom_description')) ?: $wo->special_requirement;
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0'; }
}
