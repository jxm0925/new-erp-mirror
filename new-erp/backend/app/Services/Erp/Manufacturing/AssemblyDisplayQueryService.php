<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgDrawing;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderChange;
use App\Services\Erp\ErpUserProjectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** 公共通知屏仅投影展示字段；不调用会补快照、落位或同步状态的生产查询。 */
final class AssemblyDisplayQueryService
{
    private const STATES = ['wait' => ['planned', 'released'], 'accept' => ['in_progress', 'paused'],
        'complete' => ['completed', 'closed'], 'cancel' => ['cancelled']];

    public function paginate(array $filters): array
    {
        $query = $this->filtered($filters);
        $page = $query->with(['salesOrder.lines.attachments', 'operations', 'units'])
            ->orderBy('priority')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
        $salespeople = app(ErpUserProjectionService::class)->many($page->getCollection()->pluck('salesOrder.sales_user_legacy_id')->all());
        $shippingStages = $this->shippingStages($page->getCollection()->pluck('id')->all());
        $drawings = $this->drawings($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($wo) => $this->row($wo, $salespeople, $shippingStages[$wo->id] ?? null, $drawings[$wo->id] ?? [])));

        // 同一订单可拆成多张工单，金额按关联订单去重，不按工单重复相加。
        $today = $this->base($filters['type'] ?? 'assemble')->whereDate('created_at', now()->toDateString());
        $stats = ['today_orders' => (clone $today)->count(), 'sales_amount' => (string) SalesOrder::query()
            ->whereIn('id', (clone $today)->whereNotNull('sales_order_id')->select('sales_order_id'))->sum('final_receivable_amount')];
        $urgent = $this->filtered(['type' => 'assemble', 'status' => 'wait'])
            ->whereHas('salesOrder', fn ($q) => $q->whereDate('required_delivery_date', now()->toDateString()))
            ->orderBy('created_at')->get(['id', 'work_order_no', 'created_at', 'released_at']);

