<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgDrawing;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\SalesOrderChange;
use App\Services\Erp\ErpUserProjectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 公共生产看板查询服务（免登录）：
 * 以销售订单为主体（常规备货单以顶层备货主工单为主体），主工单与半成品子工单树形下挂在对应订单内；
 * 仅投影车间展示所需的工单、工序、销售备注与设计图字段，不调用会写入或同步状态的生产方法。
 */
final class AssemblyDisplayQueryService
{
    private const STATES = [
        'wait' => ['planned', 'released'],
        'accept' => ['in_progress', 'paused'],
        'complete' => ['completed', 'closed'],
        'cancel' => ['cancelled'],
    ];

    private const PUBLIC_ATTACHMENT_TYPES = [
        'public_attachment',
        'design_drawing',
        'customer_drawing',
        'technical_agreement',
        'configuration_note',
        'other',
    ];

    public function paginate(array $filters): array
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $pageNo = max(1, (int) ($filters['page'] ?? 1));

        // 1. 先按筛选条件找出匹配的工单，并向上解析其所属销售订单或顶层主工单，按“订单组”去重分页
        $groupedSub = $this->filtered($filters)
            ->leftJoin('erp_mfg_work_order_links as wl1', 'wl1.child_work_order_id', '=', 'erp_mfg_work_orders.id')
            ->leftJoin('erp_mfg_work_orders as p1', function ($join) {
                $join->on('p1.id', '=', DB::raw('COALESCE(wl1.parent_work_order_id, erp_mfg_work_orders.rework_of_work_order_id)'));
            })
            ->leftJoin('erp_mfg_work_order_links as wl2', 'wl2.child_work_order_id', '=', 'p1.id')
            ->leftJoin('erp_mfg_work_orders as p2', function ($join) {
                $join->on('p2.id', '=', DB::raw('COALESCE(wl2.parent_work_order_id, p1.rework_of_work_order_id)'));
            })
            ->leftJoin('erp_sales_orders as so_no', function ($join) {
                $join->on('so_no.sales_order_no', '=', 'erp_mfg_work_orders.sales_order_no')
                    ->whereNull('erp_mfg_work_orders.sales_order_id');
            })
            ->selectRaw("
                CASE
                    WHEN COALESCE(erp_mfg_work_orders.sales_order_id, p1.sales_order_id, p2.sales_order_id, so_no.id) IS NOT NULL
                        THEN CONCAT('so:', COALESCE(erp_mfg_work_orders.sales_order_id, p1.sales_order_id, p2.sales_order_id, so_no.id))
                    ELSE CONCAT('wo:', COALESCE(p2.id, p1.id, erp_mfg_work_orders.id))
                END AS group_key,
                MAX(COALESCE(erp_mfg_work_orders.sales_order_id, p1.sales_order_id, p2.sales_order_id, so_no.id)) AS resolved_sales_order_id,
                MIN(COALESCE(p2.id, p1.id, erp_mfg_work_orders.id)) AS resolved_root_wo_id,
                MIN(CASE WHEN erp_mfg_work_orders.status IN ('completed', 'closed', 'cancelled') THEN 1 ELSE 0 END) AS all_finished_flag,
                MIN(COALESCE(p2.priority, p1.priority, erp_mfg_work_orders.priority, 3)) AS group_priority,
                MAX(erp_mfg_work_orders.id) AS latest_wo_id
            ")
            ->groupBy('group_key');

        /** @var LengthAwarePaginator $groupPage */
        $groupPage = DB::query()
            ->fromSub($groupedSub, 'order_groups')
            ->orderBy('all_finished_flag')
            ->orderBy('group_priority')
            ->orderByDesc('latest_wo_id')
            ->paginate($perPage, ['*'], 'page', $pageNo);

        $groupRows = collect($groupPage->items());
        $rows = $this->buildOrderGroupRows($groupRows);
        $groupPage->setCollection(collect($rows));

        // 2. 顶部统计：今日工单数 & 关联订单应收金额（按订单去重）
        $type = $filters['type'] ?? 'all';
        $today = $this->base($type)->whereDate('erp_mfg_work_orders.created_at', now()->toDateString());
        $stats = [
            'today_orders' => (clone $today)->count(),
            'sales_amount' => (string) SalesOrder::query()
                ->whereIn('id', (clone $today)->whereNotNull('erp_mfg_work_orders.sales_order_id')->select('erp_mfg_work_orders.sales_order_id'))
                ->sum('final_receivable_amount'),
        ];

        // 3. 当天交期且仍有待接单工序的紧急催办工单
        $urgent = $this->filtered(['type' => 'all', 'status' => 'wait'])
            ->where(function ($q) {
                $q->whereDate('erp_mfg_work_orders.required_date', '<=', now()->toDateString())
                    ->orWhereHas('salesOrder', fn ($so) => $so->whereDate('required_delivery_date', '<=', now()->toDateString()));
            })
            ->orderBy('erp_mfg_work_orders.created_at')
            ->limit(20)
            ->get(['erp_mfg_work_orders.id', 'erp_mfg_work_orders.work_order_no', 'erp_mfg_work_orders.sales_order_no', 'erp_mfg_work_orders.product_name', 'erp_mfg_work_orders.item_name', 'erp_mfg_work_orders.created_at', 'erp_mfg_work_orders.released_at']);

        return [
            'list' => $groupPage->toArray(),
            'stats' => $stats,
            'urgent_orders' => $urgent->map(fn ($wo) => [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'sales_order_no' => $wo->sales_order_no,
                'product_name' => $wo->product_name ?: $wo->item_name,
                'wait_seconds' => max(0, (int) ($wo->released_at ?? $wo->created_at)?->diffInSeconds(now(), false)),
            ])->all(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function detail(int $id): array
    {
        $wo = $this->base('all')
            ->with(['salesOrder.lines.attachments', 'operations', 'units', 'materials', 'item:id,item_type,spec,model,material_grade'])
            ->findOrFail($id);

        [$salesOrderId, $rootWoId] = $this->resolveGroupIdentity($wo);
        $groupDescriptor = (object) [
            'group_key' => $salesOrderId ? 'so:'.$salesOrderId : 'wo:'.$rootWoId,
            'resolved_sales_order_id' => $salesOrderId,
            'resolved_root_wo_id' => $rootWoId,
        ];

        $built = $this->buildOrderGroupRows(collect([$groupDescriptor]), $wo->id);
        $groupRow = $built[0] ?? null;
        if (! $groupRow) {
            $salespeople = app(ErpUserProjectionService::class)->many([$wo->salesOrder?->sales_user_legacy_id]);
            return $this->legacyRow($wo, $salespeople, $this->shippingStages([$id])[$id] ?? null, $this->drawings([$id])[$id] ?? []);
        }

        // 若前端点击的是具体的某个子工单或主工单，将顶层字段对齐到所点工单，同时携带整单与全部下挂工单树
        $selectedWo = collect($groupRow['work_orders'] ?? [])->firstWhere('id', $wo->id)
            ?? ($groupRow['work_orders'][0] ?? null);

        if ($selectedWo) {
            $groupRow['selected_work_order_id'] = $selectedWo['id'];
            $groupRow['selected_work_order'] = $selectedWo;
            $groupRow['work_order_no'] = $selectedWo['work_order_no'];
            $groupRow['status_label'] = $selectedWo['status_label'];
            $groupRow['device_name'] = $selectedWo['product_name'];
            $groupRow['quantity'] = $selectedWo['planned_qty'];
            $groupRow['unit'] = $selectedWo['unit_name'];
        }

        return $groupRow;
    }

    public function history(int $id): array
    {
        $wo = $this->base('all')->with('salesOrder.lines.attachments')->findOrFail($id);
        [$salesOrderId] = $this->resolveGroupIdentity($wo);
        $salesOrder = $wo->salesOrder ?: ($salesOrderId ? SalesOrder::query()->with('lines.attachments')->find($salesOrderId) : null);
        if (! $salesOrder) {
            return ['versions' => []];
        }
        $changes = SalesOrderChange::query()
            ->where('sales_order_id', $salesOrder->id)
            ->whereNotNull('applied_at')
            ->orderBy('id')
            ->get();

        // 原数据取首次正式变更之前的事实；无变更时展示当前内容，不虚构修改记录。
        $original = $changes->first()?->before_snapshot ?? $salesOrder->toArray();
        $versions = [[
            'id' => 'original',
            'label' => '原数据',
            'is_original' => true,
            'actor_name' => $original['created_by'] ?? $wo->created_by_name,
            'occurred_at' => ($salesOrder->created_at ?? $wo->created_at)?->toIso8601String(),
            'snapshot' => $this->snapshot($original),
        ]];
        foreach ($changes as $index => $change) {
            $versions[] = [
                'id' => $change->id,
                'label' => '第'.($index + 1).'次修改',
                'is_original' => false,
                'actor_name' => $change->operator,
                'occurred_at' => $change->applied_at?->toIso8601String(),
                'snapshot' => $this->snapshot((array) $change->after_snapshot),
            ];
        }

        return ['versions' => $versions];
    }

    public function change(int $id): array
    {
        $change = SalesOrderChange::query()->whereNotNull('applied_at')->findOrFail($id);
        $wo = $this->base('all')
            ->where('sales_order_id', $change->sales_order_id)
            ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->firstOrFail();

        return [
            'id' => $change->id,
            'work_order_no' => $wo->work_order_no,
            'sales_order_id' => (int) $change->sales_order_id,
            'sales_order_no' => $wo->sales_order_no,
            'before' => $this->snapshot((array) $change->before_snapshot),
            'after' => $this->snapshot((array) $change->after_snapshot),
            'actor_name' => $change->operator,
            'occurred_at' => $change->applied_at?->toIso8601String(),
        ];
    }

    /**
     * 为当前页的订单组构建完整的“订单主信息 + 醒目销售备注 + 图纸缩略图 + 下挂主/子工单树”。
     */
    private function buildOrderGroupRows(Collection $groupRows, ?int $focusWoId = null): array
    {
        if ($groupRows->isEmpty()) {
            return [];
        }

        $salesOrderIds = $groupRows->pluck('resolved_sales_order_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $rootWoIds = $groupRows->pluck('resolved_root_wo_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        // 找出这些订单或顶层主工单下的全部工单（含最多 4 层半成品子工单及返工工单）
        $seedWos = MfgWorkOrder::query()
            ->where(function ($q) use ($salesOrderIds, $rootWoIds) {
                if ($salesOrderIds) {
                    $q->orWhereIn('sales_order_id', $salesOrderIds);
                }
                if ($rootWoIds) {
                    $q->orWhereIn('id', $rootWoIds);
                }
            })
            ->pluck('id')
            ->all();

        $allWoIds = $this->expandWorkOrderTreeIds($seedWos);
        if (! $allWoIds) {
            return [];
        }

        $workOrders = MfgWorkOrder::query()
            ->with(['salesOrder.lines.attachments', 'operations', 'units', 'materials', 'item:id,item_type,spec,model,material_grade'])
            ->whereIn('id', $allWoIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // 父子关系表：child_work_order_id => parent_work_order_id
        $parentLinks = MfgWorkOrderLink::query()
            ->whereIn('child_work_order_id', $allWoIds)
            ->orWhereIn('parent_work_order_id', $allWoIds)
            ->orderBy('id')
            ->get(['parent_work_order_id', 'child_work_order_id']);

        $parentByChild = [];
        $childrenByParent = [];
        foreach ($parentLinks as $link) {
            $pid = (int) $link->parent_work_order_id;
            $cid = (int) $link->child_work_order_id;
            $parentByChild[$cid] = $pid;
            $childrenByParent[$pid][] = $cid;
        }
        foreach ($workOrders as $wo) {
            if ($wo->rework_of_work_order_id && ! isset($parentByChild[$wo->id])) {
                $pid = (int) $wo->rework_of_work_order_id;
                $parentByChild[$wo->id] = $pid;
                $childrenByParent[$pid][] = $wo->id;
            }
        }

        // 批量预取工序接单人与实时计时人员、物料规格、发货试水阶段、技术图纸、销售员与订单修改记录
        $operationIds = $workOrders->flatMap(fn ($wo) => $wo->operations->pluck('id'))->unique()->values()->all();
        $ownersByOp = $operationIds
            ? MfgTaskParticipant::query()
                ->whereIn('work_order_operation_id', $operationIds)
                ->where('lane', 'normal')
                ->where('role', 'owner')
                ->where('is_active', true)
                ->pluck('employee_name', 'work_order_operation_id')
                ->all()
            : [];
        $timingByOp = $operationIds
            ? MfgLaborSession::query()
                ->whereIn('work_order_operation_id', $operationIds)
                ->where('status', 'active')
                ->get(['work_order_operation_id', 'employee_name', 'unit_ids'])
                ->groupBy('work_order_operation_id')
            : collect();

        $compItemIds = $workOrders->flatMap(fn ($wo) => $wo->materials->pluck('component_item_id'))->filter()->unique()->values()->all();
        $compItems = $compItemIds
            ? Item::query()->whereIn('id', $compItemIds)->get(['id', 'spec', 'model', 'material_grade'])->keyBy('id')
            : collect();

        $shippingStages = $this->shippingStages($allWoIds);
        $woDrawings = $this->drawings($allWoIds);

        $salesOrders = $salesOrderIds
            ? SalesOrder::query()->with('lines.attachments')->whereIn('id', $salesOrderIds)->get()->keyBy('id')
            : collect();
        $orderAttachments = $salesOrderIds ? $this->salesOrderAttachments($salesOrderIds) : [];
        $orderChanges = $salesOrderIds
            ? SalesOrderChange::query()
                ->whereIn('sales_order_id', $salesOrderIds)
                ->whereNotNull('applied_at')
                ->orderBy('id')
                ->get(['id', 'sales_order_id', 'operator', 'reason', 'applied_at'])
                ->groupBy('sales_order_id')
            : collect();

        $salesUserIds = $salesOrders->pluck('sales_user_legacy_id')
            ->merge($workOrders->pluck('salesOrder.sales_user_legacy_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $salespeople = app(ErpUserProjectionService::class)->many($salesUserIds);

        // 将每张工单归入对应的 group_key
        $wosByGroup = [];
        foreach ($workOrders as $wo) {
            [$soId, $rootId] = $this->resolveGroupIdentityWithMap($wo, $workOrders, $parentByChild);
            $gKey = $soId ? 'so:'.$soId : 'wo:'.$rootId;
            $wosByGroup[$gKey][$wo->id] = $wo;
        }

        $result = [];
        foreach ($groupRows as $gRow) {
            $gKey = (string) $gRow->group_key;
            $groupWos = collect($wosByGroup[$gKey] ?? []);
            if ($groupWos->isEmpty()) {
                continue;
            }

            $soId = $gRow->resolved_sales_order_id ? (int) $gRow->resolved_sales_order_id : null;
            $order = $soId ? ($salesOrders->get($soId) ?? $groupWos->first()?->salesOrder) : null;

            // 找出该订单组内的顶层主工单（父工单不在本组内，或自身就是主工单）
            $roots = $groupWos->filter(function (MfgWorkOrder $wo) use ($parentByChild, $groupWos) {
                $pid = $parentByChild[$wo->id] ?? null;
                return ! $pid || ! $groupWos->has($pid);
            })->sortBy([
                ['sales_order_line_no', 'asc'],
                ['id', 'asc'],
            ])->values();

            if ($roots->isEmpty()) {
                $roots = $groupWos->values();
            }

            // 按树形前序遍历展开：主工单 -> 子工单（半成品/返工）
            $orderedWoNodes = [];
            $visited = [];
            $walk = function (MfgWorkOrder $wo, int $depth, ?MfgWorkOrder $parentWo) use (
                &$walk,
                &$orderedWoNodes,
                &$visited,
                $childrenByParent,
                $groupWos,
                $ownersByOp,
                $timingByOp,
                $compItems,
                $shippingStages,
                $woDrawings,
                $order,
                $orderAttachments
            ) {
                if (isset($visited[$wo->id])) {
                    return;
                }
                $visited[$wo->id] = true;

                $childIds = array_values(array_filter(
                    $childrenByParent[$wo->id] ?? [],
                    fn ($cid) => $groupWos->has($cid) && ! isset($visited[$cid])
                ));
                $childModels = collect($childIds)->map(fn ($cid) => $groupWos->get($cid))->filter()->values();

                $orderedWoNodes[] = $this->formatWorkOrderNode(
                    $wo,
                    $depth,
                    $parentWo,
                    $childModels,
                    $ownersByOp,
                    $timingByOp,
                    $compItems,
                    $shippingStages[$wo->id] ?? null,
                    $woDrawings[$wo->id] ?? [],
                    $order,
                    $orderAttachments[$order?->id ?? 0] ?? []
                );

                foreach ($childModels as $childWo) {
                    $walk($childWo, $depth + 1, $wo);
                }
            };

            foreach ($roots as $rootWo) {
                $walk($rootWo, 0, null);
            }
            // 兜底：若仍有未遍历到的工单也一并附加上
            foreach ($groupWos as $leftoverWo) {
                if (! isset($visited[$leftoverWo->id])) {
                    $walk($leftoverWo, 0, null);
                }
            }

            $primaryWo = $roots->first();
            $baseRow = $this->legacyRow(
                $primaryWo,
                $salespeople,
                $shippingStages[$primaryWo->id] ?? null,
                $woDrawings[$primaryWo->id] ?? [],
                $order,
                $orderAttachments[$order?->id ?? 0] ?? []
            );

            // 汇总整张订单（含订单级、订单行级、主工单、子工单）的全部设计图纸与技术附件
            $allOrderDrawings = collect();
            if ($order) {
                foreach ($orderAttachments[$order->id]['order'] ?? [] as $att) {
                    $allOrderDrawings->push(array_merge($att, [
                        'scope_type' => 'order',
                        'scope_label' => '订单公共附图',
                        'work_order_id' => $primaryWo->id,
                    ]));
                }
                foreach ($order->lines as $line) {
                    $lineFiles = array_merge(
                        $this->attachments($line->toArray()),
                        $orderAttachments[$order->id]['lines'][$line->id] ?? []
                    );
                    foreach ($lineFiles as $lf) {
                        $matchedWo = $roots->firstWhere('sales_order_line_id', $line->id) ?? $primaryWo;
                        $allOrderDrawings->push(array_merge($lf, [
                            'scope_type' => 'order',
                            'scope_label' => '订单第'.($line->line_no ?: 1).'行附图',
                            'work_order_id' => $matchedWo->id,
                        ]));
                    }
                }
            }
            foreach ($orderedWoNodes as $woNode) {
                foreach ($woNode['drawings'] as $wd) {
                    $allOrderDrawings->push(array_merge($wd, [
                        'scope_type' => $woNode['is_sub'] ? 'sub' : 'main',
                        'scope_label' => ($woNode['is_sub'] ? '子工单 ' : '主工单 ').$woNode['work_order_no'],
                        'work_order_id' => $woNode['id'],
                    ]));
                }
            }
            $uniqueDrawings = $allOrderDrawings->unique('url')->values()->all();

            // 汇总订单行备注与定制说明
            $lineRemarks = [];
            $electricLabels = [];
            $pumpLabels = [];
            if ($order) {
                foreach ($order->lines as $line) {
                    if (($line->line_status ?? null) === 'removed') {
                        continue;
                    }
                    $lName = $line->product_name ?: $line->item_name ?: $line->sku_name ?: '商品';
                    $lNo = $line->line_no ?: 1;
                    $customDesc = trim((string) ($line->customization_description ?: data_get($line->configuration_snapshot, 'special_custom_description')));
                    $lRemark = trim((string) $line->remark);
                    $parts = array_filter([
                        $lRemark !== '' ? $lRemark : null,
                        $customDesc !== '' ? '定制：'.$customDesc : null,
                    ]);
                    if ($parts) {
                        $lineRemarks[] = "第{$lNo}行 [{$lName}]：".implode('；', $parts);
                    }
                    if (filled($line->electric)) {
                        $electricLabels[] = trim((string) $line->electric);
                    }
                    $pLabel = $this->pumpLabel($line->need_pump);
                    if ($pLabel) {
                        $pumpLabels[] = $pLabel;
                    }
                }
            }

            $changesForOrder = $soId ? ($orderChanges->get($soId) ?? collect()) : collect();
            $latestChange = $changesForOrder->last();

            $dueDate = $order?->required_delivery_date
                ? substr((string) $order->required_delivery_date, 0, 10)
                : ($primaryWo->required_date?->toDateString() ?: $primaryWo->planned_finish_date?->toDateString());

            $mainWoCount = collect($orderedWoNodes)->where('is_sub', false)->count();
            $subWoCount = collect($orderedWoNodes)->where('is_sub', true)->count();

            $result[] = array_merge($baseRow, [
                'order_group_key' => $gKey,
                'source_type' => $order ? 'sales_order' : 'stock',
                'source_label' => $order ? '销售订单' : '库存备货',
                'display_order_no' => $order?->sales_order_no ?: ($primaryWo->sales_order_no ?: $primaryWo->work_order_no),
                'required_delivery_date' => $dueDate,
                'due_hint' => $this->dueHint($dueDate, collect($orderedWoNodes)),
                'is_overdue' => $this->isOverdue($dueDate, collect($orderedWoNodes)),
                'electric_label' => $electricLabels ? implode(' / ', array_values(array_unique($electricLabels))) : $baseRow['electric_label'],
                'need_pump_label' => $pumpLabels ? implode(' / ', array_values(array_unique($pumpLabels))) : $baseRow['need_pump_label'],
                'logistics_requirement' => $order?->logistics_requirement ?: ($baseRow['production_remarks']['logistics'] ?? null),
                'order_remark' => ($order?->order_remark ?: $order?->remark) ?: $primaryWo->remark,
                'customer_remark' => $order?->customer_remark,
                'line_remarks' => $lineRemarks,
                'has_changed' => $changesForOrder->isNotEmpty(),
                'change_count' => $changesForOrder->count(),
                'latest_change_id' => $latestChange?->id,
                'latest_change_by' => $latestChange?->operator,
                'latest_change_reason' => $latestChange?->reason,
                'latest_change_at' => $latestChange?->applied_at?->toIso8601String(),
                'main_work_order_count' => $mainWoCount,
                'sub_work_order_count' => $subWoCount,
                'work_orders' => $orderedWoNodes,
                'order_drawings' => $uniqueDrawings,
                'selected_work_order_id' => $focusWoId ?: ($orderedWoNodes[0]['id'] ?? $primaryWo->id),
            ]);
        }

        return $result;
    }

    /**
     * 格式化单张工单节点（主工单或半成品子工单），结合当前工单真实工序、用料、序列号情况。
     */
    private function formatWorkOrderNode(
        MfgWorkOrder $wo,
        int $depth,
        ?MfgWorkOrder $parentWo,
        Collection $childModels,
        array $ownersByOp,
        Collection $timingByOp,
        Collection $compItems,
        ?string $shippingStage,
        array $directDrawings,
        ?SalesOrder $order,
        array $orderAttMap
    ): array {
        $isSub = $depth > 0 || $parentWo !== null || $wo->source_type === 'work_order' || (bool) $wo->rework_of_work_order_id;
        $roleLabel = $wo->rework_of_work_order_id
            ? '返工工单'
            : ($isSub ? '子工单 · 半成品' : ($wo->source_type === 'stock' ? '备货主工单' : '主工单 · 总装'));

        $specInfo = WorkOrderQueryService::itemSpecInfo($wo->relationLoaded('item') ? $wo->item : null, $wo);
        $plannedQty = (float) $wo->planned_qty;
        $completedQty = (float) $wo->completed_qty;
        $storedQty = (float) $wo->stored_qty;
        $todoQty = max(0.0, round($plannedQty - $completedQty, 4));
        $unitName = $wo->unit_name ?: ($isSub ? '个' : '台');

        // 1. 半成品齐套情况（主工单）或完工交接去向（子工单）
        $semiText = null;
        $semiReady = null;
        if ($childModels->isNotEmpty()) {
            $parts = $childModels->map(function (MfgWorkOrder $c) {
                $name = $c->item_name ?: $c->product_name;
                return $name.' '.$this->qty((float) $c->stored_qty).'/'.$this->qty((float) $c->planned_qty);
            })->all();
            $semiReady = $childModels->every(fn (MfgWorkOrder $c) => (float) $c->stored_qty + 0.0001 >= (float) $c->planned_qty);
            $semiText = '半成品：'.implode(' · ', $parts).($semiReady ? ' · 已齐' : ' · 在制');
        } elseif ($isSub && $parentWo) {
            $semiText = '所属主工单：'.$parentWo->work_order_no.' ('.($parentWo->product_name ?: $parentWo->item_name).')';
        }

        // 2. 本工单用料与缺料情况（对齐小程序“缺 X 项”）
        $bomLines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $materialsList = $wo->materials->map(function ($m) use ($compItems, $bomLines) {
            $ms = WorkOrderQueryService::materialSpecInfo($compItems->get($m->component_item_id), $bomLines->get($m->bom_item_id));
            $req = (float) $m->required_qty;
            $iss = (float) $m->issued_qty;
            $isShort = $m->participates_in_kitting && ($iss + 0.0001 < $req);
            return [
                'id' => $m->id,
                'component_item_id' => (int) $m->component_item_id,
                'component_item_code' => $m->component_item_code,
                'component_item_name' => $m->component_item_name,
                'specification' => $ms['specification'],
                'unit_name' => $m->unit_name,
                'required_qty' => $req,
                'issued_qty' => $iss,
                'is_short' => $isShort,
            ];
        })->values();

        $shortCount = $materialsList->where('is_short', true)->count();
        $totalMatCount = $materialsList->count();
        if ($wo->status === 'completed' || $wo->status === 'closed') {
            $kittingText = '已完工入库 '.$this->qty($storedQty).'/'.$this->qty($plannedQty).' '.$unitName;
            $kittingType = 'ready';
        } elseif ($shortCount > 0) {
            $kittingText = '本道用料 缺 '.$shortCount.' 项';
            $kittingType = 'short';
        } elseif ($totalMatCount > 0) {
            $kittingText = '本道用料 已齐套 ('.$totalMatCount.'项)';
            $kittingType = 'ready';
        } else {
            $kittingText = '标准用料';
            $kittingType = 'ready';
        }

        // 3. 工序加工进度链条（含谁在计时、谁已接单、待接单、已完成）
        $unitsByOp = $wo->units
            ->whereIn('status', ['waiting', 'in_progress', 'rework'])
            ->groupBy('current_operation_id')
            ->map->count();

        $operations = $wo->operations
            ->where('execution_context', 'production')
            ->sortBy('sequence')
            ->values()
            ->map(function ($op) use ($plannedQty, $ownersByOp, $timingByOp, $unitsByOp) {
                $comp = (float) $op->completed_qty;
                $hereQty = (int) ($unitsByOp[$op->id] ?? 0);
                $timingSessions = $timingByOp->get($op->id, collect());
                $timingNames = $timingSessions->pluck('employee_name')->filter()->unique()->values();
                $ownerName = $ownersByOp[$op->id] ?? null;

                $isDone = ($plannedQty > 0 && $comp + 0.0001 >= $plannedQty) || $op->status === 'done';
                if ($isDone) {
                    $state = 'done';
                    $workerText = $ownerName ? $ownerName.' 已完成' : '已完成';
                } elseif ($timingNames->isNotEmpty()) {
                    $state = 'timing';
                    $workerText = $timingNames->implode('、').' 计时中';
                } elseif ($ownerName) {
                    $state = 'claimed';
                    $workerText = $ownerName.' 已接单';
                } else {
                    $state = 'wait';
                    $workerText = '待接单';
                }

                return [
                    'id' => $op->id,
                    'name' => $op->operation_name,
                    'completed' => $this->qty($comp),
                    'planned' => $this->qty($plannedQty),
                    'here_qty' => $hereQty,
                    'state' => $state,
                    'worker' => $workerText,
                ];
            })
            ->all();

        if ($shippingStage) {
            $operations[] = [
                'id' => 'ship-'.$wo->id,
                'name' => '试水/发货',
                'completed' => '-',
                'planned' => '-',
                'here_qty' => 0,
                'state' => 'claimed',
                'worker' => $shippingStage,
            ];
        }

        // 4. 按台/按件设备序列号列表 (SN)
        $opNamesById = $wo->operations->pluck('operation_name', 'id');
        $activeWorkersByUnit = [];
        foreach ($wo->operations as $op) {
            foreach ($timingByOp->get($op->id, collect()) as $session) {
                foreach ($session->unit_ids ?: [] as $uid) {
                    $activeWorkersByUnit[(int) $uid][] = $session->employee_name;
                }
            }
        }

        $units = $wo->units
            ->where('status', '!=', 'cancelled')
            ->sortBy('unit_no')
            ->values()
            ->map(function ($u) use ($opNamesById, $activeWorkersByUnit) {
                $wNames = array_values(array_unique(array_filter($activeWorkersByUnit[$u->id] ?? [])));
                $curOp = $u->current_operation_id ? ($opNamesById[$u->current_operation_id] ?? '') : '';
                $uStatus = match (true) {
                    $wNames !== [] => '计时中 ('.implode('、', $wNames).')',
                    $u->status === 'waiting' && $u->started_at !== null => '等待本道加工',
                    default => WorkOrderQueryService::UNIT_LABELS[$u->status] ?? $u->status,
                };
                return [
                    'id' => $u->id,
                    'unit_no' => $u->unit_no,
                    'serial_no' => $u->serial_no ?: ('#'.$u->unit_no),
                    'current_op' => $curOp ?: ($u->status === 'stored' ? '已入库' : '—'),
                    'status' => $u->status,
                    'status_label' => $uStatus,
                    'worker' => implode('、', $wNames),
                ];
            })
            ->all();

        // 5. 本工单直接关联图纸（工单技术图纸 + 所属订单行图纸）
        $lineDrawings = [];
        if ($order && $wo->sales_order_line_id) {
            $line = $order->lines->firstWhere('id', $wo->sales_order_line_id);
            if ($line) {
                $lineDrawings = array_merge(
                    $this->attachments($line->toArray()),
                    $orderAttMap['lines'][$line->id] ?? []
                );
            }
        }
        $mergedDrawings = collect(array_merge($directDrawings, $lineDrawings))->unique('url')->values()->all();

        return [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'source_type' => $wo->source_type,
            'is_sub' => $isSub,
            'depth' => $depth,
            'parent_work_order_id' => $parentWo?->id,
            'parent_work_order_no' => $parentWo?->work_order_no,
            'role_label' => $roleLabel,
            'product_name' => $wo->product_name ?: $wo->item_name,
            'item_name' => $wo->item_name,
            'item_code' => $wo->item_code,
            'sku_name' => $wo->sku_name,
            'specification' => $specInfo['specification'],
            'planned_qty' => $this->qty($plannedQty),
            'completed_qty' => $this->qty($completedQty),
            'stored_qty' => $this->qty($storedQty),
            'todo_qty' => $this->qty($todoQty),
            'unit_name' => $unitName,
            'status' => $wo->status,
            'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
            'is_urgent' => (bool) $wo->is_urgent,
            'priority' => (int) ($wo->priority ?: 3),
            'required_date' => $wo->required_date?->toDateString() ?: $wo->planned_finish_date?->toDateString(),
            'semi_text' => $semiText,
            'semi_ready' => $semiReady,
            'kitting_text' => $kittingText,
            'kitting_type' => $kittingType,
            'shortage_count' => $shortCount,
            'wo_remark' => collect([
                $wo->remark,
                $wo->special_requirement ? '特殊工艺要求：'.$wo->special_requirement : null,
                $wo->pause_reason ? '暂停原因：'.$wo->pause_reason : null,
                $wo->block_reason ? '阻塞原因：'.$wo->block_reason : null,
            ])->filter()->unique()->implode('；') ?: null,
            'operations' => $operations,
            'units' => $units,
            'materials' => $materialsList->all(),
            'drawings' => $mergedDrawings,
        ];
    }

    private function base(string $type): Builder
    {
        $query = MfgWorkOrder::query();
        if ($type === 'assemble') {
            $query->whereHas('operations', fn ($q) => $q
                ->whereIn('production_stage_id', DB::table('erp_production_stages')->where('code', 'ASSEMBLY')->select('id'))
                ->orWhere('operation_name', 'like', '%总装%'));
        } elseif ($type === 'testing') {
            $query->where(function ($q) {
                $q->whereIn('erp_mfg_work_orders.id', $this->shippingStates()->select('work_order_id'))
                    ->orWhereHas('operations', fn ($op) => $op->where('operation_name', 'like', '%试水%')->orWhere('operation_name', 'like', '%打包%'));
            });
        } elseif ($type === 'produce') {
            $query->whereHas('operations', fn ($q) => $q->where('execution_context', 'production'));
        } elseif ($type === 'stock') {
            $query->where('erp_mfg_work_orders.source_type', 'stock');
        }

        return $query;
    }

    private function filtered(array $filters): Builder
    {
        $type = $filters['type'] ?? 'all';
        $query = $this->base($type);
        $claimed = fn ($q) => $q->selectRaw('1')->from('erp_mfg_task_participants as display_owner')
            ->whereColumn('display_owner.work_order_id', 'erp_mfg_work_orders.id')
            ->where('display_owner.role', 'owner')
            ->where('display_owner.is_active', true);

        $status = $filters['status'] ?? 'all';
        if ($status === 'all') {
            $query->where('erp_mfg_work_orders.status', '!=', 'cancelled');
        } elseif ($type === 'testing') {
            $jobs = $this->shippingStates()->select('work_order_id');
            if ($status === 'wait') {
                $jobs->where('unclaimed_count', '>', 0)->where('active_count', '=', 0);
            } elseif ($status === 'accept') {
                $jobs->where('active_count', '>', 0);
            } elseif ($status === 'complete') {
                $jobs->where('unfinished_count', '=', 0)->where('completed_count', '>', 0);
            } else {
                $jobs->where('non_cancelled_count', '=', 0);
            }
            $query->whereIn('erp_mfg_work_orders.id', $jobs);
        } elseif ($status === 'wait') {
            $query->whereIn('erp_mfg_work_orders.status', self::STATES['wait'])->whereNotExists($claimed);
        } elseif ($status === 'accept') {
            $query->where(fn ($q) => $q->whereIn('erp_mfg_work_orders.status', self::STATES['accept'])
                ->orWhere(fn ($q) => $q->whereIn('erp_mfg_work_orders.status', self::STATES['wait'])->whereExists($claimed)));
        } else {
            $query->whereIn('erp_mfg_work_orders.status', self::STATES[$status] ?? self::STATES['wait']);
        }

        foreach (['create_from', 'create_to'] as $key) {
            if (! empty($filters[$key])) {
                $query->whereDate('erp_mfg_work_orders.created_at', $key === 'create_from' ? '>=' : '<=', $filters[$key]);
            }
        }
        if (! empty($filters['customer'])) {
            $like = '%'.$filters['customer'].'%';
            $query->where(fn ($q) => $q->where('erp_mfg_work_orders.customer_name', 'like', $like)
                ->orWhereHas('salesOrder', fn ($o) => $o
                    ->where('customer_name', 'like', $like)
                    ->orWhere('customer_name_snapshot', 'like', $like)
                    ->orWhere('contact_phone_snapshot', 'like', $like)
                    ->orWhere('contact_phone', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)));
        }
        if (! empty($filters['product_name'])) {
            $like = '%'.$filters['product_name'].'%';
            $query->where(fn ($q) => $q->where('erp_mfg_work_orders.product_name', 'like', $like)
                ->orWhere('erp_mfg_work_orders.sku_name', 'like', $like)
                ->orWhere('erp_mfg_work_orders.item_name', 'like', $like));
        }
        if (! empty($filters['order_no'])) {
            $like = '%'.$filters['order_no'].'%';
            $query->where(fn ($q) => $q->where('erp_mfg_work_orders.work_order_no', 'like', $like)
                ->orWhere('erp_mfg_work_orders.sales_order_no', 'like', $like));
        }

        $orderType = $filters['order_type'] ?? 'all';
        $urgent = fn ($q) => $q->where('erp_mfg_work_orders.is_urgent', true)
            ->orWhereHas('salesOrder', fn ($o) => $o->where('is_urgent', true)->orWhere('quickly', true));
        $delayed = fn ($q) => $q->whereHas('salesOrder', fn ($o) => $o->where('is_delay', true)->orWhere('delay', true));
        $customized = fn ($q) => $q->whereHas('salesOrder', fn ($o) => $o->where('is_customized', true)->orWhere('is_special_customized', true))
            ->orWhereExists(fn ($line) => $line->selectRaw('1')->from('erp_sales_order_lines as display_custom_line')
                ->whereColumn('display_custom_line.id', 'erp_mfg_work_orders.sales_order_line_id')
                ->where('display_custom_line.is_special_customized', true));

        if ($orderType === 'urgent') {
            $query->where($urgent);
        } elseif ($orderType === 'delayed') {
            $query->where($delayed);
        } elseif ($orderType === 'customized') {
            $query->where($customized);
        } elseif ($orderType === 'ordinary') {
            $query->whereNot($urgent)->whereNot($delayed)->whereNot($customized);
        }

        return $query;
    }

    /**
     * 双向扩展工单树 ID（向上找主工单，向下找所有半成品子工单与返工工单）。
     * @param int[] $seedIds
     * @return int[]
     */
    private function expandWorkOrderTreeIds(array $seedIds): array
    {
        $all = array_fill_keys(array_map('intval', array_filter($seedIds)), true);
        if (! $all) {
            return [];
        }

        // 向上追溯父工单
        $upFrontier = array_keys($all);
        for ($i = 0; $i < 4 && $upFrontier; $i++) {
            $parentIds = MfgWorkOrderLink::query()
                ->whereIn('child_work_order_id', $upFrontier)
                ->pluck('parent_work_order_id')
                ->merge(
                    MfgWorkOrder::query()
                        ->whereIn('id', $upFrontier)
                        ->whereNotNull('rework_of_work_order_id')
                        ->pluck('rework_of_work_order_id')
                )
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->all();

            $newUp = [];
            foreach ($parentIds as $pid) {
                if (! isset($all[$pid])) {
                    $all[$pid] = true;
                    $newUp[] = $pid;
                }
            }
            $upFrontier = $newUp;
        }

        // 如果父工单有关联销售订单，把同一销售订单下的其他主工单也纳入
        $soIds = MfgWorkOrder::query()
            ->whereIn('id', array_keys($all))
            ->whereNotNull('sales_order_id')
            ->pluck('sales_order_id')
            ->unique()
            ->all();
        if ($soIds) {
            foreach (MfgWorkOrder::query()->whereIn('sales_order_id', $soIds)->pluck('id') as $siblingId) {
                $all[(int) $siblingId] = true;
            }
        }

        // 向下展开全部子工单与返工工单
        $downFrontier = array_keys($all);
        for ($i = 0; $i < 4 && $downFrontier; $i++) {
            $childIds = MfgWorkOrderLink::query()
                ->whereIn('parent_work_order_id', $downFrontier)
                ->pluck('child_work_order_id')
                ->merge(
                    MfgWorkOrder::query()
                        ->whereIn('rework_of_work_order_id', $downFrontier)
                        ->pluck('id')
                )
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->unique()
                ->all();

            $newDown = [];
            foreach ($childIds as $cid) {
                if (! isset($all[$cid])) {
                    $all[$cid] = true;
                    $newDown[] = $cid;
                }
            }
            $downFrontier = $newDown;
        }

        return array_keys($all);
    }

    /**
     * @return array{0: ?int, 1: int} [sales_order_id, root_work_order_id]
     */
    private function resolveGroupIdentity(MfgWorkOrder $wo): array
    {
        $cur = $wo;
        $rootId = (int) $wo->id;
        for ($i = 0; $i < 5 && $cur; $i++) {
            $rootId = (int) $cur->id;
            if ($cur->sales_order_id) {
                return [(int) $cur->sales_order_id, $rootId];
            }
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $cur->id)->value('parent_work_order_id')
                ?: $cur->rework_of_work_order_id;
            $cur = $parentId ? MfgWorkOrder::query()->find($parentId) : null;
        }
        if ($wo->sales_order_no) {
            $soId = DB::table('erp_sales_orders')->where('sales_order_no', $wo->sales_order_no)->value('id');
            if ($soId) {
                return [(int) $soId, $rootId];
            }
        }

        return [null, $rootId];
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private function resolveGroupIdentityWithMap(MfgWorkOrder $wo, Collection $workOrders, array $parentByChild): array
    {
        $cur = $wo;
        $rootId = (int) $wo->id;
        for ($i = 0; $i < 5 && $cur; $i++) {
            $rootId = (int) $cur->id;
            if ($cur->sales_order_id) {
                return [(int) $cur->sales_order_id, $rootId];
            }
            $pid = $parentByChild[$cur->id] ?? null;
            $cur = $pid ? $workOrders->get($pid) : null;
        }

        return [null, $rootId];
    }

    private function shippingLinks()
    {
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
        if (! $ids) {
            return [];
        }

        return $this->shippingLinks()->whereIn('display_wo.id', $ids)->whereNotIn('display_line.line_status', ['cancelled', 'replaced'])
            ->where(fn ($q) => $q->whereIn('display_job.status', ['READY', 'IN_PROGRESS', 'WAIT_QUALITY'])
                ->orWhere(fn ($q) => $q->where('display_job.status', 'WAITING')->whereNotNull('display_job.owner_legacy_id')))
            ->orderBy('display_job.sequence')
            ->get(['display_wo.id as work_order_id', 'display_job.operation_name_snapshot', 'display_line.line_status'])
            ->groupBy('work_order_id')
            ->map(fn ($jobs) => $jobs->map(fn ($job) => $job->operation_name_snapshot.($job->line_status === 'repair' ? '（返修中）' : ''))->unique()->implode('、'))
            ->all();
    }

    private function drawings(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return MfgDrawing::query()
            ->whereIn('work_order_id', $ids)
            ->where('status', 'active')
            // 公共大屏没有员工身份，CRM 私有图纸只在已登录的工单、任务资料中读取。
            ->where('storage_disk', 'public')
            ->get(['id', 'work_order_id', 'drawing_name', 'original_name', 'url', 'storage_path', 'mime_type', 'version_no', 'uploaded_by', 'uploaded_at'])
            ->groupBy('work_order_id')
            ->map(function ($rows) {
                return $rows->map(function ($d) {
                    $url = $this->normalizeFileUrl(str_starts_with((string) $d->url, '/api/v1/erp/production/technical-drawings/') ? null : $d->url, $d->storage_path);
                    if (! $url) {
                        return null;
                    }
                    return [
                        'id' => 'wo-drawing-'.$d->id,
                        'name' => $d->drawing_name ?: $d->original_name ?: basename((string) parse_url($url, PHP_URL_PATH)),
                        'url' => $url,
                        'mime_type' => $d->mime_type,
                        'version' => $d->version_no ? 'V'.$d->version_no : null,
                        'uploaded_by' => $d->uploaded_by,
                        'updated_at' => $d->uploaded_at?->toDateTimeString(),
                    ];
                })->filter()->unique('url')->values()->all();
            })
            ->all();
    }

    /**
     * 读取销售订单级与订单行级的有效生产/设计附件（排除合同和财务凭证）。
     */
    private function salesOrderAttachments(array $salesOrderIds): array
    {
        if (! $salesOrderIds) {
            return [];
        }
        $rows = SalesOrderAttachment::query()
            ->whereIn('sales_order_id', $salesOrderIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('attachment_type', self::PUBLIC_ATTACHMENT_TYPES)
            ->orderBy('id')
            ->get();

        $map = [];
        foreach ($rows as $att) {
            if ($att->attachment_scope === 'order' && $att->attachment_type === 'other'
                && ! str_starts_with((string) $att->mime_type, 'image/')) {
                continue;
            }
            $url = $this->normalizeFileUrl($att->url, $att->storage_disk === 'public' ? $att->storage_path : null);
            if (! $url) {
                continue;
            }
            $item = [
                'id' => 'so-att-'.$att->id,
                'name' => $att->original_name ?: basename((string) parse_url($url, PHP_URL_PATH)),
                'url' => $url,
                'mime_type' => $att->mime_type,
                'version' => $att->version_no ? 'V'.$att->version_no : null,
                'uploaded_by' => $att->uploaded_by,
                'updated_at' => $att->uploaded_at?->toDateTimeString(),
            ];
            if ($att->attachment_scope === 'line' && $att->sales_order_line_id) {
                $map[(int) $att->sales_order_id]['lines'][(int) $att->sales_order_line_id][] = $item;
            } else {
                $map[(int) $att->sales_order_id]['order'][] = $item;
            }
        }

        return $map;
    }

    private function legacyRow(
        MfgWorkOrder $wo,
        array $salespeople,
        ?string $shippingStage = null,
        array $drawings = [],
        ?SalesOrder $resolvedOrder = null,
        array $orderAttMap = []
    ): array {
        $order = $resolvedOrder ?: $wo->salesOrder;
        $line = $order?->lines->firstWhere('id', $wo->sales_order_line_id);
        $data = $order?->toArray() ?? [];
        $tags = [];
        if ($wo->is_urgent || $order?->is_urgent || $order?->quickly) {
            $tags[] = ['code' => 'urgent', 'label' => '加急'];
        }
        if ($order?->is_delay || $order?->delay) {
            $tags[] = ['code' => 'delayed', 'label' => '延迟'];
        }
        if ($order?->is_customized || $order?->is_special_customized || $line?->is_special_customized) {
            $tags[] = ['code' => 'customized', 'label' => '定制'];
        }
        if (! $tags) {
            $tags[] = ['code' => 'ordinary', 'label' => '普通'];
        }

        $here = $wo->units->whereIn('status', ['waiting', 'in_progress', 'rework'])->pluck('current_operation_id')->filter()->unique();
        $stages = $wo->operations->whereIn('id', $here)->map(fn ($op) => $op->stage_name ?: $op->operation_name)->unique()->values()->all();
        if (! $stages) {
            $stages = [WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status];
        }

        $snapshot = $this->snapshot($data);
        $products = $snapshot['products'];
        if (! $order) {
            $products = [[
                'id' => $wo->id,
                'name' => $wo->product_name ?: $wo->item_name,
                'quantity' => $wo->planned_qty,
                'unit' => $wo->unit_name,
                'unit_price' => null,
                'remark' => $wo->special_requirement,
                'attachments' => $drawings,
            ]];
        } else {
            foreach ($products as &$product) {
                $lineId = $product['id'] ?? null;
                $extraLineAtts = $lineId ? ($orderAttMap['lines'][$lineId] ?? []) : [];
                $extraWoAtts = ($lineId === $wo->sales_order_line_id) ? $drawings : [];
                $product['attachments'] = collect(array_merge($product['attachments'] ?? [], $extraLineAtts, $extraWoAtts))
                    ->unique('url')
                    ->values()
                    ->all();
            }
            unset($product);
        }

        return [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'sales_order_id' => $order?->id ?: $wo->sales_order_id,
            'sales_order_no' => $order?->sales_order_no ?: $wo->sales_order_no,
            'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
            'tags' => $tags,
            'production_remarks' => [
                'welding' => null,
                'process' => $wo->special_requirement,
                'logistics' => $order?->logistics_requirement,
            ],
            'salesperson' => $salespeople[(int) $order?->sales_user_legacy_id]['display_name'] ?? null,
            'customer_name' => $snapshot['customer_name'] ?: $wo->customer_name,
            'customer_phone' => $snapshot['customer_phone'],
            'device_name' => $wo->product_name ?: $wo->item_name,
            'quantity' => $wo->planned_qty,
            'unit' => $wo->unit_name,
            'order_note' => collect([
                $snapshot['order_note'],
                $wo->remark,
                $wo->special_requirement ? '工单特殊要求：'.$wo->special_requirement : null,
            ])->filter()->unique()->implode("\n") ?: null,
            'need_pump_label' => $this->pumpLabel($wo->sales_order_line_id ? $line?->need_pump : $order?->need_pump),
            'electric_label' => $line?->electric ?: $order?->electric,
            'platform_name' => $order?->sales_channel_name_snapshot ?: $order?->platform,
            'address' => $snapshot['address'],
            'stage_name' => $shippingStage ?: implode('、', $stages),
            'created_at' => ($order?->created_at ?? $wo->created_at)?->toIso8601String(),
            'products' => $products,
        ];
    }

    private function snapshot(array $data): array
    {
        return [
            'customer_name' => ($data['customer_name_snapshot'] ?? null) ?: ($data['customer_name'] ?? null),
            'customer_phone' => ($data['contact_phone_snapshot'] ?? null) ?: (($data['contact_phone'] ?? null) ?: ($data['customer_phone'] ?? null)),
            'address' => ($data['full_address'] ?? null) ?: (($data['address'] ?? null) ?: data_get($data, 'shipping_address_snapshot.full_address')),
            'order_note' => ($data['order_remark'] ?? null) ?: ($data['remark'] ?? null),
            'products' => collect($data['lines'] ?? [])
                ->reject(fn ($line) => ($line['line_status'] ?? null) === 'removed')
                ->map(fn ($line) => [
                    'id' => $line['id'] ?? null,
                    'name' => $line['product_name'] ?? $line['item_name'] ?? $line['sku_name'] ?? null,
                    'quantity' => $line['order_qty'] ?? null,
                    'unit' => $line['unit_name_snapshot'] ?? null,
                    'unit_price' => $line['unit_price'] ?? null,
                    'remark' => $this->productRemark($line),
                    'attachments' => $this->attachments($line),
                ])->values()->all(),
        ];
    }

    private function pumpLabel(mixed $value): ?string
    {
        return match ($value) {
            true, 1, '1' => '需要控制原水泵',
            false, 0, '0' => '无需控制原水泵',
            default => null,
        };
    }

    private function productRemark(array $line): ?string
    {
        return collect([
            $line['remark'] ?? null,
            $line['customization_description'] ?? data_get($line, 'configuration_snapshot.special_custom_description'),
            filled($line['electric'] ?? null) ? '电压：'.$line['electric'] : null,
            $this->pumpLabel($line['need_pump'] ?? null),
        ])->filter(fn ($part) => filled($part))->unique()->implode("\n") ?: null;
    }

    private function attachments(array $line): array
    {
        $files = array_merge(
            (array) ($line['attachments'] ?? []),
            (array) data_get($line, 'technical_attachment_snapshot.files', []),
            is_array(data_get($line, 'drawing_snapshot.files')) ? data_get($line, 'drawing_snapshot.files') : []
        );

        return collect($files)
            ->filter(fn ($file) => is_array($file) && ! in_array($file['status'] ?? 'active', ['deleted', 'replaced'], true))
            ->map(function ($file) {
                $url = $this->normalizeFileUrl($file['url'] ?? null, $file['storage_path'] ?? null);
                if (! $url) {
                    return null;
                }
                return [
                    'id' => $file['attachment_id'] ?? $file['id'] ?? null,
                    'name' => $file['file_name'] ?? $file['original_name'] ?? $file['name'] ?? basename((string) parse_url($url, PHP_URL_PATH)),
                    'url' => $url,
                    'mime_type' => $file['mime_type'] ?? null,
                ];
            })
            ->filter()
            ->unique('url')
            ->values()
            ->all();
    }

    private function normalizeFileUrl(?string $url, ?string $storagePath = null): ?string
    {
        $candidate = trim((string) $url);
        if ($candidate === '' && $storagePath) {
            $candidate = Storage::disk('public')->url($storagePath);
        }
        if ($candidate === '') {
            return null;
        }
        if (str_starts_with($candidate, '/storage/') || str_starts_with($candidate, 'storage/')) {
            $candidate = url('/'.ltrim($candidate, '/'));
        }
        if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $candidate : null;
    }

    private function dueHint(?string $dueDate, Collection $woNodes): ?string
    {
        $allDone = $woNodes->isNotEmpty() && $woNodes->every(fn ($w) => in_array($w['status'], ['completed', 'closed'], true));
        if ($allDone) {
            return '已完工';
        }
        if (! $dueDate) {
            return null;
        }
        $today = now()->startOfDay();
        $target = \Illuminate\Support\Carbon::parse($dueDate)->startOfDay();
        $diff = (int) $today->diffInDays($target, false);
        if ($diff < 0) {
            return '逾期 '.abs($diff).' 天';
        }
        if ($diff === 0) {
            return '今日交期';
        }
        return '剩余 '.$diff.' 天';
    }

    private function isOverdue(?string $dueDate, Collection $woNodes): bool
    {
        $allDone = $woNodes->isNotEmpty() && $woNodes->every(fn ($w) => in_array($w['status'], ['completed', 'closed', 'cancelled'], true));
        if ($allDone || ! $dueDate) {
            return false;
        }
        return \Illuminate\Support\Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay());
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
