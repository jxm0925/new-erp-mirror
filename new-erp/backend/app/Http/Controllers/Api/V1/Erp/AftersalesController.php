<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\AftersalesAction;
use App\Models\Erp\AftersalesAttachment;
use App\Models\Erp\AftersalesCase;
use App\Models\Erp\AftersalesClaim;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\AftersalesActionApplicationService;
use App\Services\Erp\AftersalesAttachmentService;
use App\Services\Erp\AftersalesCaseApplicationService;
use App\Services\Erp\AftersalesClaimApplicationService;
use App\Services\Erp\AftersalesReshipApplicationService;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AftersalesController extends Controller
{
    public function __construct(
        private readonly AftersalesCaseApplicationService $cases,
        private readonly AftersalesActionApplicationService $actions,
        private readonly AftersalesClaimApplicationService $claims,
    ) {}

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'aftersales.view');
        $query = $this->visibleCases($request)->with(['order:id,sales_order_no,customer_name,customer_phone', 'items', 'actions.salesReturn:id,return_no,return_status']);
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('case_no', 'like', "%{$keyword}%")
                ->orWhereHas('order', fn ($o) => $o->where('sales_order_no', 'like', "%{$keyword}%")->orWhere('origin_order_no', 'like', "%{$keyword}%")));
        }
        if ($request->filled('customer_keyword')) {
            $kw = trim((string) $request->input('customer_keyword'));
            $query->whereHas('order', fn ($o) => $o->where(fn ($b) => $b->where('customer_name', 'like', "%{$kw}%")->orWhere('customer_phone', 'like', "%{$kw}%")));
        }
        if ($request->filled('serial_no')) {
            $serial = trim((string) $request->input('serial_no'));
            $query->whereHas('items', fn ($i) => $i->where('serial_no', 'like', "%{$serial}%"));
        }
        if ($request->filled('issue_type')) $query->where('issue_type', $request->input('issue_type'));
        if ($request->filled('action_type')) $query->whereHas('actions', fn ($a) => $a->where('action_type', $request->input('action_type'))->where('status', '!=', 'cancelled'));
        if ($request->filled('owner')) $query->where(fn ($q) => $q->where('owner_name', 'like', '%'.trim((string) $request->input('owner')).'%')->orWhere('owner_legacy_id', (int) $request->input('owner')));
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->input('date_to'));
        if ($request->filled('status') && $request->input('status') !== 'all') $query->where('status', $request->input('status'));

        $paginator = $query->latest('updated_at')->paginate($this->perPage($request));
        $counts = $this->visibleCases($request)->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status');
        $data = collect($paginator->items())->map(fn ($case) => $this->listRow($case, $request))->all();
        return response()->json([
            'data' => $data,
            'meta' => ['total' => $paginator->total(), 'current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(), 'status_counts' => $counts->all() + ['all' => $counts->sum()]],
        ]);
    }

    public function orderCases(Request $request, int $orderId)
    {
        $this->authorizePermission($request, 'aftersales.view');
        $orderQuery = SalesOrder::query()->whereKey($orderId);
        $this->applySalesOrderVisibility($orderQuery, $request, true);
        abort_unless($orderQuery->exists(), 403, '无权查看该订单的售后记录。');
        $cases = AftersalesCase::query()->with(['items', 'actions.salesReturn:id,return_no,return_status'])
            ->where('sales_order_id', $orderId)->latest('id')->get();
        return response()->json(['data' => $cases->map(fn ($c) => $this->listRow($c, $request))->all()]);
    }

    public function show(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.view');
        $case = $this->visibleCases($request)->with([
            'order:id,sales_order_no,origin_order_no,customer_name,customer_phone',
            'items.orderLine:id,product_name,sku_name,item_name', 'actions.items.caseItem', 'actions.salesReturn:id,return_no,return_status,return_date', 'actions.salesReturn.items:id,sales_return_id,requested_base_qty,received_base_qty',
            'actions.reships.items', 'reships.items', 'claims', 'logs',
            'attachments' => fn ($q) => $q->where(fn ($a) => $a->whereNotNull('claim_id')->orWhereNull('draft_token')
                ->orWhere('attachment_scope', 'case')->orWhere('uploaded_by_legacy_id', (int) $user->legacy_id)),
        ])->findOrFail($id);
        return $this->amountSafeJson($request, $case);
    }

    /** 可售后商品行：原发/补发数量、退货/活跃/已完成占用，以及仍在客户处的序列号候选。 */
    public function eligible(Request $request, int $orderId)
    {
        $this->authorizePermission($request, 'aftersales.view');
        $orderQuery = SalesOrder::query()->whereKey($orderId);
        $this->applySalesOrderVisibility($orderQuery, $request, true);
        $order = $orderQuery->with(['lines' => fn ($q) => $q->orderBy('id')])->firstOrFail();
        $rows = [];
        foreach ($order->lines as $line) {
            if (! AftersalesCaseApplicationService::isEligibleLine($line)) continue;
            $quantities = AftersalesCaseApplicationService::quantitiesForCase($line);
            $serials = [];
            $serialIds = $this->cases->shippedSerialIds($line);
            if ($serialIds) {
                $serials = DB::table('erp_inventory_serials')->whereIn('id', $serialIds)->orderBy('serial_no')
                    ->get(['id', 'serial_no', 'batch_no'])->map(fn ($s) => [
                        'inventory_serial_id' => (int) $s->id, 'serial_no' => $s->serial_no, 'batch_no' => $s->batch_no,
                        'in_case' => $quantities['serial_occupations'][(int) $s->id]['in_case'] ?? false,
                        'completed_case' => $quantities['serial_occupations'][(int) $s->id]['completed_case'] ?? false,
                    ])->all();
            }
            $rows[] = [
                'sales_order_line_id' => (int) $line->id, 'line_no' => (int) ($line->line_no ?? 0),
                'item_id' => (int) $line->item_id, 'product_name' => $line->product_name, 'sku_name' => $line->sku_name,
                'item_name' => $line->item_name, 'order_qty' => (float) $line->order_qty,
                'shipped_qty' => (float) $line->shipped_qty, 'reshipped_qty' => $quantities['reshipped_qty'],
                'returned_qty' => $quantities['returned_qty'], 'in_aftersales_qty' => $quantities['in_aftersales_qty'],
                'completed_aftersales_qty' => $quantities['completed_aftersales_qty'], 'available_qty' => $quantities['available_qty'],
                'serial_tracked' => $serialIds !== [], 'serials' => $serials,
            ];
        }
        return response()->json(['data' => $rows, 'meta' => ['sales_order_no' => $order->sales_order_no, 'has_shipped' => $order->lines->contains(fn ($l) => (float) $l->shipped_qty > 0)]]);
    }

    public function store(Request $request)
    {
        $user = $this->authorizePermission($request, 'aftersales.create');
        $payload = $request->validate([
            'reservation_token' => 'nullable|uuid', 'creation_session_id' => 'nullable|uuid',
            'sales_order_id' => 'required|integer|exists:erp_sales_orders,id',
            'owner_legacy_id' => 'nullable|integer|exists:erp_legacy_admin_users,legacy_id',
            'issue_type' => 'required|string|max:30', 'warranty_status' => 'nullable|string|max:20',
            'responsibility' => 'nullable|string|max:30', 'reported_at' => 'required|date',
            'description' => 'required|string|max:2000', 'attachment_draft_token' => 'nullable|string|max:120',
            'accept' => 'nullable|boolean',
            'items' => 'required|array|min:1', 'items.*.sales_order_line_id' => 'required|integer',
            'items.*.qty' => 'nullable|numeric|min:0', 'items.*.inventory_serial_id' => 'nullable|integer',
            'items.*.serial_no' => 'nullable|string|max:120', 'items.*.remark' => 'nullable|string|max:200',
        ]);
        $this->assertOrderVisible($request, (int) $payload['sales_order_id']);
        $payload = $this->withOwnerName($payload);
        return $this->amountSafeJson($request, $this->cases->create($payload, $this->actor($user)), 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.create');
        $payload = $request->validate([
            'owner_legacy_id' => 'nullable|integer|exists:erp_legacy_admin_users,legacy_id',
            'issue_type' => 'nullable|string|max:30', 'warranty_status' => 'nullable|string|max:20',
            'responsibility' => 'nullable|string|max:30', 'reported_at' => 'nullable|date',
            'description' => 'nullable|string|max:2000',
            'items' => 'nullable|array|min:1', 'items.*.sales_order_line_id' => 'required|integer',
            'items.*.qty' => 'nullable|numeric|min:0', 'items.*.inventory_serial_id' => 'nullable|integer',
            'items.*.serial_no' => 'nullable|string|max:120', 'items.*.remark' => 'nullable|string|max:200',
        ]);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        $payload = $this->withOwnerName($payload);
        return $this->amountSafeJson($request, $this->cases->update($id, $payload, $this->actor($user)));
    }

    public function accept(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->cases->accept($id, $this->actor($user)));
    }

    public function cancel(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $payload = $request->validate(['cancel_reason' => 'required|string|max:255']);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->cases->cancel($id, $payload, $this->actor($user)));
    }

    public function close(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.close');
        $payload = $request->validate([
            'result' => 'required|string|max:60', 'responsibility' => 'required|string|max:30',
            'close_remark' => 'required|string|max:2000', 'customer_confirmed' => 'nullable|integer|in:0,1',
        ]);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->cases->close($id, $payload, $this->actor($user)));
    }

    public function storeAction(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $payload = $request->validate([
            'action_type' => 'required|string|in:refund,exchange,record,reship,compensation',
            'return_method' => 'nullable|string|max:20', 'freight_payer' => 'nullable|string|max:20',
            'freight_amount' => 'nullable|numeric|min:0', 'planned_disposition' => 'nullable|string|in:defective,restock,scrap',
            'record_content' => 'nullable|string|max:2000', 'remark' => 'nullable|string|max:2000',
            'reship_timing' => 'nullable|string|in:return_first,ship_first', 'ship_first_reason' => 'nullable|string|max:255',
            'compensation_amount' => 'nullable|numeric', 'compensation_method' => 'nullable|string|in:cash,wechat,alipay,bank_transfer,other',
            'compensated_at' => 'nullable|date', 'compensation_payee' => 'nullable|string|max:60',
            'items' => 'nullable|array', 'items.*.case_item_id' => 'required|integer',
            'items.*.return_qty' => 'required|numeric|min:0',
            'reship_items' => 'nullable|array', 'reship_items.*.item_id' => 'required|integer',
            'reship_items.*.qty' => 'required|numeric|min:0', 'reship_items.*.case_item_id' => 'nullable|integer',
        ]);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->actions->add($id, $payload, $this->actor($user)), 201);
    }

    /** 补发出库库存选项：该物料的可出库余额分页；balance_id 给出时返回其余额下可用序列号（分页）。 */
    public function reshipStockOptions(Request $request, int $reshipId)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $reship = \App\Models\Erp\AftersalesReship::query()->findOrFail($reshipId);
        $this->assertOrderVisible($request, (int) $reship->sales_order_id);
        $payload = $request->validate([
            'item_id' => 'required|integer', 'balance_id' => 'nullable|integer',
            'keyword' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'serial_page' => 'nullable|integer|min:1',
            'ids' => 'nullable|array|max:1000', 'ids.*' => 'integer|min:1',
        ]);
        abort_unless($reship->items()->where('item_id', $payload['item_id'])->exists(), 422, '该物料不属于本补发单。');
        $availability = app(InventoryAvailabilityService::class);
        $balancesQuery = $availability->outboundBalanceQuery([(int) $payload['item_id']]);
        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $data = [];
        if (! empty($payload['balance_id'])) {
            $balance = (clone $balancesQuery)->where('erp_inventory_balances.id', $payload['balance_id'])->firstOrFail();
            abort_unless($availability->availableForOutbound($balance) > 0, 422, '该库存来源当前不可出库。');
            $serialsQuery = \App\Models\Erp\InventorySerial::query()
                ->where('inventory_balance_id', (int) $payload['balance_id'])->where('serial_status', 'available')
                ->where('item_id', (int) $payload['item_id'])
                ->when($keyword !== '', fn ($q) => $q->where(fn ($s) => $s->where('serial_no', 'like', "%{$keyword}%")->orWhere('batch_no', 'like', "%{$keyword}%")))
                ->orderBy('serial_no');
            $data['serials'] = ! empty($payload['ids'])
                ? ['data' => $serialsQuery->whereIn('id', $payload['ids'])->get(['id', 'serial_no', 'batch_no'])]
                : $serialsQuery->paginate($this->perPage($request), ['id', 'serial_no', 'batch_no'], 'serial_page');
            $data['serial_mode'] = $balance->item?->serialTrackingMode() ?? 'none';
        } else {
            $balancesQuery->with(['warehouse:id,warehouse_name', 'location:id,location_name'])
                ->when($keyword !== '', fn ($q) => $q->where(fn ($b) => $b->where('erp_inventory_balances.batch_no', 'like', "%{$keyword}%")
                    ->orWhere('erp_warehouses.warehouse_name', 'like', "%{$keyword}%")->orWhere('erp_locations.location_name', 'like', "%{$keyword}%")))
                ->orderBy('erp_inventory_balances.id');
            $selected = ! empty($payload['ids']);
            $balances = $selected ? $balancesQuery->whereIn('erp_inventory_balances.id', $payload['ids'])->get() : $balancesQuery->paginate($this->perPage($request));
            $rows = $selected ? $balances : $balances->getCollection();
            $rows->transform(function ($b) use ($availability) {
                return ['id' => (int) $b->id, 'warehouse_name' => $b->warehouse?->warehouse_name, 'location_name' => $b->location?->location_name,
                    'batch_no' => $b->batch_no, 'available' => $availability->availableForOutbound($b),
                    'serial_mode' => $b->item?->serialTrackingMode() ?? 'none'];
            });
            $data['balances'] = $selected ? ['data' => $rows] : $balances;
        }
        return response()->json(['data' => $data]);
    }

    /** 补发出库：承运/运单/收货信息 + 逐行库存来源与序列号；过账后不可取消。 */
    public function shipReship(Request $request, int $reshipId)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $reship = \App\Models\Erp\AftersalesReship::query()->findOrFail($reshipId);
        $this->assertOrderVisible($request, (int) $reship->sales_order_id);
        $payload = $request->validate([
            'carrier_id' => 'nullable|integer', 'carrier_name' => 'nullable|string|max:120',
            'tracking_no' => 'nullable|string|max:80', 'shipped_at' => 'nullable|date',
            'receiver_name' => 'nullable|string|max:80', 'receiver_phone' => 'nullable|string|max:40',
            'receiver_address' => 'nullable|string|max:300',
            'package_count' => 'nullable|integer|min:0', 'package_method' => 'nullable|string|max:40',
            'remark' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.reship_item_id' => 'required|integer',
            'items.*.inventory_balance_id' => 'required|integer', 'items.*.serial_ids' => 'nullable|array',
        ]);
        $reship = app(AftersalesReshipApplicationService::class)->ship($reshipId, $payload, $this->actor($user));
        return $this->amountSafeJson($request, $reship->fresh('items'));
    }

    /** 所有详情及写入响应共用裁剪，销售金额和库存金额各按自己的权限判断。 */
    private function amountSafeJson(Request $request, \Illuminate\Contracts\Support\Arrayable $record, int $status = 200): \Illuminate\Http\JsonResponse
    {
        $data = $record->toArray();
        $this->stripAmounts($data, $request);
        return response()->json(['data' => $data], $status);
    }

    public function cancelAction(Request $request, int $id, int $actionId)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $payload = $request->validate(['cancel_reason' => 'required|string|max:255']);
        $action = AftersalesAction::query()->where('case_id', $id)->findOrFail($actionId);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->actions->cancel((int) $action->id, $payload, $this->actor($user)));
    }

    public function storeClaim(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $payload = $request->validate([
            'target_type' => 'required|string|in:carrier,supplier,other',
            'carrier_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'target_name' => 'nullable|string|max:120',
            'related_doc_type' => 'nullable|string|max:40', 'related_doc_id' => 'nullable|integer', 'related_doc_no' => 'nullable|string|max:60',
            'claim_amount' => 'required|numeric|min:0', 'claimed_on' => 'required|date', 'reason' => 'required|string|max:2000',
            'attachment_draft_token' => 'nullable|string|max:120', 'status' => 'nullable|string|max:20',
            'paid_amount' => 'nullable|numeric|min:0', 'paid_on' => 'nullable|date',
        ]);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->claims->create($id, $payload, $this->actor($user)), 201);
    }

    public function updateClaim(Request $request, int $id, int $claimId)
    {
        $user = $this->authorizePermission($request, 'aftersales.handle');
        $payload = $request->validate([
            'target_type' => 'nullable|string|in:carrier,supplier,other',
            'carrier_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'target_name' => 'nullable|string|max:120',
            'claim_amount' => 'nullable|numeric|min:0', 'reason' => 'nullable|string|max:2000',
            'status' => 'nullable|string|in:submitted,paid,partially_paid,rejected,abandoned',
            'paid_amount' => 'nullable|numeric|min:0', 'paid_on' => 'nullable|date',
        ]);
        AftersalesClaim::query()->where('case_id', $id)->findOrFail($claimId);
        $this->assertOrderVisible($request, $this->caseOrderId($id));
        return $this->amountSafeJson($request, $this->claims->update($claimId, $payload, $this->actor($user)));
    }

    public function uploadAttachment(Request $request)
    {
        $user = $this->authorizePermission($request, 'aftersales.create');
        $payload = $request->validate([
            'file' => 'required|file|max:51200',
            'case_id' => 'nullable|integer|exists:erp_aftersales_cases,id',
            'claim_id' => 'nullable|integer|exists:erp_aftersales_claims,id',
            'draft_token' => 'nullable|string|max:120',
        ]);
        $claim = ! empty($payload['claim_id']) ? AftersalesClaim::query()->with('case')->findOrFail($payload['claim_id']) : null;
        if ($claim) {
            abort_if(! empty($payload['case_id']) && (int) $payload['case_id'] !== (int) $claim->case_id, 422, '索赔与售后单不一致。');
            $payload['case_id'] = (int) $claim->case_id;
        }
        if (! empty($payload['case_id'])) {
            $case = $claim?->case ?? AftersalesCase::query()->findOrFail($payload['case_id']);
            $this->assertOrderVisible($request, (int) $case->sales_order_id);
            abort_if($claim && in_array($claim->status, ['paid', 'rejected', 'abandoned'], true), 422, '索赔已终结，不能再上传凭证。');
            $claimDraft = ! empty($payload['draft_token']);
            if ($case->status === 'cancelled' || (! in_array($case->status, ['pending', 'processing'], true) && ! $claim && ! $claimDraft)) {
                return response()->json(['message' => '售后单已关闭或取消，不能再上传附件'], 422);
            }
        }
        if (empty($payload['case_id']) && empty($payload['claim_id']) && empty($payload['draft_token'])) {
            return response()->json(['message' => '附件必须关联售后单、索赔或草稿会话'], 422);
        }
        $attachment = app(AftersalesAttachmentService::class)->upload(
            $request->file('file'),
            ['case_id' => $payload['case_id'] ?? null, 'claim_id' => $payload['claim_id'] ?? null,
                'draft_token' => $claim ? null : ($payload['draft_token'] ?? null),
                'attachment_scope' => $claim || (! empty($payload['case_id']) && ! empty($payload['draft_token'])) ? 'claim' : 'case'],
            $this->actor($user)
        );
        return response()->json(['data' => $attachment], 201);
    }

    public function previewAttachment(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.view');
        $attachment = AftersalesAttachment::query()->where('status', 'active')->findOrFail($id);
        $this->assertAttachmentVisible($request, $attachment, $user);
        return \Illuminate\Support\Facades\Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name);
    }

    public function downloadAttachment(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.view');
        $attachment = AftersalesAttachment::query()->where('status', 'active')->findOrFail($id);
        $this->assertAttachmentVisible($request, $attachment, $user);
        return \Illuminate\Support\Facades\Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name);
    }

    public function deleteAttachment(Request $request, int $id)
    {
        $user = $this->authorizePermission($request, 'aftersales.create');
        $attachment = AftersalesAttachment::query()->where('status', 'active')->findOrFail($id);
        $case = $this->assertAttachmentVisible($request, $attachment, $user);
        if ($attachment->claim_id) {
            $claim = $attachment->claim;
            if (in_array($claim->status, ['paid', 'rejected', 'abandoned'], true)) {
                return response()->json(['message' => '索赔已终结，不能删除凭证'], 422);
            }
        } elseif ($case && ! in_array($case->status, ['pending', 'processing'], true)
            && ! ($attachment->attachment_scope === 'claim' && $attachment->draft_token && $case->status !== 'cancelled')) {
            return response()->json(['message' => '售后单已关闭或取消，不能删除附件'], 422);
        }
        app(AftersalesAttachmentService::class)->softDelete($attachment);
        return response()->json(['message' => '附件已删除']);
    }

    /** 老附件可能只有 claim_id；先解析真实所属单，再判断订单范围或本人未归并草稿。 */
    private function assertAttachmentVisible(Request $request, AftersalesAttachment $attachment, object $user): ?AftersalesCase
    {
        $case = null;
        if ($attachment->claim_id) {
            $claim = $attachment->claim()->with('case')->firstOrFail();
            abort_if($attachment->case_id && (int) $attachment->case_id !== (int) $claim->case_id, 422, '附件索赔与售后单不一致。');
            $case = $claim->case;
        } elseif ($attachment->case_id) {
            $case = $attachment->case()->firstOrFail();
        }
        if (! $case || (! $attachment->claim_id && $attachment->attachment_scope === 'claim' && $attachment->draft_token)) {
            abort_unless($attachment->draft_token && (int) $attachment->uploaded_by_legacy_id === (int) $user->legacy_id, 403, '无权操作他人的草稿附件。');
        }
        if ($case) $this->assertOrderVisible($request, (int) $case->sales_order_id);
        return $case;
    }

    private function listRow(AftersalesCase $case, Request $request): array
    {
        $row = [
            'id' => (int) $case->id, 'case_no' => $case->case_no, 'sales_order_id' => (int) $case->sales_order_id,
            'sales_order_no' => $case->order?->sales_order_no, 'customer_name' => $case->customer_snapshot['name'] ?? $case->order?->customer_name,
            'customer_phone' => $case->customer_snapshot['phone'] ?? $case->order?->customer_phone,
            'issue_type' => $case->issue_type, 'description' => $case->description, 'warranty_status' => $case->warranty_status,
            'status' => $case->status, 'result' => $case->result, 'owner_name' => $case->owner_name,
            'created_at' => $case->created_at, 'item_count' => $case->items->count(),
            'items_summary' => $case->items->map(fn ($i) => ($i->product_name ?: $i->item_name).($i->sku_name ? ' / '.$i->sku_name : '').' × '.(float) $i->qty)->unique()->implode('，'),
            'action_types' => $case->actions->where('status', '!=', 'cancelled')->pluck('action_type')->unique()->values()->all(),
            'actions_progress' => $case->actions->where('status', '!=', 'cancelled')->map(fn ($a) => [
                'type' => $a->action_type, 'status' => $a->status, 'return_status' => $a->salesReturn?->return_status,
            ])->values()->all(),
        ];
        $this->stripAmounts($row, $request);
        return $row;
    }

    /** 无销售金额权限只看数量、单号与进度。 */
    private function stripAmounts(array &$data, Request $request): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $auth->isSuperAdmin($user) && array_intersect(['sales_order.amount.view', 'finance.view'], $auth->permissionCodes($user)) === []) {
            $this->stripSalesAmounts($data);
        }
        $data = app(\App\Services\Erp\InventoryAmountVisibilityService::class)->forResponse($data, $request);
    }

    private function stripSalesAmounts(array &$data): void
    {
        unset($data['freight_amount'], $data['compensation_amount'], $data['claim_amount'], $data['paid_amount']);
        // 仅改返回副本，原始审计日志继续保留完整金额。
        if (($data['event'] ?? null) === 'claim_add') $data['content'] = '登记索赔';
        if (($data['event'] ?? null) === 'action_add' && str_starts_with((string) ($data['content'] ?? ''), '赔付记录：')) {
            $data['content'] = preg_replace('/^赔付记录：¥[^（]+/u', '赔付记录：', $data['content']);
        }
        foreach ($data as &$value) {
            if (is_array($value)) $this->stripSalesAmounts($value);
        }
        unset($value);
    }

    private function visibleCases(Request $request): Builder
    {
        $query = AftersalesCase::query();
        $query->whereHas('order', fn (Builder $orders) => $this->applySalesOrderVisibility($orders, $request, true));
        return $query;
    }

    /** 负责人姓名以账号档案为准，不信任前端传入的显示名。 */
    private function withOwnerName(array $payload): array
    {
        unset($payload['owner_name']);
        if (! empty($payload['owner_legacy_id'])) {
            $owner = DB::table('erp_legacy_admin_users')->where('legacy_id', (int) $payload['owner_legacy_id'])->first(['nickname', 'username']);
            $payload['owner_name'] = (string) ($owner->nickname ?: $owner->username);
        }
        return $payload;
    }

    private function caseOrderId(int $caseId): int
    {
        return (int) AftersalesCase::query()->whereKey($caseId)->value('sales_order_id');
    }

    private function assertOrderVisible(Request $request, int $orderId): void
    {
        $orderQuery = SalesOrder::query()->whereKey($orderId);
        $this->applySalesOrderVisibility($orderQuery, $request);
        abort_unless($orderQuery->exists(), 403, '无权操作该订单的售后单。');
    }

    private function applySalesOrderVisibility(Builder $query, Request $request, bool $includeShared = false): void
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        app(\App\Services\Erp\SalesOrderVisibilityService::class)->apply($query, $user, $includeShared);
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permission);
        return $user;
    }

    private function actor(object $user): object
    {
        return (object) ['legacy_id' => (int) ($user->legacy_id ?? 0), 'name' => (string) ($user->nickname ?: ($user->username ?? '系统'))];
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 10)));
    }
}