        return ['list' => $page->toArray(), 'stats' => $stats, 'urgent_orders' => $urgent->map(fn ($wo) => [
            'id' => $wo->id, 'work_order_no' => $wo->work_order_no,
            'wait_seconds' => max(0, (int) ($wo->released_at ?? $wo->created_at)?->diffInSeconds(now(), false)),
        ])->all(), 'server_time' => now()->toIso8601String()];
    }

    public function detail(int $id): array
    {
        $wo = $this->base('all')->with(['salesOrder.lines.attachments', 'operations', 'units'])->findOrFail($id);
        $salespeople = app(ErpUserProjectionService::class)->many([$wo->salesOrder?->sales_user_legacy_id]);
        return $this->row($wo, $salespeople, $this->shippingStages([$id])[$id] ?? null, $this->drawings([$id])[$id] ?? []);
    }

    public function history(int $id): array
    {
        $wo = $this->base('all')->with('salesOrder.lines.attachments')->findOrFail($id);
        if (! $wo->salesOrder) return ['versions' => []];
        $changes = SalesOrderChange::query()->where('sales_order_id', $wo->sales_order_id)->whereNotNull('applied_at')->orderBy('id')->get();
        // 原数据取首次正式变更之前的事实；无变更时展示当前内容，不虚构修改记录。
        $original = $changes->first()?->before_snapshot ?? $wo->salesOrder->toArray();
        $versions = [['id' => 'original', 'label' => '原数据', 'is_original' => true,
            'actor_name' => $original['created_by'] ?? $wo->created_by_name,
            'occurred_at' => $wo->created_at?->toIso8601String(), 'snapshot' => $this->snapshot($original)]];
        foreach ($changes as $index => $change) $versions[] = [
            'id' => $change->id, 'label' => '第'.($index + 1).'次修改', 'is_original' => false,
            'actor_name' => $change->operator, 'occurred_at' => $change->applied_at?->toIso8601String(),
            'snapshot' => $this->snapshot((array) $change->after_snapshot),
        ];
        return ['versions' => $versions];
    }

    public function change(int $id): array
    {
        $change = SalesOrderChange::query()->whereNotNull('applied_at')->findOrFail($id);
        $wo = $this->base('all')->where('sales_order_id', $change->sales_order_id)
            ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")->orderByDesc('id')->firstOrFail();
        return ['id' => $change->id, 'work_order_no' => $wo->work_order_no, 'sales_order_id' => (int) $change->sales_order_id,
            'before' => $this->snapshot((array) $change->before_snapshot),
            'after' => $this->snapshot((array) $change->after_snapshot),
            'actor_name' => $change->operator, 'occurred_at' => $change->applied_at?->toIso8601String()];
    }

    private function base(string $type): Builder
    {
        $query = MfgWorkOrder::query();
        if ($type === 'assemble') $query->whereHas('operations', fn ($q) => $q
            ->whereIn('production_stage_id', DB::table('erp_production_stages')->where('code', 'ASSEMBLY')->select('id')));
        if ($type === 'testing') $query->whereIn('id', $this->shippingStates()->select('work_order_id'));
        if ($type === 'produce') $query->whereHas('operations', fn ($q) => $q->where('execution_context', 'production'));
        return $query;
    }

    private function filtered(array $filters): Builder
    {
        $query = $this->base($filters['type'] ?? 'assemble');
        $claimed = fn ($q) => $q->selectRaw('1')->from('erp_mfg_task_participants as display_owner')
            ->whereColumn('display_owner.work_order_id', 'erp_mfg_work_orders.id')->where('display_owner.role', 'owner')->where('display_owner.is_active', true);
        $status = $filters['status'] ?? 'wait';
        if (($filters['type'] ?? 'assemble') === 'testing') {
            $jobs = $this->shippingStates()->select('work_order_id');
            if ($status === 'wait') $jobs->where('unclaimed_count', '>', 0)->where('active_count', '=', 0);
            elseif ($status === 'accept') $jobs->where('active_count', '>', 0);
            elseif ($status === 'complete') $jobs->where('unfinished_count', '=', 0)->where('completed_count', '>', 0);
            else $jobs->where('non_cancelled_count', '=', 0);
            $query->whereIn('id', $jobs);
        } elseif ($status === 'wait') $query->whereIn('status', self::STATES['wait'])->whereNotExists($claimed);
        elseif ($status === 'accept') $query->where(fn ($q) => $q->whereIn('status', self::STATES['accept'])
            ->orWhere(fn ($q) => $q->whereIn('status', self::STATES['wait'])->whereExists($claimed)));
        else $query->whereIn('status', self::STATES[$status]);
        foreach (['create_from', 'create_to'] as $key) if (! empty($filters[$key])) $query->whereDate('created_at', $key === 'create_from' ? '>=' : '<=', $filters[$key]);
        if (! empty($filters['customer'])) {
            $like = '%'.$filters['customer'].'%';
            $query->where(fn ($q) => $q->where('customer_name', 'like', $like)->orWhereHas('salesOrder', fn ($o) => $o
                ->where('customer_name', 'like', $like)->orWhere('customer_name_snapshot', 'like', $like)
                ->orWhere('contact_phone_snapshot', 'like', $like)->orWhere('contact_phone', 'like', $like)->orWhere('customer_phone', 'like', $like)));
        }
        if (! empty($filters['product_name'])) {
            $like = '%'.$filters['product_name'].'%';
            $query->where(fn ($q) => $q->where('product_name', 'like', $like)->orWhere('sku_name', 'like', $like)->orWhere('item_name', 'like', $like));
        }
        if (! empty($filters['order_no'])) {
            $like = '%'.$filters['order_no'].'%';
            $query->where(fn ($q) => $q->where('work_order_no', 'like', $like)->orWhere('sales_order_no', 'like', $like));
        }
        $type = $filters['order_type'] ?? 'all';
        $urgent = fn ($q) => $q->where('is_urgent', true)->orWhereHas('salesOrder', fn ($o) => $o->where('is_urgent', true)->orWhere('quickly', true));
        $delayed = fn ($q) => $q->whereHas('salesOrder', fn ($o) => $o->where('is_delay', true)->orWhere('delay', true));
        $customized = fn ($q) => $q->whereHas('salesOrder', fn ($o) => $o->where('is_customized', true)->orWhere('is_special_customized', true))
            ->orWhereExists(fn ($line) => $line->selectRaw('1')->from('erp_sales_order_lines as display_custom_line')
                ->whereColumn('display_custom_line.id', 'erp_mfg_work_orders.sales_order_line_id')->where('display_custom_line.is_special_customized', true));
        if ($type === 'urgent') $query->where($urgent);
        elseif ($type === 'delayed') $query->where($delayed);
        elseif ($type === 'customized') $query->where($customized);
        elseif ($type === 'ordinary') $query->whereNot($urgent)->whereNot($delayed)->whereNot($customized);
        return $query;
    }

    private function shippingLinks()
    {
        // 序列号精确回到制造单；无 SN 只接纳冻结来源唯一且明确写有生产工单 ID 的明细，不按整单猜配。
        return DB::table('erp_mfg_work_orders as display_wo')
            ->join('erp_shipment_packing_contents as display_content', function ($join) {
                $join->on('display_content.item_id', '=', 'display_wo.item_id')->where(function ($link) {
                    $link->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as display_unit')
                        ->whereColumn('display_unit.work_order_id', 'display_wo.id')->whereNotNull('display_unit.inventory_serial_id')
                        ->whereRaw("JSON_CONTAINS(display_content.serial_snapshot, JSON_ARRAY(display_unit.inventory_serial_id), '$.inventory_serial_ids')"))
                        ->orWhereRaw("(COALESCE(JSON_LENGTH(display_content.serial_snapshot, '$.inventory_serial_ids'), 0) = 0
                            AND JSON_LENGTH(display_content.source_snapshot) = 1
                            AND JSON_UNQUOTE(JSON_EXTRACT(display_content.source_snapshot, '$[0].source_origin')) = 'mfg_production'
                            AND JSON_EXTRACT(display_content.source_snapshot, '$[0].work_order_id') = display_wo.id)");
                });
            })->join('erp_shipment_packing_operations as display_job', function ($join) {
                $join->on('display_job.package_id', '=', 'display_content.package_id')
                    ->on('display_job.shipment_id', '=', 'display_content.shipment_id')
                    ->whereRaw('JSON_CONTAINS(display_job.packing_content_ids, JSON_ARRAY(display_content.id))');
            })->join('erp_sales_shipment_lines as display_line', 'display_line.id', '=', 'display_content.shipment_line_id');
    }

    private function shippingStates()
    {
        $links = $this->shippingLinks()->select('display_wo.id as work_order_id', 'display_job.id', 'display_job.owner_legacy_id')
            ->selectRaw("CASE WHEN display_line.line_status IN ('cancelled', 'replaced') THEN 'CANCELLED' ELSE display_job.status END as status")
            ->distinct();
        $states = DB::query()->fromSub($links, 'display_jobs')->groupBy('work_order_id')->select('work_order_id')
            ->selectRaw("SUM(status IN ('READY', 'WAITING') AND owner_legacy_id IS NULL) as unclaimed_count,
                SUM(status IN ('IN_PROGRESS', 'WAIT_QUALITY') OR (status NOT IN ('COMPLETED', 'CANCELLED') AND owner_legacy_id IS NOT NULL)) as active_count,
                SUM(status NOT IN ('COMPLETED', 'CANCELLED')) as unfinished_count,
                SUM(status = 'COMPLETED') as completed_count, SUM(status != 'CANCELLED') as non_cancelled_count");
        return DB::query()->fromSub($states, 'display_shipping_state');
    }

    private function shippingStages(array $ids): array
    {
        if (! $ids) return [];
        return $this->shippingLinks()->whereIn('display_wo.id', $ids)->whereNotIn('display_line.line_status', ['cancelled', 'replaced'])
            ->where(fn ($q) => $q->whereIn('display_job.status', ['READY', 'IN_PROGRESS', 'WAIT_QUALITY'])
                ->orWhere(fn ($q) => $q->where('display_job.status', 'WAITING')->whereNotNull('display_job.owner_legacy_id')))
            ->orderBy('display_job.sequence')
            ->get(['display_wo.id as work_order_id', 'display_job.operation_name_snapshot', 'display_line.line_status'])->groupBy('work_order_id')
            ->map(fn ($jobs) => $jobs->map(fn ($job) => $job->operation_name_snapshot.($job->line_status === 'repair' ? '（返修中）' : ''))->unique()->implode('、'))->all();
    }

    private function drawings(array $ids): array
    {
        if (! $ids) return [];
        return MfgDrawing::query()->whereIn('work_order_id', $ids)->where('status', 'active')
            ->get(['id', 'work_order_id', 'original_name', 'url', 'mime_type'])->groupBy('work_order_id')
            ->map(fn ($rows) => $this->attachments(['attachments' => $rows->toArray()]))->all();
    }

    private function row(MfgWorkOrder $wo, array $salespeople, ?string $shippingStage = null, array $drawings = []): array
    {
        $order = $wo->salesOrder;
        $line = $order?->lines->firstWhere('id', $wo->sales_order_line_id);
        $data = $order?->toArray() ?? [];
        $tags = [];
        if ($wo->is_urgent || $order?->is_urgent || $order?->quickly) $tags[] = ['code' => 'urgent', 'label' => '加急'];
        if ($order?->is_delay || $order?->delay) $tags[] = ['code' => 'delayed', 'label' => '延迟'];
        if ($order?->is_customized || $order?->is_special_customized || $line?->is_special_customized) $tags[] = ['code' => 'customized', 'label' => '定制'];
        if (! $tags) $tags[] = ['code' => 'ordinary', 'label' => '普通'];
        $here = $wo->units->whereIn('status', ['waiting', 'in_progress', 'rework'])->pluck('current_operation_id')->filter()->unique();
        $stages = $wo->operations->whereIn('id', $here)->map(fn ($op) => $op->stage_name ?: $op->operation_name)->unique()->values()->all();
        if (! $stages) $stages = [WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status];
        $snapshot = $this->snapshot($data);
        $products = $snapshot['products'];
        if (! $order) $products = [['id' => $wo->id, 'name' => $wo->product_name ?: $wo->item_name,
            'quantity' => $wo->planned_qty, 'unit' => $wo->unit_name, 'unit_price' => null, 'remark' => $wo->special_requirement, 'attachments' => $drawings]];
        else foreach ($products as &$product) if ($product['id'] === $wo->sales_order_line_id)
            $product['attachments'] = collect(array_merge($product['attachments'], $drawings))->unique('url')->values()->all();
        unset($product);
        return ['id' => $wo->id, 'work_order_no' => $wo->work_order_no,
            'sales_order_id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no,
            'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
            'tags' => $tags, 'production_remarks' => ['welding' => null, 'process' => null, 'logistics' => $order?->logistics_requirement],
            'salesperson' => $salespeople[(int) $order?->sales_user_legacy_id]['display_name'] ?? null,
            'customer_name' => $snapshot['customer_name'] ?: $wo->customer_name, 'customer_phone' => $snapshot['customer_phone'],
            'device_name' => $wo->product_name ?: $wo->item_name, 'quantity' => $wo->planned_qty, 'unit' => $wo->unit_name,
            'order_note' => collect([$snapshot['order_note'], $wo->remark, $wo->special_requirement ? '工单特殊要求：'.$wo->special_requirement : null])->filter()->unique()->implode("\n") ?: null,
            'need_pump_label' => $this->pumpLabel($wo->sales_order_line_id ? $line?->need_pump : $order?->need_pump),
            'electric_label' => $line?->electric ?: $order?->electric,
            'platform_name' => $order?->sales_channel_name_snapshot ?: $order?->platform,
            'address' => $snapshot['address'], 'stage_name' => $shippingStage ?: implode('、', $stages),
            'created_at' => $wo->created_at?->toIso8601String(), 'products' => $products];
    }

    private function snapshot(array $data): array
    {
        return ['customer_name' => ($data['customer_name_snapshot'] ?? null) ?: ($data['customer_name'] ?? null),
            'customer_phone' => ($data['contact_phone_snapshot'] ?? null) ?: (($data['contact_phone'] ?? null) ?: ($data['customer_phone'] ?? null)),
            'address' => ($data['full_address'] ?? null) ?: (($data['address'] ?? null) ?: data_get($data, 'shipping_address_snapshot.full_address')),
            'order_note' => ($data['order_remark'] ?? null) ?: ($data['remark'] ?? null),
            'products' => collect($data['lines'] ?? [])->reject(fn ($line) => ($line['line_status'] ?? null) === 'removed')->map(fn ($line) => [
                'id' => $line['id'] ?? null, 'name' => $line['product_name'] ?? $line['item_name'] ?? $line['sku_name'] ?? null,
                'quantity' => $line['order_qty'] ?? null, 'unit' => $line['unit_name_snapshot'] ?? null,
                'unit_price' => $line['unit_price'] ?? null, 'remark' => $this->productRemark($line),
                'attachments' => $this->attachments($line),
            ])->values()->all()];
    }

    private function pumpLabel(mixed $value): ?string
    {
        return match ($value) { true => '需要控制原水泵', false => '无需控制原水泵', default => null };
    }

    private function productRemark(array $line): ?string
    {
        return collect([$line['remark'] ?? null, filled($line['electric'] ?? null) ? '电压：'.$line['electric'] : null,
            $this->pumpLabel($line['need_pump'] ?? null)])->filter(fn ($part) => filled($part))->unique()->implode("\n") ?: null;
    }

    private function attachments(array $line): array
    {
        // 只输出当前订单行和其历史快照内的文件；不接收外部 URL/磁盘路径参数，也不代理任意 URL。
        $files = array_merge((array) ($line['attachments'] ?? []),
            (array) data_get($line, 'technical_attachment_snapshot.files', []),
            is_array(data_get($line, 'drawing_snapshot.files')) ? data_get($line, 'drawing_snapshot.files') : []);
        return collect($files)->filter(fn ($file) => is_array($file) && ! in_array($file['status'] ?? 'active', ['deleted', 'replaced'], true))
            ->map(function ($file) {
                $url = trim((string) ($file['url'] ?? ''));
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return null;
                return ['id' => $file['attachment_id'] ?? $file['id'] ?? null,
                    'name' => $file['file_name'] ?? $file['original_name'] ?? $file['name'] ?? basename((string) parse_url($url, PHP_URL_PATH)),
                    'url' => $url, 'mime_type' => $file['mime_type'] ?? null];
            })->filter()->unique('url')->values()->all();
    }
}
