<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{ApprovalTask, Item, PurchaseAttachment, PurchaseDefectHandling, PurchaseExchangeOrder, PurchaseLog, PurchaseOrder, PurchaseOrderItem, PurchasePlan, PurchasePlanItem, PurchasePlanSupplierSplit, PurchasePriceHistory, PurchaseReceipt, PurchaseReceiptItem, PurchaseRequest, PurchaseRequestItem, Supplier, SupplierItemStat};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\PurchaseReturnApplicationService;
use App\Services\Erp\PurchaseReceiptApplicationService;
use App\Services\Erp\PurchaseReceiptConfirmationApplicationService;
use App\Services\Erp\PurchaseReceiptAllocationService;
use App\Services\Erp\PurchaseWorkflowApplicationService;
use App\Services\Erp\InventorySerialApplicationService;
use App\Services\Erp\PurchaseAttachmentApplicationService;
use App\Services\Erp\PurchaseDefectApplicationService;
use App\Services\Erp\PurchaseDraftDeletionApplicationService;
use App\Services\Erp\PurchaseOrderFinanceSummaryService;
use App\Services\Erp\SupplierCapabilityService;
use App\Services\Erp\SupplierPerformanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    private const DATA_SOURCES = ['manual', 'import', 'legacy_sync', 'api', 'system'];
    private const INLINE_PREVIEW_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'text/csv',
        'application/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function requests(Request $request)
    {
        $query = PurchaseRequest::with(['items.item.unit.standardUnit', 'items.unit.standardUnit', 'items.warehouse'])->latest('updated_at');
        $this->keyword($query, $request, ['request_no'], ['items.item' => ['item_code', 'item_name']]);
        if ($request->filled('status')) $query->where('request_status', $request->input('status'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function deleteRequest(int $id, PurchaseDraftDeletionApplicationService $service)
    {
        $service->deleteRequest($id);
        return response()->json(['message' => '采购需求草稿已删除']);
    }

    public function deletePlan(int $id, PurchaseDraftDeletionApplicationService $service)
    {
        $service->deletePlan($id);
        return response()->json(['message' => '采购计划草稿已删除，关联需求占用已释放']);
    }

    public function deleteOrder(int $id, PurchaseDraftDeletionApplicationService $service)
    {
        $service->deleteOrder($id);
        return response()->json(['message' => '采购订单草稿已删除，计划占用已释放']);
    }

    public function deleteReceipt(int $id, PurchaseDraftDeletionApplicationService $service)
    {
        $service->deleteReceipt($id);
        return response()->json(['message' => '采购到货草稿已删除']);
    }

    public function storeRequest(Request $request, DocumentNumberService $numbers)
    {
        $data = $request->validate([
            'request_no' => 'nullable|string|max:80',
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'request_date' => 'nullable|date',
            'source_type' => 'nullable|string|max:30',
            'source_id' => 'nullable|string|max:80',
            'source_no' => 'nullable|string|max:80',
            'requester' => 'nullable|string|max:80',
            'remark' => 'nullable|string',
            'data_source' => ['nullable', Rule::in(self::DATA_SOURCES)],
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.purchase_unit_id' => 'nullable|exists:erp_units,id',
            'items.*.request_qty' => 'required|numeric|min:0.0001',
            'items.*.expected_date' => 'nullable|date',
            'items.*.warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.priority' => 'nullable|in:low,normal,high,urgent',
            'items.*.remark' => 'nullable|string',
        ]);
        $data['request_no'] = $request->input('request_no') ?: $this->nextNo('PRQ');
        $data['request_date'] = $data['request_date'] ?? now()->toDateString();
        $data['source_type'] = $data['source_type'] ?? 'manual';
        $data['request_status'] = 'draft';
        $data['status'] = 'draft';
        $data['data_source'] = $data['data_source'] ?? 'manual';
        $data['item_id'] = $data['items'][0]['item_id'];
        $data['request_qty'] = collect($data['items'])->sum(fn ($line) => (float) $line['request_qty']);
        $data['planned_qty'] = 0;
        $data['required_date'] = $data['items'][0]['expected_date'] ?? null;
        $operatorId = app(AuthContextService::class)->currentUser($request)?->legacy_id;
        return DB::transaction(function () use ($data, $numbers, $operatorId) {
            $items = $data['items'];
            $reservationToken = $data['reservation_token'] ?? null;
            unset($data['items'], $data['reservation_token'], $data['creation_session_id']);
            $record = PurchaseRequest::create($data);
            $this->saveRequestItems($record, $items);
            if ($reservationToken) {
                $numbers->consume($reservationToken, 'purchase_request', $record->request_no, $operatorId, 'purchase_request', $record->id);
            }
            $this->log('purchase_request', $record->id, 'create', '新增采购需求');
            return response()->json(['message' => '采购需求已保存', 'data' => $record->fresh(['items.item.unit.standardUnit', 'items.unit.standardUnit', 'items.warehouse'])], 201);
        });
    }

    public function showRequest(int $id)
    {
        return response()->json(PurchaseRequest::with(['items.item.unit.standardUnit', 'items.unit.standardUnit', 'items.warehouse'])->findOrFail($id));
    }

    public function updateRequest(Request $request, int $id)
    {
        $record = PurchaseRequest::findOrFail($id);
        abort_if($record->request_status !== 'draft', 422, '只有草稿采购需求可以编辑');
        $data = $request->validate([
            'request_date' => 'nullable|date',
            'source_type' => 'nullable|string|max:30',
            'requester' => 'nullable|string|max:80',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.purchase_unit_id' => 'nullable|exists:erp_units,id',
            'items.*.request_qty' => 'required|numeric|min:0.0001',
            'items.*.expected_date' => 'nullable|date',
            'items.*.warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.priority' => 'nullable|in:low,normal,high,urgent',
            'items.*.remark' => 'nullable|string',
        ]);
        return DB::transaction(function () use ($record, $data, $id) {
            $items = $data['items'];
            unset($data['items']);
            $record->update($data);
            PurchaseRequestItem::where('request_id', $id)->delete();
            $this->saveRequestItems($record, $items);
            $this->log('purchase_request', $id, 'update', '编辑采购需求');
            return response()->json(['message' => '采购需求已更新', 'data' => $record->fresh(['items.item.unit.standardUnit', 'items.unit.standardUnit', 'items.warehouse'])]);
        });
    }

    public function submitRequest(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return response()->json(['message' => '采购需求已确认，可进入采购计划', 'data' => $workflow->confirmRequest($id, $this->operatorName($request))]);
    }

    public function closeRequest(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return response()->json(['message' => '采购需求已关闭', 'data' => $workflow->closeRequest($id, $this->operatorName($request))]);
    }

    public function cancelRequest(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return response()->json(['message' => '采购需求已取消', 'data' => $workflow->cancelRequest($id, $this->operatorName($request))]);
    }

    public function requestToPlan(int $id)
    {
        return DB::transaction(function () use ($id) {
            $request = PurchaseRequest::with(['items.item'])->findOrFail($id);
            abort_if(!in_array($request->request_status, ['confirmed', 'partially_planned'], true), 422, '只有已确认的采购需求可以转采购计划');
            $requestItems = $request->items->filter(fn ($line) => (float) $line->remaining_qty > 0);
            abort_if($requestItems->isEmpty(), 422, '采购需求明细已全部转计划，不能重复转换');
            $plan = PurchasePlan::create([
                'plan_no' => $this->nextNo('PPL'),
                'plan_date' => now()->toDateString(),
                'plan_status' => 'draft',
                'audit_status' => 'pending',
                'order_status' => 'not_ordered',
                'data_source' => 'manual',
                'remark' => "由采购需求 {$request->request_no} 生成",
            ]);
            foreach ($requestItems as $requestItem) {
                PurchasePlanItem::create([
                    'plan_id' => $plan->id,
                    'request_id' => $request->id,
                    'request_item_id' => $requestItem->id,
                    'item_id' => $requestItem->item_id,
                    'unit_id' => $requestItem->unit_id,
                    'plan_qty' => $requestItem->remaining_qty,
                    'required_qty' => $requestItem->remaining_qty,
                    'allocated_qty' => 0,
                    'remaining_qty' => $requestItem->remaining_qty,
                    'warehouse_id' => $requestItem->warehouse_id,
                    'expected_arrival_date' => $requestItem->expected_date,
                    'expected_date' => $requestItem->expected_date,
                    'status' => 'unallocated',
                    'line_status' => 'open',
                    'data_source' => 'manual',
                    ...$this->materialPolicySnapshot(
                        $requestItem->item,
                        $requestItem->material_policy_snapshot,
                        $requestItem->material_policy_id_snapshot,
                        $requestItem->material_policy_version_snapshot,
                    ),
                ]);
                $requestItem->update([
                    'converted_qty' => $requestItem->request_qty,
                    'remaining_qty' => 0,
                    'line_status' => 'planned',
                ]);
            }
            $this->refreshPlanTotal($plan->id);
            $request->update(['request_status' => 'planned', 'status' => 'planned', 'planned_qty' => DB::raw('request_qty')]);
            $this->log('purchase_request', $request->id, 'to_plan', "转采购计划 {$plan->plan_no}");
            $this->log('purchase_plan', $plan->id, 'create_from_request', "由采购需求 {$request->request_no} 生成");
            return response()->json(['message' => '已生成采购计划', 'data' => $plan->fresh(['items.item', 'items.requestItem', 'items.splits.supplier'])]);
        });
    }

    public function plans(Request $request)
    {
        $query = PurchasePlan::with(['items.item.unit', 'items.request', 'items.requestItem', 'items.splits.supplier', 'items.splits.item', 'items.splits.order'])->latest('updated_at');
        $this->keyword($query, $request, ['plan_no']);
        if ($request->filled('status')) $query->where('plan_status', $request->input('status'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storePlan(Request $request, DocumentNumberService $numbers)
    {
        $payload = $this->validatePlan($request);
        $operatorId = app(AuthContextService::class)->currentUser($request)?->legacy_id;
        return DB::transaction(function () use ($payload, $numbers, $operatorId) {
            $plan = PurchasePlan::create([
                'plan_no' => $payload['plan_no'] ?? $this->nextNo('PPL'),
                'plan_date' => $payload['plan_date'] ?? now()->toDateString(),
                'plan_status' => 'draft',
                'audit_status' => 'pending',
                'remark' => $payload['remark'] ?? null,
                'data_source' => $payload['data_source'] ?? 'manual',
            ]);
            $this->savePlanItems($plan, $payload['items']);
            if (!empty($payload['reservation_token'])) {
                $numbers->consume($payload['reservation_token'], 'purchase_plan', $plan->plan_no, $operatorId, 'purchase_plan', $plan->id);
            }
            $this->log('purchase_plan', $plan->id, 'create', '新增采购计划');
            return response()->json(['message' => '采购计划已保存', 'data' => $plan->fresh(['items.item.unit', 'items.splits.supplier'])], 201);
        });
    }

    public function showPlan(int $id)
    {
        return response()->json(PurchasePlan::with(['items.item.unit', 'items.request', 'items.requestItem', 'items.splits.supplier', 'items.splits.item', 'items.splits.order', 'items.splits.orderItem', 'orders.supplier', 'orders.items'])->findOrFail($id));
    }

    public function updatePlan(Request $request, int $id)
    {
        $plan = PurchasePlan::findOrFail($id);
        abort_if($plan->plan_status !== 'draft', 422, '只有草稿采购计划可以编辑');
        $payload = $this->validatePlan($request);
        return DB::transaction(function () use ($plan, $payload) {
            $plan->update([
                'plan_date' => $payload['plan_date'] ?? $plan->plan_date,
                'remark' => $payload['remark'] ?? null,
            ]);
            PurchasePlanSupplierSplit::where('plan_id', $plan->id)->delete();
            PurchasePlanItem::where('plan_id', $plan->id)->delete();
            $this->savePlanItems($plan, $payload['items']);
            $this->log('purchase_plan', $plan->id, 'update', '编辑采购计划及供应商拆分');
            return response()->json(['message' => '采购计划已更新', 'data' => $plan->fresh(['items.item.unit', 'items.splits.supplier'])]);
        });
    }

    public function submitPlan(int $id)
    {
        $plan = PurchasePlan::with('items.splits.supplier')->findOrFail($id);
        abort_if($plan->items->isEmpty(), 422, '采购计划至少需要一行明细');
        abort_if($plan->items->contains(fn ($i) => (float) $i->remaining_qty > 0), 422, '采购计划存在未分配数量，不能提交审核');
        abort_if($plan->items->contains(fn ($i) => $i->splits->isEmpty()), 422, '采购计划必须完成供应商拆分');
        abort_if(
            PurchasePlanSupplierSplit::where('plan_id', $id)->where('unit_price', '<=', 0)->exists(),
            422,
            '采购计划存在采购单价小于或等于 0 的供应商拆分，不能提交审核。供应商报价仅供参考，实际采购价必须由采购员确认。'
        );
        $conversionService = app(\App\Services\Erp\PurchaseConversionApplicationService::class);
        foreach ($plan->items as $item) {
            foreach ($item->splits as $split) {
                $conversionService->orderLineSnapshotFromBaseRequirement([
                    'item_id' => $item->item_id,
                    'supplier_id' => $split->supplier_id,
                    'base_qty' => $split->purchase_qty,
                    'base_unit_price' => $split->unit_price,
                ]);
            }
        }
        $plan->update(['plan_status' => 'submitted', 'audit_status' => 'pending']);
        $this->log('purchase_plan', $id, 'submit', '提交采购计划');
        return response()->json(['message' => '采购计划已提交', 'data' => $plan->fresh(['items.item', 'items.splits.supplier'])]);
    }

    public function approvePlan(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        $this->authorizePermission($request, 'purchase.plan.approve');
        return response()->json(['message' => '采购计划已审核', 'data' => $workflow->approvePlan($id, $this->operatorName($request))]);
    }

    public function rejectPlan(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        $this->authorizePermission($request, 'purchase.plan.approve');
        $payload = $request->validate(['reason' => 'required|string|max:500']);
        return response()->json(['message' => '采购计划已驳回，可修改后重新提交', 'data' => $workflow->rejectPlan($id, $payload['reason'], $this->operatorName($request))]);
    }

    public function previewPlanOrders(int $id)
    {
        $plan = PurchasePlan::with(['items.item', 'items.splits.supplier'])->findOrFail($id);
        return response()->json(['data' => $this->groupPlanItemsForOrders($plan)]);
    }

    public function generateOrdersFromPlan(int $id)
    {
        return DB::transaction(function () use ($id) {
            $plan = PurchasePlan::with(['items.item', 'items.splits.supplier'])->findOrFail($id);
            abort_if($plan->audit_status !== 'approved', 422, '采购计划审核后才能生成采购订单');
            $groups = $this->groupPlanItemsForOrders($plan);
            abort_if(empty($groups), 422, '没有可生成订单的供应商明细');
            $orders = [];
            foreach ($groups as $group) {
                $preparedItems = collect($group['items'])->map(function (array $line) use ($group) {
                    $snapshot = app(\App\Services\Erp\PurchaseConversionApplicationService::class)
                        ->orderLineSnapshotFromBaseRequirement([
                            'item_id' => $line['item_id'],
                            'supplier_id' => $group['supplier_id'],
                            'base_qty' => $line['purchase_qty'],
                            'base_unit_price' => $line['unit_price'],
                        ]);

                    return $line + ['conversion_snapshot' => $snapshot];
                });
                $lineAmount = $preparedItems->sum(fn (array $line) => (float) $line['conversion_snapshot']['amount']);
                $taxAmount = $preparedItems->sum(function (array $line) {
                    $amount = (float) $line['conversion_snapshot']['amount'];
                    $taxRate = (float) ($line['tax_rate'] ?? 0);

                    return $taxRate > 0 ? $amount * $taxRate / (100 + $taxRate) : 0;
                });
                $order = PurchaseOrder::create([
                    'purchase_order_no' => $this->nextNo('POD'),
                    'plan_id' => $plan->id,
                    'source_type' => 'purchase_plan',
                    'source_no' => $plan->plan_no,
                    'supplier_id' => $group['supplier_id'],
                    'order_date' => now()->toDateString(),
                    'purchase_status' => 'draft',
                    'audit_status' => 'pending',
                    'receipt_status' => 'not_received',
                    'total_qty' => $preparedItems->sum(fn (array $line) => (float) $line['conversion_snapshot']['purchase_qty']),
                    'tax_mode' => 'tax_included',
                    'tax_amount' => $taxAmount,
                    'total_amount' => $lineAmount,
                    'data_source' => 'manual',
                    'remark' => "由采购计划 {$plan->plan_no} 生成",
                ]);
                foreach ($preparedItems as $line) {
                    $conversion = $line['conversion_snapshot'];
                    $orderItem = PurchaseOrderItem::create([
                        'order_id' => $order->id,
                        'plan_id' => $plan->id,
                        'plan_item_id' => $line['plan_item_id'],
                        'plan_split_id' => $line['plan_split_id'],
                        'supplier_split_id' => $line['plan_split_id'],
                        'request_id' => $line['request_id'] ?? null,
                        'request_item_id' => $line['request_item_id'] ?? null,
                        'item_id' => $line['item_id'],
                        'supplier_id' => $group['supplier_id'],
                        'order_qty' => $conversion['purchase_qty'],
                        'remaining_qty' => $conversion['purchase_qty'],
                        'unit_price' => $conversion['purchase_unit_price'],
                        ...$conversion,
                        'tax_rate' => $line['tax_rate'],
                        'amount' => $conversion['amount'],
                        'expected_arrival_date' => $line['expected_arrival_date'],
                        'recommended_supplier_id_snapshot' => $line['recommended_supplier_id_snapshot'],
                        'recommended_price_snapshot' => $line['recommended_price_snapshot'],
                        'recommendation_basis_snapshot' => $line['recommendation_basis_snapshot'],
                        'recommendation_time' => $line['recommendation_time'],
                        'actual_supplier_id' => $group['supplier_id'],
                        'supplier_override_reason' => $line['supplier_override_reason'],
                        'supplier_override_remark' => $line['supplier_override_remark'],
                        'supplier_override_by' => $line['supplier_override_by'],
                        'supplier_override_at' => $line['supplier_override_at'],
                        'data_source' => 'manual',
                        'material_policy_id_snapshot' => $line['material_policy_id_snapshot'] ?? null,
                        'material_policy_version_snapshot' => $line['material_policy_version_snapshot'] ?? null,
                        'material_policy_snapshot' => $line['material_policy_snapshot'] ?? null,
                    ]);
                    PurchasePlanSupplierSplit::whereKey($line['plan_split_id'])->increment('ordered_qty', $line['purchase_qty']);
                    PurchasePlanSupplierSplit::whereKey($line['plan_split_id'])->update([
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                        'split_status' => 'ordered',
                    ]);
                    PurchasePlanItem::whereKey($line['plan_item_id'])->increment('ordered_qty', $line['purchase_qty']);
                }
                $orders[] = $order->fresh(['items.item', 'supplier']);
                $this->log('purchase_order', $order->id, 'create_from_plan', "由采购计划 {$plan->plan_no} 生成");
            }
            $remaining = PurchasePlanSupplierSplit::where('plan_id', $plan->id)->where(function ($q) {
                $q->whereNull('order_id')->orWhere('split_status', '!=', 'ordered');
            })->count();
            $plan->update(['order_status' => $remaining > 0 ? 'partially_ordered' : 'order_generated']);
            $this->log('purchase_plan', $plan->id, 'generate_orders', '生成采购订单：' . count($orders) . ' 张');
            return response()->json(['message' => '采购订单已生成', 'data' => $orders]);
        });
    }

    public function orders(Request $request, PurchaseReceiptApplicationService $receipts)
    {
        $query = PurchaseOrder::with(['supplier', 'plan', 'items.item.unit'])->latest('updated_at');
        $this->keyword($query, $request, ['purchase_order_no'], ['supplier' => ['supplier_code', 'supplier_name']]);
        if ($request->filled('status')) $query->where('purchase_status', $request->input('status'));
        $page = $query->paginate($this->perPage($request));
        $orders = $receipts->decorateOrders($page->getCollection());
        $taskMap = ApprovalTask::query()
            ->where('business_type', 'PURCHASE_ORDER')
            ->whereIn('business_id', $orders->pluck('id'))
            ->latest('id')
            ->get()
            ->unique('business_id')
            ->keyBy('business_id');
        $orders->each(function (PurchaseOrder $order) use ($taskMap) {
            $task = $taskMap->get($order->id);
            $order->setAttribute('approval_task_id', $task?->id);
            $order->setAttribute('approval_task_status', $task?->task_status);
        });
        $page->setCollection($orders);
        return response()->json($page);
    }

    public function storeOrder(Request $request, DocumentNumberService $numbers)
    {
        $payload = $this->validateOrder($request);
        $this->assertSupplierValid((int) $payload['supplier_id']);
        $operatorId = app(AuthContextService::class)->currentUser($request)?->legacy_id;
        return DB::transaction(function () use ($payload, $numbers, $operatorId) {
            $order = PurchaseOrder::create([
                'purchase_order_no' => $payload['purchase_order_no'] ?? $this->nextNo('POD'),
                'supplier_id' => $payload['supplier_id'],
                'order_date' => $payload['order_date'] ?? now()->toDateString(),
                'expected_arrival_date' => $payload['expected_arrival_date'] ?? null,
                'currency' => $payload['currency'] ?? 'CNY',
                'tax_mode' => $payload['tax_mode'] ?? 'tax_included',
                'settlement_method' => $payload['settlement_method'] ?? null,
                'delivery_method' => $payload['delivery_method'] ?? null,
                'freight_amount' => $payload['freight_amount'] ?? 0,
                'purchase_status' => 'draft',
                'audit_status' => 'pending',
                'receipt_status' => 'not_received',
                'remark' => $payload['remark'] ?? null,
                'data_source' => $payload['data_source'] ?? 'manual',
            ]);
            $this->saveOrderItems($order, $payload['items']);
            app(PurchaseAttachmentApplicationService::class)->bindDraft('order', $order->id, $payload['attachment_draft_token'] ?? null);
            if (!empty($payload['reservation_token'])) {
                $numbers->consume($payload['reservation_token'], 'purchase_order', $order->purchase_order_no, $operatorId, 'purchase_order', $order->id);
            }
            $this->log('purchase_order', $order->id, 'create', '手工新增采购订单');
            return response()->json(['message' => '采购订单已保存', 'data' => $order->fresh(['items.item.unit', 'supplier'])], 201);
        });
    }

    public function showOrder(
        int $id,
        PurchaseReceiptApplicationService $receipts,
        PurchaseOrderFinanceSummaryService $financeSummary,
    )
    {
        $order = PurchaseOrder::with([
            'supplier',
            'plan',
            'items.item.unit',
            'items.request',
            'items.requestItem.request',
            'items.plan',
            'items.planItem.plan',
            'items.planSplit.supplier',
            'items.supplierSplit.supplier',
            'items.supplierSplit.plan',
            'items.supplierSplit.planItem',
            'receipts.items',
            'attachments' => fn ($query) => $query->where('status', 'active')->latest('id'),
            'logs',
        ])->findOrFail($id);
        $order = $receipts->decorateOrder($order);
        $order->setAttribute('finance_summary', $financeSummary->forOrder($order));
        $approvalTask = ApprovalTask::query()
            ->with(['nodes.decisions'])
            ->where('business_type', 'PURCHASE_ORDER')
            ->where('business_id', $order->id)
            ->latest('id')
            ->first();
        $order->setAttribute('approval_task', $approvalTask);

        return response()->json($order);
    }

    public function updateOrder(Request $request, int $id)
    {
        $order = PurchaseOrder::findOrFail($id);
        abort_if(!($order->purchase_status === 'draft' || $order->audit_status === 'rejected'), 422, '只有草稿或驳回的采购订单可以编辑');
        $payload = $this->validateOrder($request);
        $this->assertSupplierValid((int) $payload['supplier_id']);
        return DB::transaction(function () use ($order, $payload) {
            $order->update([
                'supplier_id' => $payload['supplier_id'],
                'order_date' => $payload['order_date'] ?? $order->order_date,
                'expected_arrival_date' => $payload['expected_arrival_date'] ?? null,
                'currency' => $payload['currency'] ?? 'CNY',
                'tax_mode' => $payload['tax_mode'] ?? 'tax_included',
                'settlement_method' => $payload['settlement_method'] ?? null,
                'delivery_method' => $payload['delivery_method'] ?? null,
                'freight_amount' => $payload['freight_amount'] ?? 0,
                'remark' => $payload['remark'] ?? null,
            ]);
            PurchaseOrderItem::where('order_id', $order->id)->delete();
            $this->saveOrderItems($order, $payload['items']);
            app(PurchaseAttachmentApplicationService::class)->bindDraft('order', $order->id, $payload['attachment_draft_token'] ?? null);
            $this->log('purchase_order', $order->id, 'update', '编辑采购订单');
            return response()->json(['message' => '采购订单已更新', 'data' => $order->fresh(['items.item.unit', 'supplier'])]);
        });
    }

    public function submitOrder(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return DB::transaction(function () use ($request, $id, $workflow) {
            $user = app(AuthContextService::class)->currentUser($request);
            $order = $workflow->submitOrder($id, $this->operatorName($request), $user);
            $task = $order->getAttribute('approval_task');
            return response()->json(['message' => $task ? '采购订单已提交审核中心' : '采购订单已提交', 'data' => $order]);
        });
    }
    public function approveOrder(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        $this->authorizePermission($request, 'purchase.order.approve');
        $this->assertNoPendingApprovalTask($id);
        return response()->json(['message' => '采购订单已审核', 'data' => $workflow->approveOrder($id, $this->operatorName($request))]);
    }
    public function rejectOrder(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        $this->authorizePermission($request, 'purchase.order.approve');
        $this->assertNoPendingApprovalTask($id);
        return response()->json(['message' => '采购订单已驳回', 'data' => $workflow->rejectOrder($id, $this->operatorName($request))]);
    }
    public function cancelOrder(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return response()->json(['message' => '采购订单已取消', 'data' => $workflow->cancelOrder($id, $this->operatorName($request))]);
    }

    public function closeOrder(Request $request, int $id, PurchaseWorkflowApplicationService $workflow)
    {
        return response()->json(['message' => '采购订单已关闭', 'data' => $workflow->closeOrder($id, $this->operatorName($request))]);
    }

    public function orderToReceipt(Request $request, int $id, PurchaseReceiptApplicationService $receipts)
    {
        $receipt = $receipts->generateFromOrder($id, $this->operatorName($request));
        return response()->json(['message' => '到货单已生成；该草稿确认前不能重复生成', 'data' => $receipt]);
    }

    public function receipts(Request $request)
    {
        $query = PurchaseReceipt::with(['supplier', 'order', 'items.item.unit', 'items.orderItem', 'items.warehouse', 'items.location', 'items.defectHandlings'])->latest('updated_at');
        $this->keyword($query, $request, ['receipt_no'], ['supplier' => ['supplier_code', 'supplier_name']]);
        if ($request->filled('status')) $query->where('confirm_status', $request->input('status'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storeReceipt(Request $request)
    {
        $payload = $this->validateReceipt($request);
        $payload['_operator_id'] = app(AuthContextService::class)->currentUser($request)?->legacy_id;
        return DB::transaction(fn () => $this->persistReceipt($payload, null));
    }

    public function showReceipt(int $id)
    {
        return response()->json(PurchaseReceipt::with([
            'supplier', 'order', 'items.item.unit', 'items.orderItem', 'items.warehouse', 'items.location', 'items.allocations.warehouse', 'items.allocations.location', 'items.defectHandlings',
            'attachments' => fn ($query) => $query->where('status', 'active')->latest('id'),
            'logs',
        ])->findOrFail($id));
    }

    public function uploadAttachment(Request $request, PurchaseAttachmentApplicationService $attachments)
    {
        $payload = $request->validate([
            'file' => 'required|file|max:51200|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt,zip,rar',
            'document_type' => ['required', Rule::in(['order', 'receipt', 'exchange'])],
            'document_id' => 'nullable|integer',
            'draft_token' => 'nullable|string|max:120',
            'attachment_type' => ['nullable', Rule::in(['contract', 'quotation', 'delivery_note', 'inspection_report', 'invoice', 'technical', 'other'])],
        ]);
        abort_if(empty($payload['document_id']) && empty($payload['draft_token']), 422, '采购附件必须关联单据或当前新增草稿');

        if (!empty($payload['document_id'])) {
            $document = match ($payload['document_type']) {
                'order' => PurchaseOrder::findOrFail($payload['document_id']),
                'receipt' => PurchaseReceipt::findOrFail($payload['document_id']),
                'exchange' => PurchaseExchangeOrder::findOrFail($payload['document_id']),
            };
            $editable = $payload['document_type'] === 'order'
                ? ($document->purchase_status === 'draft' || $document->audit_status === 'rejected')
                : ($payload['document_type'] === 'receipt' ? $document->confirm_status === 'draft' : $document->exchange_status === 'processing');
            abort_unless($editable, 422, '只有可编辑状态的采购单据允许新增附件');
        }

        $file = $payload['file'];
        unset($payload['file']);
        $attachment = $attachments->upload($file, $payload + ['attachment_type' => 'other'], app(AuthContextService::class)->currentUser($request));
        if (!empty($payload['document_id'])) {
            $this->log('purchase_'.$payload['document_type'], (int) $payload['document_id'], 'upload_attachment', '上传采购附件：'.$attachment->original_name);
        }

        return response()->json(['message' => '采购附件已上传', 'data' => $this->attachmentPayload($attachment)], 201);
    }

    public function previewAttachment(Request $request, int $id)
    {
        $attachment = $this->visibleAttachment($request, $id);
        abort_unless($this->isInlinePreviewable($attachment), 422, '该文件类型不支持在线预览');
        return Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
        ]);
    }

    public function downloadAttachment(Request $request, int $id)
    {
        $attachment = $this->visibleAttachment($request, $id);
        return Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name);
    }

    public function deleteAttachment(Request $request, int $id, PurchaseAttachmentApplicationService $attachments)
    {
        $attachment = $this->visibleAttachment($request, $id);
        if ($attachment->document_id) {
            $document = match ($attachment->document_type) {
                'order' => PurchaseOrder::findOrFail($attachment->document_id),
                'receipt' => PurchaseReceipt::findOrFail($attachment->document_id),
                'exchange' => PurchaseExchangeOrder::findOrFail($attachment->document_id),
            };
            $editable = $attachment->document_type === 'order'
                ? ($document->purchase_status === 'draft' || $document->audit_status === 'rejected')
                : ($attachment->document_type === 'receipt' ? $document->confirm_status === 'draft' : $document->exchange_status === 'processing');
            abort_unless($editable, 422, '只有可编辑状态的采购单据允许删除附件');
        }
        $attachments->softDelete($attachment, $this->operatorName($request) ?: '系统');
        if ($attachment->document_id) $this->log('purchase_'.$attachment->document_type, (int) $attachment->document_id, 'delete_attachment', '删除采购附件：'.$attachment->original_name);

        return response()->json(['message' => '采购附件已删除', 'data' => ['id' => $attachment->id]]);
    }

    public function generateReceiptSerials(Request $request, InventorySerialApplicationService $serials)
    {
        $data = $request->validate([
            'item_id' => 'required|exists:erp_items,id',
            'quantity' => 'required|integer|min:1|max:500',
        ]);

        $item = Item::findOrFail($data['item_id']);

        return response()->json([
            'data' => $serials->generateForItem($item, (int) $data['quantity']),
        ]);
    }

    public function updateReceipt(Request $request, int $id)
    {
        $receipt = PurchaseReceipt::findOrFail($id);
        abort_if($receipt->confirm_status !== 'draft', 422, '只有草稿到货单可以编辑');
        $payload = $this->validateReceipt($request);
        return DB::transaction(fn () => $this->persistReceipt($payload, $receipt));
    }

    public function confirmReceipt(int $id, Request $request, PurchaseReceiptConfirmationApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase.receipt.confirm');
        $user = app(AuthContextService::class)->currentUser($request);
        $receipt = $service->confirm($id, $user?->legacy_id, $user?->username ?: '系统');

        return response()->json([
            'message' => $receipt->stock_post_status === 'not_required'
                ? '到货已确认，非库存物料已完成采购履约'
                : '到货已确认，库存物料等待库存过账',
            'data' => $receipt,
        ]);
    }

    public function defectRows(Request $request)
    {
        $this->authorizePermission($request, 'purchase.quality.view');
        $query = PurchaseReceiptItem::with(['receipt.supplier', 'receipt.order', 'item.unit', 'defectHandlings.logs', 'defectHandlings.replacementReceipt', 'defectHandlings.exchangeOrder'])
            ->whereHas('receipt', fn ($q) => $q->where('confirm_status', 'confirmed'))
            ->where(function ($q) {
                $q->where('unqualified_base_qty', '>', 0)
                    ->orWhereRaw('actual_base_qty > qualified_base_qty + unqualified_base_qty')
                    ->orWhereHas('defectHandlings');
            })
            ->latest('updated_at');

        if ($request->filled('receipt_no')) {
            $query->whereHas('receipt', fn ($q) => $q->where('receipt_no', 'like', '%' . $request->input('receipt_no') . '%'));
        }
        if ($request->filled('purchase_order_no')) {
            $query->whereHas('receipt.order', fn ($q) => $q->where('purchase_order_no', 'like', '%' . $request->input('purchase_order_no') . '%'));
        }
        if ($request->filled('supplier_id')) {
            $query->whereHas('receipt', fn ($q) => $q->where('supplier_id', $request->input('supplier_id')));
        }
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->whereHas('item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('handling_status')) {
            $query->whereHas('defectHandlings', fn ($q) => $q->where('handling_status', $request->input('handling_status')));
        }
        if ($request->filled('handling_method')) {
            $query->whereHas('defectHandlings', fn ($q) => $q->where('handling_method', $request->input('handling_method')));
        }
        if ($request->filled('date_from')) {
            $query->whereHas('receipt', fn ($q) => $q->whereDate('receipt_date', '>=', $request->input('date_from')));
        }
        if ($request->filled('date_to')) {
            $query->whereHas('receipt', fn ($q) => $q->whereDate('receipt_date', '<=', $request->input('date_to')));
        }

        $paginator = $query->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(function (PurchaseReceiptItem $line) {
            $receipt = $line->receipt;
            $receiptQty = (float) ($line->actual_base_qty ?? $line->standard_base_qty ?? $line->receipt_qty);
            $qualifiedQty = (float) ($line->qualified_base_qty ?? $line->qualified_qty);
            $unqualifiedQty = (float) ($line->unqualified_base_qty ?? $line->unqualified_qty);
            $pendingQty = max(0, $receiptQty - $qualifiedQty - $unqualifiedQty);
            $handledQty = (float) $line->defectHandlings
                ->filter(fn ($handling) => $handling->handling_status !== 'cancelled'
                    && $handling->handling_method !== 'pending')
                ->sum('handling_qty');
            $remainingQty = max(0, $unqualifiedQty + $pendingQty - $handledQty);

            return [
                'id' => "{$receipt->id}-{$line->id}",
                'receipt_id' => $receipt->id,
                'receipt_item_id' => $line->id,
                'receipt_no' => $receipt->receipt_no,
                'purchase_order_no' => $receipt->order?->purchase_order_no ?: '--',
                'supplier_id' => $receipt->supplier_id,
                'supplier_name' => $receipt->supplier?->supplier_name ?: '--',
                'item_id' => $line->item_id,
                'item_code' => $line->item?->item_code ?: '',
                'item_name' => $line->item?->item_name ?: '',
                'unit_name' => $line->base_unit_name_snapshot ?: $line->item?->unit?->unit_name ?: '',
                'receipt_qty' => $receiptQty,
                'qualified_qty' => $qualifiedQty,
                'unqualified_qty' => $unqualifiedQty,
                'pending_qty' => $pendingQty,
                'handled_qty' => $handledQty,
                'remaining_qty' => $remainingQty,
                'handlings' => $line->defectHandlings->sortByDesc('created_at')->values(),
                'latest_handling' => $line->defectHandlings->sortByDesc('created_at')->first(),
            ];
        }));

        return response()->json($paginator);
    }

    public function storeDefectHandling(Request $request, PurchaseDefectApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase.quality.handle');
        $data = $request->validate([
            'receipt_item_id' => 'required|exists:erp_purchase_receipt_items,id',
            'handling_method' => 'required|in:return_supplier,exchange,concession,repair,scrap,pending',
            'handling_qty' => 'required|numeric|min:0.0001',
            'defect_reason' => 'required|string|max:120',
            'defect_description' => 'nullable|string',
            'responsible_party' => 'required|string|max:80',
            'remark' => 'nullable|string',
        ]);

        $operator = app(AuthContextService::class)->currentUser($request);
        $handling = $service->create(
            $data,
            $operator?->legacy_id,
            $operator?->nickname ?: $operator?->username,
        );
        $this->log('purchase_receipt', $handling->receipt_id, 'defect_handling', "不合格品处理 {$handling->handling_no}");
        return response()->json(['message' => '不合格品处理单已创建，请按状态继续执行后续动作', 'data' => $handling], 201);
    }

    public function actionDefectHandling(Request $request, int $id, PurchaseDefectApplicationService $service)
    {
        $this->authorizePermission($request, 'purchase.quality.handle');
        $data = $request->validate([
            'action' => 'required|in:approve_concession,start_repair,complete_repair,approve_scrap,confirm_scrap',
            'result_description' => 'nullable|string|max:1000',
        ]);
        $operator = app(AuthContextService::class)->currentUser($request);
        $handling = $service->action($id, $data['action'], $data, $operator?->nickname ?: $operator?->username);
        return response()->json(['message' => '不合格品后续动作已完成', 'data' => $handling]);
    }

    public function priceHistories(Request $request)
    {
        $query = PurchasePriceHistory::with(['supplier', 'item.unit'])->latest('effective_date')->latest();
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));
        if ($request->filled('item_id')) $query->where('item_id', $request->input('item_id'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function supplierItemStats(Request $request)
    {
        $query = SupplierItemStat::with(['supplier', 'item.unit'])->latest('updated_at');
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));
        if ($request->filled('item_id')) $query->where('item_id', $request->input('item_id'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'plan_no' => 'nullable|string|max:80',
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'attachment_draft_token' => 'nullable|string|max:120',
            'plan_date' => 'nullable|date',
            'remark' => 'nullable|string',
            'data_source' => ['nullable', Rule::in(self::DATA_SOURCES)],
            'items' => 'required|array|min:1',
            'items.*.request_id' => 'nullable|exists:erp_purchase_requests,id',
            'items.*.request_item_id' => 'nullable|exists:erp_purchase_request_items,id',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.unit_id' => 'nullable|exists:erp_units,id',
            'items.*.required_qty' => 'required|numeric|min:0.0001',
            'items.*.warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.expected_date' => 'nullable|date',
            'items.*.splits' => 'nullable|array',
            'items.*.splits.*.supplier_id' => 'required_with:items.*.splits|exists:erp_suppliers,id',
            'items.*.splits.*.purchase_qty' => 'required_with:items.*.splits|numeric|min:0.0001',
            'items.*.splits.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.splits.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.splits.*.delivery_days' => 'nullable|integer|min:0|max:999',
            'items.*.splits.*.moq_qty' => 'nullable|numeric|min:0',
            'items.*.splits.*.expected_date' => 'nullable|date',
            'items.*.splits.*.recommended_supplier_id_snapshot' => 'nullable|exists:erp_suppliers,id',
            'items.*.splits.*.recommended_price_snapshot' => 'nullable|numeric|min:0',
            'items.*.splits.*.recommendation_basis_snapshot' => 'nullable|string|max:60',
            'items.*.splits.*.recommendation_time' => 'nullable|date',
            'items.*.splits.*.supplier_override_reason' => 'nullable|string|max:60',
            'items.*.splits.*.supplier_override_remark' => 'nullable|string|max:1000',
            'items.*.splits.*.remark' => 'nullable|string',
            'items.*.remark' => 'nullable|string',
        ]);
    }

    private function validateOrder(Request $request): array
    {
        return $request->validate([
            'purchase_order_no' => 'nullable|string|max:80',
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'attachment_draft_token' => 'nullable|string|max:120',
            'supplier_id' => 'required|exists:erp_suppliers,id',
            'order_date' => 'nullable|date',
            'expected_arrival_date' => 'nullable|date',
            // V1采购—财务联动仅允许公司本位币；外币采购必须等 Finance Core 后续阶段接入。
            // 该规则必须在写入采购订单前由后端强制执行，不能依赖前端隐藏币种选择。
            'currency' => ['nullable', Rule::in(['CNY'])],
            'tax_mode' => 'nullable|string|max:30',
            'settlement_method' => 'nullable|string|max:80',
            'delivery_method' => 'nullable|string|max:80',
            'freight_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string',
            'data_source' => ['nullable', Rule::in(self::DATA_SOURCES)],
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.purchase_unit_id' => 'nullable|exists:erp_units,id',
            'items.*.order_qty' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.expected_arrival_date' => 'nullable|date',
            'items.*.target_warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.recommended_supplier_id_snapshot' => 'nullable|exists:erp_suppliers,id',
            'items.*.recommended_price_snapshot' => 'nullable|numeric|min:0',
            'items.*.recommendation_basis_snapshot' => 'nullable|string|max:60',
            'items.*.recommendation_time' => 'nullable|date',
            'items.*.supplier_override_reason' => 'nullable|string|max:60',
            'items.*.supplier_override_remark' => 'nullable|string|max:1000',
            'items.*.remark' => 'nullable|string',
        ]);
    }

    private function validateReceipt(Request $request): array
    {
        return $request->validate([
            'receipt_no' => 'nullable|string|max:80',
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'order_id' => 'nullable|exists:erp_purchase_orders,id',
            'supplier_id' => 'required|exists:erp_suppliers,id',
            'receipt_date' => 'nullable|date',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer|exists:erp_purchase_receipt_items,id',
            'items.*.order_item_id' => 'nullable|exists:erp_purchase_order_items,id',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.purchase_unit_id' => 'nullable|exists:erp_units,id',
            'items.*.warehouse_id' => 'nullable|exists:erp_warehouses,id',
            'items.*.location_id' => 'nullable|exists:erp_locations,id',
            'items.*.receipt_qty' => 'required|numeric|min:0.0001',
            'items.*.actual_base_qty' => 'nullable|numeric|min:0',
            'items.*.difference_reason' => 'nullable|string|max:255',
            'items.*.qualified_qty' => 'nullable|numeric|min:0',
            'items.*.unqualified_qty' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.batch_no' => 'nullable|string|max:80',
            'items.*.expected_arrival_date' => 'nullable|date',
            'items.*.serial_text' => 'nullable|string',
            'items.*.serial_entries' => 'nullable|array',
            'items.*.serial_entries.*.serial_no' => 'required_with:items.*.serial_entries|string|max:120',
            'items.*.serial_entries.*.source' => 'required_with:items.*.serial_entries|in:supplier,system_generated',
            'items.*.serial_number_source' => 'nullable|in:supplier,system_generated',
            'items.*.allocations' => 'nullable|array',
            'items.*.allocations.*.warehouse_id' => 'required_with:items.*.allocations|exists:erp_warehouses,id',
            'items.*.allocations.*.location_id' => 'required_with:items.*.allocations|exists:erp_locations,id',
            'items.*.allocations.*.base_qty' => 'required_with:items.*.allocations|numeric|min:0.00000001',
            'items.*.allocations.*.serial_nos' => 'nullable|array',
            'items.*.allocations.*.serial_nos.*' => 'string|max:120',
            'items.*.remark' => 'nullable|string',
        ]);
    }

    private function savePlanItems(PurchasePlan $plan, array $items): void
    {
        foreach ($items as $line) {
            $item = Item::with('unit.standardUnit')->findOrFail($line['item_id']);
            $baseUnit = app(\App\Services\Erp\UnitConversionDomainService::class)->canonicalUnit($item->unit);
            $requiredQty = (float) $line['required_qty'];
            $splits = $line['splits'] ?? [];
            $allocatedQty = collect($splits)->sum(fn ($s) => (float) ($s['purchase_qty'] ?? 0));
            abort_if($allocatedQty > $requiredQty, 422, '供应商拆分数量不能超过物料总需求数量');
            $planItem = PurchasePlanItem::create([
                'plan_id' => $plan->id,
                'request_id' => $line['request_id'] ?? null,
                'request_item_id' => $line['request_item_id'] ?? null,
                'item_id' => $line['item_id'],
                'unit_id' => $line['unit_id'] ?? $item->unit_id,
                'plan_qty' => $requiredQty,
                'required_qty' => $requiredQty,
                'allocated_qty' => $allocatedQty,
                'remaining_qty' => max(0, $requiredQty - $allocatedQty),
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'expected_arrival_date' => $line['expected_date'] ?? null,
                'status' => $allocatedQty <= 0 ? 'unallocated' : ($allocatedQty < $requiredQty ? 'partial_allocated' : 'allocated'),
                'remark' => $line['remark'] ?? null,
                'data_source' => 'manual',
                ...$this->materialPolicySnapshotForPlanLine($item, $line),
            ]);
            foreach ($splits as $split) {
                $supplier = Supplier::find($split['supplier_id']);
                $this->assertSupplierValid((int) $split['supplier_id']);
                $this->assertRecommendationOverride($split, (int) $split['supplier_id']);
                abort_if(!$supplier || $this->isBadSupplierName($supplier), 422, '供应商无效或名称异常，不能保存供应商拆分');
                $qty = (float) $split['purchase_qty'];
                $price = (float) ($split['unit_price'] ?? 0);
                PurchasePlanSupplierSplit::create([
                    'plan_id' => $plan->id,
                    'plan_item_id' => $planItem->id,
                    'request_id' => $planItem->request_id,
                    'request_item_id' => $planItem->request_item_id,
                    'item_id' => $line['item_id'],
                    'supplier_id' => $split['supplier_id'],
                    'purchase_qty' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $split['tax_rate'] ?? 0,
                    'delivery_days' => $split['delivery_days'] ?? 0,
                    'moq_qty' => $split['moq_qty'] ?? 0,
                    'expected_date' => $split['expected_date'] ?? ($line['expected_date'] ?? null),
                    'amount' => $qty * $price,
                    'status' => 'draft',
                    'split_status' => 'not_ordered',
                    'recommended_supplier_id_snapshot' => $split['recommended_supplier_id_snapshot'] ?? null,
                    'recommended_price_snapshot' => $split['recommended_price_snapshot'] ?? null,
                    'recommendation_basis_snapshot' => $split['recommendation_basis_snapshot'] ?? null,
                    'recommendation_time' => $split['recommendation_time'] ?? null,
                    'actual_supplier_id' => $split['supplier_id'],
                    'supplier_override_reason' => $split['supplier_override_reason'] ?? null,
                    'supplier_override_remark' => $split['supplier_override_remark'] ?? null,
                    'supplier_override_by' => !empty($split['supplier_override_reason'])
                        ? app(AuthContextService::class)->currentUser(request())?->legacy_id
                        : null,
                    'supplier_override_at' => !empty($split['supplier_override_reason']) ? now() : null,
                    'remark' => $split['remark'] ?? null,
                    'data_source' => 'manual',
                ]);
            }
        }
        $this->refreshPlanTotal($plan->id);
    }

    private function saveRequestItems(PurchaseRequest $request, array $items): void
    {
        $totalQty = 0;
        foreach ($items as $line) {
            $item = Item::with('unit.standardUnit')->findOrFail($line['item_id']);
            $baseUnit = app(\App\Services\Erp\UnitConversionDomainService::class)->canonicalUnit($item->unit);
            $qty = (float) $line['request_qty'];
            PurchaseRequestItem::create([
                'request_id' => $request->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'unit_id' => $baseUnit?->id ?: $item->unit_id,
                'request_qty' => $qty,
                'converted_qty' => 0,
                'remaining_qty' => $qty,
                'expected_date' => $line['expected_date'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'priority' => $line['priority'] ?? 'normal',
                'line_status' => 'open',
                'remark' => $line['remark'] ?? null,
                'data_source' => 'manual',
                ...app(\App\Services\Erp\MaterialPolicySnapshotService::class)->fromItem($item),
            ]);
            $totalQty += $qty;
        }
        $request->update([
            'item_id' => $items[0]['item_id'] ?? null,
            'request_qty' => $totalQty,
            'planned_qty' => 0,
            'required_date' => $items[0]['expected_date'] ?? null,
        ]);
    }

    private function saveOrderItems(PurchaseOrder $order, array $items): void
    {
        $totalQty = 0; $totalAmount = 0; $taxAmount = 0;
        foreach ($items as $line) {
            $this->assertRecommendationOverride($line, (int) $order->supplier_id);
            $item = Item::with('activeMaterialPolicy')->findOrFail($line['item_id']);
            $qty = (float) $line['order_qty'];
            $price = (float) ($line['unit_price'] ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $amount = $qty * $price;
            $finance = app(\App\Services\Erp\PurchaseFinancialFactService::class);
            $amountFacts = $finance->amountFacts($amount, $taxRate, (string) $order->tax_mode);
            $conversion = app(\App\Services\Erp\PurchaseConversionApplicationService::class)->orderLineSnapshot($line);
            PurchaseOrderItem::create([
                'order_id' => $order->id,
                'plan_id' => $order->plan_id,
                'item_id' => $line['item_id'],
                'order_qty' => $qty,
                'remaining_qty' => $qty,
                'unit_price' => $price,
                ...$conversion,
                'tax_rate' => $taxRate,
                'amount' => $amount,
                'currency_snapshot' => $order->currency ?: 'CNY',
                'tax_mode_snapshot' => $order->tax_mode ?: 'tax_included',
                ...$amountFacts,
                'contract_amount_snapshot' => $amountFacts['amount_incl_tax'],
                'expected_arrival_date' => $line['expected_arrival_date'] ?? null,
                'target_warehouse_id' => $line['target_warehouse_id'] ?? null,
                'recommended_supplier_id_snapshot' => $line['recommended_supplier_id_snapshot'] ?? null,
                'recommended_price_snapshot' => $line['recommended_price_snapshot'] ?? null,
                'recommendation_basis_snapshot' => $line['recommendation_basis_snapshot'] ?? null,
                'recommendation_time' => $line['recommendation_time'] ?? null,
                'actual_supplier_id' => $order->supplier_id,
                'supplier_override_reason' => $line['supplier_override_reason'] ?? null,
                'supplier_override_remark' => $line['supplier_override_remark'] ?? null,
                'supplier_override_by' => !empty($line['supplier_override_reason'])
                    ? app(AuthContextService::class)->currentUser(request())?->legacy_id
                    : null,
                'supplier_override_at' => !empty($line['supplier_override_reason']) ? now() : null,
                'remark' => $line['remark'] ?? null,
                'data_source' => 'manual',
                ...$this->materialPolicySnapshotForOrderLine($item, $line),
            ]);
            $totalQty += $qty;
            $totalAmount += $amount;
            $taxAmount += $order->tax_mode === 'tax_excluded'
                ? $amount * $taxRate / 100
                : ($taxRate > 0 ? $amount * $taxRate / (100 + $taxRate) : 0);
        }
        $contractAmount = $totalAmount + ($order->tax_mode === 'tax_excluded' ? $taxAmount : 0) + (float) ($order->freight_amount ?? 0);
        $order->update([
            'total_qty' => $totalQty,
            'total_amount' => $contractAmount,
            'amount_excl_tax' => round((float) $order->items()->sum('amount_excl_tax'), 4),
            'tax_amount' => round($taxAmount, 4),
            'amount_incl_tax' => round((float) $order->items()->sum('amount_incl_tax') + (float) ($order->freight_amount ?? 0), 4),
            'finance_fact_status' => 'pending',
        ]);
    }

    private function persistReceipt(array $payload, ?PurchaseReceipt $receipt)
    {
        $receiptService = app(PurchaseReceiptApplicationService::class);
        if ($receipt) $payload = $receiptService->protectReplacementDraft($receipt, $payload);
        $receiptService->assertDraftAllocation($payload, $receipt);
        $created = !$receipt;
        if (!$receipt) {
            $receipt = PurchaseReceipt::create([
                'receipt_no' => $payload['receipt_no'] ?? $this->nextNo('PRC'),
                'order_id' => $payload['order_id'] ?? null,
                'supplier_id' => $payload['supplier_id'],
                'receipt_date' => $payload['receipt_date'] ?? now()->toDateString(),
                'receipt_status' => 'draft',
                'confirm_status' => 'draft',
                'stock_post_status' => 'pending',
                'remark' => $payload['remark'] ?? null,
                'data_source' => 'manual',
            ]);
            $action = 'create';
        } else {
            $receipt->update([
                'order_id' => $receipt->settlement_mode === 'replacement_no_charge' ? $receipt->order_id : ($payload['order_id'] ?? null),
                'supplier_id' => $receipt->settlement_mode === 'replacement_no_charge' ? $receipt->supplier_id : $payload['supplier_id'],
                'receipt_date' => $payload['receipt_date'] ?? $receipt->receipt_date,
                'remark' => $payload['remark'] ?? null,
            ]);
            PurchaseReceiptItem::where('receipt_id', $receipt->id)->delete();
            $action = 'update';
        }
        if ($created && !empty($payload['reservation_token'])) {
            app(DocumentNumberService::class)->consume(
                $payload['reservation_token'],
                'purchase_receipt',
                $receipt->receipt_no,
                $payload['_operator_id'] ?? null,
                'purchase_receipt',
                $receipt->id
            );
        }
        foreach ($payload['items'] as $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            $orderItem = !empty($line['order_item_id'])
                ? PurchaseOrderItem::query()->find($line['order_item_id'])
                : null;
            $materialPolicy = $orderItem
                ? $this->materialPolicySnapshot(
                    $item,
                    $orderItem->material_policy_snapshot,
                    $orderItem->material_policy_id_snapshot,
                    $orderItem->material_policy_version_snapshot,
                )
                : app(\App\Services\Erp\MaterialPolicySnapshotService::class)->fromItem($item);
            $stockManaged = (bool) data_get($materialPolicy, 'material_policy_snapshot.is_stock_managed');
            $qty = (float) $line['receipt_qty'];
            $price = (float) ($line['unit_price'] ?? 0);
            $batchNo = $stockManaged ? trim((string) ($line['batch_no'] ?? '')) : null;
            if ($stockManaged && $batchNo === '') {
                $batchNo = app(DocumentNumberService::class)->next('inventory_batch', 'BAT');
            }
            $conversion = app(\App\Services\Erp\PurchaseConversionApplicationService::class)->receiptLineSnapshot(
                $line,
                $receipt->settlement_mode === 'replacement_no_charge',
            );
            $receiptItem = PurchaseReceiptItem::create([
                'receipt_id' => $receipt->id,
                'order_item_id' => $line['order_item_id'] ?? null,
                'item_id' => $line['item_id'],
                'is_stock_item_snapshot' => $stockManaged,
                'warehouse_id' => $stockManaged ? ($line['warehouse_id'] ?? null) : null,
                'location_id' => $stockManaged ? ($line['location_id'] ?? null) : null,
                'receipt_qty' => $qty,
                'qualified_qty' => $line['qualified_qty'] ?? $qty,
                'unqualified_qty' => $line['unqualified_qty'] ?? 0,
                'unit_price' => $price,
                'tax_rate' => $line['tax_rate'] ?? 13,
                'receipt_cost' => $qty * $price,
                ...$conversion,
                'batch_no' => $batchNo,
                'expected_arrival_date' => $line['expected_arrival_date'] ?? null,
                'serial_text' => $stockManaged ? ($line['serial_text'] ?? null) : null,
                'serial_entries' => $stockManaged ? ($line['serial_entries'] ?? null) : null,
                'serial_number_source' => $stockManaged ? ($line['serial_number_source'] ?? null) : null,
                'inventory_posting_status' => $stockManaged ? 'pending' : 'not_required',
                'remark' => $line['remark'] ?? null,
                'data_source' => $line['data_source'] ?? 'manual',
                ...$materialPolicy,
            ]);
            app(PurchaseReceiptAllocationService::class)->replace(
                $receiptItem,
                $stockManaged ? ($line['allocations'] ?? []) : [],
            );
        }
        app(PurchaseAttachmentApplicationService::class)->bindDraft('receipt', $receipt->id, $payload['attachment_draft_token'] ?? null);
        $this->refreshReceiptTotal($receipt->id);
        $this->log('purchase_receipt', $receipt->id, $action, $action === 'create' ? '新增到货单' : '编辑到货单');
        return response()->json(['message' => '到货单已保存', 'data' => $receipt->fresh(['items.item.unit', 'items.warehouse', 'items.location', 'items.allocations.warehouse', 'items.allocations.location', 'order', 'supplier', 'attachments'])], $action === 'create' ? 201 : 200);
    }

    private function visibleAttachment(Request $request, int $id): PurchaseAttachment
    {
        $attachment = PurchaseAttachment::where('status', 'active')->findOrFail($id);
        if (!$attachment->document_id) {
            $user = app(AuthContextService::class)->currentUser($request);
            abort_unless($user && ((int) $attachment->uploaded_by_legacy_id === (int) $user->legacy_id || app(AuthContextService::class)->isSuperAdmin($user)), 403, '无权访问该临时采购附件');
        }
        return $attachment;
    }

    private function attachmentPayload(PurchaseAttachment $attachment): array
    {
        return [
            ...$attachment->toArray(),
            'previewable' => $this->isInlinePreviewable($attachment),
            'preview_url' => '/api/v1/erp/purchase/attachments/'.$attachment->id.'/preview',
            'download_url' => '/api/v1/erp/purchase/attachments/'.$attachment->id.'/download',
        ];
    }

    private function isInlinePreviewable(PurchaseAttachment $attachment): bool
    {
        $extension = strtolower((string) pathinfo((string) $attachment->original_name, PATHINFO_EXTENSION));

        return in_array(strtolower((string) $attachment->mime_type), self::INLINE_PREVIEW_MIME_TYPES, true)
            || in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx'], true);
    }

    private function groupPlanItemsForOrders(PurchasePlan $plan): array
    {
        $splits = $plan->items->flatMap->splits
            ->filter(fn ($split) => $split->supplier_id && (float) $split->purchase_qty > (float) $split->ordered_qty);
        return $splits->groupBy('supplier_id')->map(function ($lines, $supplierId) {
                $supplier = $lines->first()->supplier;
                abort_if(!$supplier || $this->isBadSupplierName($supplier), 422, '供应商为空或名称异常，不能生成采购订单');
                $items = $lines->map(function ($split) {
                    $qty = (float) $split->purchase_qty - (float) $split->ordered_qty;
                    $price = (float) $split->unit_price;
                    return [
                        'plan_item_id' => $split->plan_item_id,
                        'plan_split_id' => $split->id,
                        'request_id' => $split->request_id,
                        'request_item_id' => $split->request_item_id,
                        'item_id' => $split->item_id,
                        'item_name' => $split->item->item_name ?? '',
                        'purchase_qty' => $qty,
                        'unit_price' => $price,
                        'tax_rate' => (float) $split->tax_rate,
                        'amount' => $qty * $price,
                        'expected_arrival_date' => $split->expected_date,
                        'recommended_supplier_id_snapshot' => $split->recommended_supplier_id_snapshot,
                        'recommended_price_snapshot' => $split->recommended_price_snapshot,
                        'recommendation_basis_snapshot' => $split->recommendation_basis_snapshot,
                        'recommendation_time' => $split->recommendation_time,
                        'supplier_override_reason' => $split->supplier_override_reason,
                        'supplier_override_remark' => $split->supplier_override_remark,
                        'supplier_override_by' => $split->supplier_override_by,
                        'supplier_override_at' => $split->supplier_override_at,
                        'material_policy_id_snapshot' => $split->planItem?->material_policy_id_snapshot,
                        'material_policy_version_snapshot' => $split->planItem?->material_policy_version_snapshot,
                        'material_policy_snapshot' => $split->planItem?->material_policy_snapshot,
                    ];
                })->values()->all();
                return [
                    'supplier_id' => (int) $supplierId,
                    'supplier_name' => $supplier->supplier_name,
                    'line_count' => count($items),
                    'total_qty' => array_sum(array_column($items, 'purchase_qty')),
                    'total_amount' => array_sum(array_column($items, 'amount')),
                    'items' => $items,
                ];
            })->values()->all();
    }

    private function materialPolicySnapshotForPlanLine(Item $item, array $line): array
    {
        $requestItem = !empty($line['request_item_id'])
            ? PurchaseRequestItem::query()->find($line['request_item_id'])
            : null;

        return $requestItem
            ? $this->materialPolicySnapshot(
                $item,
                $requestItem->material_policy_snapshot,
                $requestItem->material_policy_id_snapshot,
                $requestItem->material_policy_version_snapshot,
            )
            : app(\App\Services\Erp\MaterialPolicySnapshotService::class)->fromItem($item);
    }

    private function materialPolicySnapshotForOrderLine(Item $item, array $line): array
    {
        $planItem = !empty($line['plan_item_id'])
            ? PurchasePlanItem::query()->find($line['plan_item_id'])
            : null;

        return $planItem
            ? $this->materialPolicySnapshot(
                $item,
                $planItem->material_policy_snapshot,
                $planItem->material_policy_id_snapshot,
                $planItem->material_policy_version_snapshot,
            )
            : app(\App\Services\Erp\MaterialPolicySnapshotService::class)->fromItem($item);
    }

    private function materialPolicySnapshot(
        Item $item,
        ?array $snapshot,
        ?int $policyId,
        ?int $versionNo,
    ): array {
        if (is_array($snapshot) && $snapshot !== []) {
            return [
                'material_policy_id_snapshot' => $policyId,
                'material_policy_version_snapshot' => $versionNo,
                'material_policy_snapshot' => $snapshot,
            ];
        }

        return app(\App\Services\Erp\MaterialPolicySnapshotService::class)->fromItem($item);
    }

    private function refreshPlanTotal(int $planId): void
    {
        $totals = PurchasePlanItem::where('plan_id', $planId)
            ->selectRaw('COALESCE(SUM(required_qty),0) qty')->first();
        $amount = PurchasePlanSupplierSplit::where('plan_id', $planId)->sum('amount');
        PurchasePlan::whereKey($planId)->update(['total_qty' => $totals->qty, 'total_amount' => $amount]);
    }

    private function isBadSupplierName(Supplier $supplier): bool
    {
        $name = trim((string) $supplier->supplier_name);
        return $name === '' || preg_match('/^\?+$/', $name) || str_contains($name, '????');
    }

    private function assertOrderCanReceive(PurchaseOrder $order): void
    {
        abort_if($order->audit_status !== 'approved', 422, '未审核采购订单不能生成到货单');
        abort_if(in_array($order->purchase_status, ['closed', 'cancelled'], true), 422, '已关闭或已取消订单不能生成到货单');
        abort_if($order->receipt_status === 'received', 422, '已全部到货订单不能再次生成到货单');
    }

    private function assertSupplierValid(int $supplierId): void
    {
        $supplier = Supplier::find($supplierId);
        abort_if(!$supplier || $this->isBadSupplierName($supplier), 422, '供应商为空或名称异常，请先修复供应商资料');
        abort_unless(
            app(SupplierCapabilityService::class)->supplierEligibleQuery()->whereKey($supplierId)->exists(),
            422,
            '该供应商已停用、未通过审批、受限、黑名单或处于质量冻结状态，不能用于采购。'
        );
    }

    private function assertRecommendationOverride(array $line, int $actualSupplierId): void
    {
        $recommendedSupplierId = (int) ($line['recommended_supplier_id_snapshot'] ?? 0);
        if (!$recommendedSupplierId || $recommendedSupplierId === $actualSupplierId) return;

        $reason = trim((string) ($line['supplier_override_reason'] ?? ''));
        abort_if($reason === '', 422, '选择非系统推荐供应商时必须填写调整原因。');
        if ($reason === 'other') {
            abort_if(trim((string) ($line['supplier_override_remark'] ?? '')) === '', 422, '调整原因选择“其他”时必须填写说明。');
        }
    }

    private function refreshReceiptTotal(int $receiptId): void
    {
        $totals = PurchaseReceiptItem::where('receipt_id', $receiptId)
            ->selectRaw('COALESCE(SUM(receipt_qty),0) qty, COALESCE(SUM(qualified_qty),0) q, COALESCE(SUM(unqualified_qty),0) uq, COALESCE(SUM(receipt_cost),0) amount, COALESCE(SUM(receipt_cost * tax_rate / 100),0) tax_amount')->first();
        PurchaseReceipt::whereKey($receiptId)->update([
            'total_receipt_qty' => $totals->qty,
            'total_qualified_qty' => $totals->q,
            'total_unqualified_qty' => $totals->uq,
            'total_amount' => $totals->amount,
            'tax_amount' => $totals->tax_amount,
        ]);
        app(\App\Services\Erp\PurchaseReceiptSettlementService::class)->refresh($receiptId);
    }

    private function refreshOrderReceiptStatus(int $orderId): void
    {
        $items = PurchaseOrderItem::where('order_id', $orderId)->get();
        $total = (float) $items->sum('order_qty');
        $received = (float) $items->sum('received_qty');
        $receiptStatus = $received <= 0 ? 'not_received' : ($received >= $total ? 'received' : 'partial');
        $purchaseStatus = $receiptStatus === 'received' ? 'received' : 'partially_received';
        PurchaseOrder::whereKey($orderId)->update(['receipt_status' => $receiptStatus, 'purchase_status' => $purchaseStatus]);
    }

    private function keyword(Builder $query, Request $request, array $columns, array $relations = []): void
    {
        $keyword = trim((string) $request->input('keyword'));
        if ($keyword === '') return;
        $query->where(function (Builder $q) use ($keyword, $columns, $relations) {
            foreach ($columns as $column) $q->orWhere($column, 'like', "%{$keyword}%");
            foreach ($relations as $relation => $fields) {
                $q->orWhereHas($relation, fn (Builder $r) => collect($fields)->each(fn ($field) => $r->orWhere($field, 'like', "%{$keyword}%")));
            }
        });
    }

    private function perPage(Request $request): int
    {
        return min(100, max(5, (int) $request->input('per_page', 20)));
    }

    private function nextNo(string $prefix): string
    {
        $type = match ($prefix) {
            'PRQ' => 'purchase_request',
            'PPL' => 'purchase_plan',
            'POD' => 'purchase_order',
            'PRC' => 'purchase_receipt',
            'PDH' => 'purchase_defect_handling',
            'PRT' => 'purchase_return',
            default => strtolower($prefix),
        };

        return app(DocumentNumberService::class)->next($type, $prefix);
    }

    private function log(string $targetType, int $targetId, string $action, string $content): void
    {
        $operator = app(AuthContextService::class)->currentUser(request());
        DB::table('erp_purchase_logs')->insert([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'content' => $content,
            'operator' => $operator?->nickname ?: $operator?->username ?: '系统任务',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function operatorName(Request $request): ?string
    {
        $operator = app(AuthContextService::class)->currentUser($request);

        return $operator?->nickname ?: $operator?->username;
    }

    private function assertNoPendingApprovalTask(int $orderId): void
    {
        $pending = DB::table('erp_approval_tasks')
            ->where('business_id', $orderId)
            ->where('task_status', 'PENDING')
            ->where('metadata->business_object_code', 'PURCHASE_ORDER')
            ->exists();
        abort_if($pending, 422, '该采购订单已进入审核中心，请在审核工作台处理，不能从采购订单页面绕过流程直接审核。');
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission,
        );

        return $user;
    }
}
