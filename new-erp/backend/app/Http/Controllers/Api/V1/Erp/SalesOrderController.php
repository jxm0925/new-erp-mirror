<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderChange;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesOrderLog;
use App\Models\Erp\SalesOrderProductionRequirement;
use App\Models\Erp\SalesOrderVersion;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Models\Erp\SalesChannel;
use App\Models\Erp\SalesFundingPolicy;
use App\Models\Erp\Sku;
use App\Models\Erp\SkuItemRelation;
use App\Models\Erp\Unit;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\BomMatcher;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryReservationService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\SalesOrderDraftService;
use App\Services\Erp\SalesOrderAttachmentService;
use App\Services\Erp\SalesOrderFundingGateService;
use App\Services\Erp\SalesOrderEditImpactService;
use App\Services\Erp\SalesOrderInventoryLockService;
use App\Services\Erp\SkuItemMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    private const ORDER_STATUSES = ['draft', 'confirmed', 'closed', 'cancelled'];
    private const FULFILLMENT_TYPES = ['pending', 'inventory', 'production', 'mixed', 'service', 'no_delivery'];

    public function storeDraft(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales_order.create');
        $payload = $this->attachCurrentOperator($this->validateOrder($request), $request);
        $order = app(SalesOrderDraftService::class)->create($payload, $this->operatorName($request));

        return response()->json(['message' => '销售订单草稿已保存', 'data' => $order], 201);
    }

    public function updateDraft(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.edit_draft');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $payload = $this->attachCurrentOperator($this->validateOrder($request, true), $request);
        $order = app(SalesOrderDraftService::class)->update($order, $payload, $this->operatorName($request));

        return response()->json(['message' => '销售订单草稿已更新', 'data' => $order]);
    }

    /** Real server-side Before/After analysis; it never writes a confirmed order. */
    public function previewEditImpact(Request $request, int $id, SalesOrderEditImpactService $service)
    {
        $this->abortUnlessAnyPermission($request, ['sales_order.change', 'sales_order.change.submit']);
        $order = SalesOrder::with(['lines', 'shipments', 'salesReturns'])->findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $payload = $this->attachCurrentOperator($this->validateOrder($request, true), $request);
        return response()->json(['data' => $service->preview($order, $payload)]);
    }

    /** Submit the exact payload that was previewed. The service recalculates it under a DB lock. */
    public function submitEditImpact(Request $request, int $id, SalesOrderEditImpactService $service)
    {
        $this->abortUnlessAnyPermission($request, ['sales_order.change', 'sales_order.change.submit']);
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $payload = $this->attachCurrentOperator($this->validateOrder($request, true), $request);
        $payload['change_reason'] = $request->validate(['change_reason' => 'nullable|string|max:500'])['change_reason'] ?? null;
        $result = $service->submit($id, $payload, $this->operatorName($request));
        return response()->json(['message' => $result['mode'] === 'candidate' ? '订单修改已提交审核，正式订单暂未变更。' : '订单修改已立即生效并完整留痕。', 'data' => $result]);
    }

    public function candidateHistory(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);
        return response()->json(SalesOrderChangeCandidate::query()->with('approvals')
            ->where('sales_order_id', $order->id)->latest('id')->paginate($perPage));
    }

    public function decideCandidate(Request $request, int $id, int $candidateId, SalesOrderEditImpactService $service)
    {
        $candidate = SalesOrderChangeCandidate::findOrFail($candidateId);
        abort_if($candidate->sales_order_id !== $id, 404);
        $this->abortUnlessOrderVisible($request, SalesOrder::findOrFail($id));
        $payload = $request->validate([
            'approval_type' => ['required', Rule::in(['business', 'finance', 'fulfillment'])],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comment' => 'nullable|string|max:500',
        ]);
        $permission = 'sales_order.change.approve_'.$payload['approval_type'];
        // Approval authority is deliberately separated from the salesperson's
        // edit/submit permission.  A generic change permission must never
        // bypass business, finance or fulfillment approval.
        $this->abortUnlessPermission($request, $permission);
        $result = $service->decide($candidateId, $payload['approval_type'], $payload['decision'] === 'approve', $this->operatorName($request), $payload['comment'] ?? null);
        return response()->json(['message' => '审核处理完成。', 'data' => $result]);
    }

    /** Paged direct SKU search for sales-order add/edit; never fetches all SKU to the browser. */
    public function searchOrderSkus(Request $request, InventoryAvailabilityService $availabilityService)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:160',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);
        $query = Sku::query()->with(['product', 'salesUnit', 'itemRelations.item.unit'])
            ->where('status', 'enabled')->where('is_sale_item', true)
            ->whereNotNull('sales_unit_id')->whereNotNull('sale_price')
            ->whereHas('product', fn (Builder $q) => $q->where('status', 'enabled'));
        foreach (preg_split('/\\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $query->where(function (Builder $q) use ($token) {
                $like = '%'.$token.'%';
                $compact = '%'.preg_replace('/[\\s\\-_\/]+/u', '', mb_strtolower($token)).'%';
                $q->where('sku_code', 'like', $like)
                    ->orWhere('sku_name', 'like', $like)
                    ->orWhere('spec_text', 'like', $like)
                    ->orWhere('search_aliases', 'like', $like)
                    ->orWhere('search_keywords', 'like', $like)
                    ->orWhere('remark', 'like', $like)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CONCAT_WS('', sku_code, sku_name, spec_text, search_aliases, search_keywords, remark)), ' ', ''), '-', ''), '_', ''), '/', '') LIKE ?", [$compact])
                    ->orWhereRaw("EXISTS (SELECT 1 FROM erp_products p WHERE p.id = erp_skus.product_id AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CONCAT_WS('', p.product_code, p.product_name, p.model, p.search_aliases, p.search_keywords, erp_skus.sku_code, erp_skus.sku_name, erp_skus.spec_text, erp_skus.search_aliases, erp_skus.search_keywords)), ' ', ''), '-', ''), '_', ''), '/', '') LIKE ?)", [$compact])
                    ->orWhereHas('product', function (Builder $product) use ($like, $compact): void {
                        $product->where('product_code', 'like', $like)
                            ->orWhere('product_name', 'like', $like)
                            ->orWhere('model', 'like', $like)
                            ->orWhere('search_aliases', 'like', $like)
                            ->orWhere('search_keywords', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('remark', 'like', $like)
                            ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CONCAT_WS('', product_code, product_name, model, search_aliases, search_keywords, description, remark)), ' ', ''), '-', ''), '_', ''), '/', '') LIKE ?", [$compact]);
                    });
            });
        }
        $page = $query->orderBy('sku_code')->paginate($perPage);
        $itemIds = $page->getCollection()->flatMap(fn (Sku $sku) => $sku->itemRelations->pluck('item_id'))->filter()->unique();
        $availability = $availabilityService->availableBaseQuantities($itemIds->all());
        $page->getCollection()->transform(function (Sku $sku) use ($availability) {
            $relation = $sku->itemRelations->firstWhere('is_primary', true) ?: $sku->itemRelations->first();
            $factor = max(0.00000001, (float) ($relation?->qty ?: 1));
            $availableBase = $relation?->item_id ? (float) ($availability[$relation->item_id] ?? 0) : null;
            $sku->setAttribute('available_stock', $availableBase === null ? null : floor(($availableBase / $factor) * 100000000) / 100000000);
            $sku->setAttribute('default_price', $sku->sale_price === null ? null : (float) $sku->sale_price);
            $sku->setAttribute('default_tax_rate', (float) ($sku->default_tax_rate ?? 0));
            $sku->setAttribute('default_price_tax_mode', $sku->default_price_tax_mode ?: 'tax_inclusive');
            $sku->setAttribute('default_fulfillment_method', in_array($sku->line_type, ['service', 'no_delivery'], true) ? $sku->line_type : 'auto');
            return $sku;
        });
        return response()->json($page);
    }

    public function destroyDraft(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.delete_draft');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        app(SalesOrderDraftService::class)->delete($order, $this->operatorName($request));

        return response()->json(['message' => '销售订单草稿已删除']);
    }

    public function submitDraftConfirmation(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.submit_confirmation');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $service = app(SalesOrderDraftService::class);
        $result = $service->precheckConfirmation($order);
        if ($result['passed']) {
            $result['order'] = $service->submitForConfirmation($order, $this->operatorName($request));
            $result['message'] = '确认前检查已通过，订单已提交正式确认。';
        }

        return response()->json([
            'message' => $result['message'],
            'data' => $result,
        ]);
    }

    public function uploadDraftAttachment(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales_order.upload_attachment');
        $payload = $request->validate([
            'file' => 'required|file|max:51200',
            'attachment_scope' => ['required', Rule::in(['order', 'line'])],
            'attachment_type' => ['required', Rule::in([
                'contract', 'public_attachment', 'customer_agreement', 'design_drawing',
                'customer_drawing', 'technical_agreement', 'configuration_note', 'other',
            ])],
            'sales_order_id' => 'nullable|integer|exists:erp_sales_orders,id',
            'sales_order_line_id' => 'nullable|integer|exists:erp_sales_order_lines,id',
            'draft_token' => 'nullable|string|max:120',
            'line_uuid' => 'nullable|string|max:80',
            'replaced_attachment_id' => 'nullable|integer|exists:erp_sales_order_attachments,id',
        ]);
        abort_if(
            $payload['attachment_scope'] === 'line'
            && empty($payload['sales_order_line_id'])
            && (empty($payload['draft_token']) || empty($payload['line_uuid'])),
            422,
            '订单行附件必须关联订单行或草稿行'
        );

        if (!empty($payload['sales_order_id'])) {
            $order = SalesOrder::with('lines')->findOrFail($payload['sales_order_id']);
            $this->abortUnlessOrderVisible($request, $order);
            abort_if($order->order_status !== 'draft', 422, '只有草稿订单允许直接上传附件');
            if (!empty($payload['sales_order_line_id'])) {
                abort_unless($order->lines->contains('id', (int) $payload['sales_order_line_id']), 422, '订单行不属于当前订单');
            }
        }

        $file = $payload['file'];
        $this->assertAttachmentFileAllowed($file, $payload['attachment_type']);
        unset($payload['file']);
        $attachment = app(SalesOrderAttachmentService::class)->upload(
            $file,
            $payload,
            app(AuthContextService::class)->currentUser($request)
        );

        return response()->json([
            'message' => '附件已上传',
            'data' => $this->attachmentPayload($request, $attachment),
        ], 201);
    }

    public function deleteDraftAttachment(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.delete_attachment');
        $attachment = SalesOrderAttachment::where('status', 'active')->findOrFail($id);
        if ($attachment->sales_order_id) {
            $order = SalesOrder::findOrFail($attachment->sales_order_id);
            $this->abortUnlessOrderVisible($request, $order);
            abort_if($order->order_status !== 'draft', 422, '只有草稿订单允许删除附件');
        } else {
            $user = app(AuthContextService::class)->currentUser($request);
            abort_unless(
                $user && (
                    (int) $attachment->uploaded_by_legacy_id === (int) $user->legacy_id
                    || app(AuthContextService::class)->isSuperAdmin($user)
                ),
                403,
                '无权删除该临时附件'
            );
        }
        app(SalesOrderAttachmentService::class)->softDelete($attachment, $this->operatorName($request));

        return response()->json(['message' => '附件已移除', 'data' => ['id' => $attachment->id]]);
    }

    public function previewAttachment(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view_attachment');
        $attachment = $this->visibleAttachment($request, $id);
        abort_unless(in_array(strtolower((string) $attachment->mime_type), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 422, '该文件类型不支持在线预览');
        return Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
        ]);
    }

    public function downloadAttachment(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view_attachment');
        $attachment = $this->visibleAttachment($request, $id);
        return Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name);
    }

    private function visibleAttachment(Request $request, int $id): SalesOrderAttachment
    {
        $attachment = SalesOrderAttachment::where('status', 'active')->findOrFail($id);
        $orderId = $attachment->sales_order_id ?: optional($attachment->line)->sales_order_id;
        if ($orderId) {
            $order = SalesOrder::findOrFail($orderId);
            $this->abortUnlessOrderVisible($request, $order);
        } else {
            $user = app(AuthContextService::class)->currentUser($request);
            abort_unless(
                $user && (
                    (int) $attachment->uploaded_by_legacy_id === (int) $user->legacy_id
                    || app(AuthContextService::class)->isSuperAdmin($user)
                ),
                403,
                '无权访问该临时附件'
            );
        }
        return $attachment;
    }

    public function customers(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $keyword = trim((string) $request->input('keyword'));
        $query = SalesCustomer::query()->where('status', '<>', 'disabled');
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('customer_name', 'like', "%{$keyword}%")
                    ->orWhere('contact_name', 'like', "%{$keyword}%")
                    ->orWhere('contact_phone', 'like', "%{$keyword}%")
                    ->orWhere('full_address', 'like', "%{$keyword}%");
            });
        }

        return response()->json($query->orderByDesc('updated_at')->paginate($this->perPage($request)));
    }

    public function orders(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $query = SalesOrder::with([
            'lines.product', 'lines.sku', 'lines.item',
            'fulfillments' => fn ($fulfillments) => $fulfillments
                ->where('demand_status', 'confirmed')
                ->whereIn('fulfillment_type', ['inventory', 'production', 'service', 'no_delivery']),
        ])
            ->latest('updated_at');
        $this->applySalesOrderVisibility($query, $request);

        $keyword = trim((string) $request->input('keyword'));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('sales_order_no', 'like', "%{$keyword}%")
                    ->orWhere('origin_order_no', 'like', "%{$keyword}%")
                    ->orWhere('customer_name', 'like', "%{$keyword}%")
                    ->orWhereHas('lines', function (Builder $line) use ($keyword) {
                        $line->where('product_name', 'like', "%{$keyword}%")
                            ->orWhere('sku_name', 'like', "%{$keyword}%")
                            ->orWhere('item_name', 'like', "%{$keyword}%");
                    });
            });
        }
        if ($request->filled('order_status')) $query->where('order_status', $request->input('order_status'));
        if ($request->filled('fulfillment_status')) $query->where('fulfillment_status', $request->input('fulfillment_status'));
        if ($request->filled('production_confirm_status')) $query->where('production_confirm_status', $request->input('production_confirm_status'));

        $page = $query->paginate($this->perPage($request));
        $page->getCollection()->transform(function (SalesOrder $order) use ($request) {
            $order->setAttribute('allowed_actions', $this->orderAllowedActions($request, $order));
            $this->decorateFulfillmentComposition($order);
            return $order;
        });
        return response()->json($page);
    }

    public function options(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        return response()->json([
            'pay_types' => DB::table('erp_sales_order_pay_types')
                ->where('enabled', true)
                ->orderBy('sort')
                ->orderBy('legacy_id')
                ->get(['legacy_id as id', 'name', 'trade_type']),
            'platforms' => DB::table('erp_sales_order_trade_platforms')
                ->where('enabled', true)
                ->orderBy('parent_legacy_id')
                ->orderBy('sort')
                ->orderBy('legacy_id')
                ->get(['legacy_id as id', 'parent_legacy_id as pid', 'name', 'short_name', 'trade_type']),
            'carriers' => DB::table('erp_sales_order_deliveries')
                ->where('enabled', true)
                ->orderBy('sort')
                ->orderBy('legacy_id')
                ->get(['legacy_id as id', 'name', 'code', 'trade_type']),
            'share_users' => DB::table('erp_sales_order_share_users')
                ->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'normal');
                })
                ->orderBy('sort')
                ->orderBy('legacy_id')
                ->get(['legacy_id as id', 'username', 'nickname', 'mobile']),
            'sales_channels' => SalesChannel::query()
                ->where('status', 'enabled')
                ->orderBy('sort')->orderBy('id')
                ->get(['id', 'channel_code', 'channel_name', 'channel_type', 'requires_external_order_no', 'default_funding_policy_code']),
            'funding_policies' => SalesFundingPolicy::query()
                ->where('status', 'enabled')
                ->orderBy('id')
                ->get(['id', 'policy_code', 'policy_name', 'policy_type', 'production_threshold_type', 'production_threshold_value']),
        ]);
    }

    public function store(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales.order.create');
        $payload = $this->validateOrder($request);
        $payload = $this->attachCurrentOperator($payload, $request);

        return DB::transaction(function () use ($payload) {
            $lines = $payload['lines'];
            $draftToken = $payload['draft_token'] ?? null;
            unset($payload['lines']);
            unset($payload['draft_token'], $payload['deleted_line_ids']);
            $payload = $this->normalizeOrderPayload($payload);
            $payload['sales_order_no'] = $payload['sales_order_no'] ?? $this->nextNo('SO');
            $payload['order_status'] = 'draft';
            $payload['confirm_status'] = 'unconfirmed';
            $payload['fulfillment_status'] = 'pending';
            $payload['production_confirm_status'] = 'not_required';
            $payload['shipment_status'] = 'not_shipped';

            $order = SalesOrder::create($payload);
            $this->saveLines($order, $lines, $draftToken);
            $this->bindDraftOrderAttachments($order, $draftToken);
            $this->refreshOrderTotals($order->id);
            $this->version($order->id, 'create', null, $order->fresh('lines')->toArray(), $payload['created_by'] ?? 'system');
            $this->log($order->id, null, 'create', null, 'draft', '新增销售订单');

            return response()->json([
                'message' => '销售订单已保存',
                'data' => $order->fresh(['lines.product', 'lines.sku', 'lines.item']),
            ], 201);
        });
    }

    public function show(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $query = SalesOrder::with([
            'lines.product',
            'lines.sku',
            'lines.item',
            'lines.attachments' => fn ($query) => $query->where('status', 'active')->latest('id'),
            'attachments' => fn ($query) => $query->where('status', 'active')->where('attachment_scope', 'order')->latest('id'),
            'fulfillments.item',
            'fulfillments.warehouse',
            'fulfillments.location',
            'productionRequirements.product',
            'productionRequirements.sku',
            'productionRequirements.item',
            'versions',
            'logs',
            'changeCandidates.approvals',
            'shipments.lines',
            'shipments.packages',
            'salesReturns',
        ]);
        $this->applySalesOrderVisibility($query, $request);
        $order = $query->findOrFail($id);
        $order->lines->each(function ($line): void {
            $line->setAttribute('system_item', $this->orderLineItemProjection($line));
        });
        $this->decorateFulfillmentComposition($order);
        $changeEligibility = app(SalesOrderEditImpactService::class)->eligibility($order);
        $allowedActions = $this->orderAllowedActions($request, $order, $changeEligibility);
        $order->setAttribute('allowed_actions', $allowedActions);
        $order->setAttribute('change_eligibility', $changeEligibility);
        $order->setAttribute('pending_change_candidate', $order->changeCandidates->firstWhere('candidate_status', 'PENDING_APPROVAL'));
        $candidateIds = $order->changeCandidates->pluck('id');
        $approvalTasks = $candidateIds->isEmpty() ? collect() : ApprovalTask::query()
            ->with('nodes')
            ->where('business_type', 'SALES_ORDER_CHANGE')
            ->whereIn('business_id', $candidateIds)
            ->latest('id')->get()->unique('business_id')->keyBy('business_id');
        $order->changeCandidates->each(function (SalesOrderChangeCandidate $candidate) use ($approvalTasks): void {
            $candidate->setAttribute('approval_task', $approvalTasks->get($candidate->id));
        });
        $approvalTask = $approvalTasks->first();
        $order->setAttribute('approval_task', $approvalTask);
        $order->attachments->each(fn (SalesOrderAttachment $attachment) => $this->decorateAttachment($attachment, $allowedActions));
        $order->lines->each(function ($line) use ($allowedActions): void {
            $line->attachments->each(fn (SalesOrderAttachment $attachment) => $this->decorateAttachment($attachment, $allowedActions));
        });
        // 第五阶段不创建工单；生产阶段接入真实工单表后只替换本投影来源。
        $order->setAttribute('work_order_tracking', $this->workOrderTrackingProjection($order));
        $order->setAttribute('funding_status', app(SalesOrderFundingGateService::class)->status($order));
        $order->setAttribute('fulfillment_quantities', app(SalesOrderInventoryLockService::class)->projection($order));
        return response()->json($order);
    }

    public function update(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales.order.edit');
        $order = SalesOrder::with('lines')->findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        abort_if($order->order_status !== 'draft', 422, '已确认订单禁止普通编辑，请走订单变更或正式撤回确认流程');
        abort_if($order->shipment_status !== 'not_shipped', 422, '已有发货记录的订单不能直接编辑，请走订单变更或售后流程');

        $payload = $this->validateOrder($request, true);
        $payload = $this->attachCurrentOperator($payload, $request);

        return DB::transaction(function () use ($order, $payload) {
            $before = $order->fresh(['lines', 'fulfillments', 'productionRequirements'])->toArray();
            $lines = $payload['lines'];
            $deletedLineIds = $payload['deleted_line_ids'] ?? [];
            $draftToken = $payload['draft_token'] ?? null;
            unset($payload['lines']);
            unset($payload['draft_token'], $payload['deleted_line_ids']);
            $payload = $this->normalizeOrderPayload($payload);

            $order->update($payload);
            $this->syncDraftLines($order, $lines, $deletedLineIds, $draftToken);
            $this->bindDraftOrderAttachments($order, $draftToken);
            $this->refreshOrderTotals($order->id);
            $order->update([
                'order_status' => 'draft',
                'confirm_status' => 'unconfirmed',
                'fulfillment_status' => 'pending',
                'production_confirm_status' => 'not_required',
            ]);
            $after = $order->fresh(['lines'])->toArray();
            $this->version($order->id, 'update', $before, $after, $payload['created_by'] ?? 'system');
            $this->log($order->id, null, 'update', $before['order_status'] ?? null, $order->order_status, '编辑销售订单，履约结果已撤回待重新确认');

            return response()->json([
                'message' => '销售订单已更新',
                'data' => $order->fresh(['lines.product', 'lines.sku', 'lines.item']),
            ]);
        });
    }

    public function confirm(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.formal_confirm');
        $order = SalesOrder::with(['lines.sku', 'lines.item'])->findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        abort_if($order->confirm_status !== 'pending_confirmation', 422, '请先完成确认前检查');
        abort_if($order->order_status !== 'draft', 422, '只有草稿状态的销售订单可以提交确认');
        abort_if($order->order_status === 'cancelled', 422, '已取消订单不能确认');
        abort_if($order->lines->isEmpty(), 422, '销售订单至少需要一行明细');
        abort_if(blank($order->carrier_id), 422, '提交确认前必须先选择快递！');
        foreach ($order->lines as $line) {
            abort_if((float) $line->unit_price <= 0, 422, '提交确认前，订单行销售单价必须大于 0');
            $this->assertLineOrderAttributes($line);
            abort_if($line->is_special_customized && !$this->hasLineTechnicalAttachment($line), 422, '特殊定制订单行必须上传设计图纸或技术附件后才能提交确认');
        }

        $confirmed = app(\App\Services\Erp\SalesOrderFulfillmentApplicationService::class)
            ->confirmOrder($order->id, $this->operatorName($request));
        return response()->json([
            'message' => '订单已确认并锁定履约换算快照；请进入订单生产确认生成履约需求。',
            'data' => $confirmed,
        ]);
    }

    public function productionConfirmationPreview(Request $request, int $id)
    {
        $this->abortUnlessAnyPermission($request, ['sales_order.view', 'sales_order.production_confirm']);
        $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item', 'fulfillments'])->findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        return response()->json(app(\App\Services\Erp\SalesOrderFulfillmentApplicationService::class)->preview($order->id));
    }

    public function lockInventory(Request $request, int $id, SalesOrderInventoryLockService $service)
    {
        $this->abortUnlessPermission($request, 'sales_order.inventory_lock');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120',
            'expected_version' => 'required|integer|min:1',
        ]);
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        $result = $service->lock($id, $payload, $user, $auth->permissionCodes($user));
        return response()->json([
            'message' => $result['created_fulfillment_count'] > 0
                ? '销售订单库存已正式锁定，待生产数量已重新计算。'
                : '当前没有新增可锁定库存，系统未重复占用。',
            'data' => $result,
        ]);
    }

    public function confirmProduction(Request $request, int $id, \App\Services\Erp\SalesOrderFulfillmentApplicationService $service)
    {
        $this->abortUnlessPermission($request, 'sales_order.production_confirm');
        $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item'])->findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        abort_if($order->order_status !== 'confirmed', 422, '订单确认后才能做生产确认');
        abort_if(!in_array($order->production_confirm_status, ['pending', 'blocked'], true), 422, '当前订单无需或已经完成订单生产确认');

        $payload = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.sales_order_line_id' => 'required|exists:erp_sales_order_lines,id',
            'lines.*.confirm_qty' => 'required|numeric|min:0',
            'lines.*.inventory_qty' => 'required|numeric|min:0',
            'lines.*.production_qty' => 'required|numeric|min:0',
            'lines.*.service_qty' => 'required|numeric|min:0',
            'lines.*.no_delivery_qty' => 'required|numeric|min:0',
            'lines.*.undetermined_qty' => 'prohibited',
            'remark' => 'nullable|string',
            'adjustment_reason' => 'nullable|string|max:500',
        ]);

        $confirmed = $service->confirmProduction(
            $order->id,
            $payload['lines'],
            $payload['remark'] ?? null,
            $this->operatorName($request),
            $payload['adjustment_reason'] ?? null,
        );
        return response()->json([
            'message' => '订单生产确认已提交，库存占用、履约需求和生产需求契约已生成；未创建工单或工序任务。',
            'data' => $confirmed,
        ]);
    }

    public function cancelWithReason(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.cancel');
        $payload = $request->validate(['reason' => 'required|string|max:500']);

        return DB::transaction(function () use ($request, $id, $payload) {
            $order = SalesOrder::with(['lines', 'fulfillments'])->lockForUpdate()->findOrFail($id);
            $this->abortUnlessOrderVisible($request, $order);
            abort_if($order->order_status === 'cancelled', 422, '订单已经取消，不能重复取消');
            abort_if($order->shipment_status !== 'not_shipped' || $order->lines->sum('shipped_qty') > 0, 422, '已有发货记录的订单不能直接取消，请进入售后/退货流程');
            abort_if(SalesOrderProductionRequirement::where('sales_order_id', $order->id)
                ->where('is_active', true)
                ->whereIn('requirement_status', ['ready', 'partially_consumed', 'consumed', 'closed'])
                ->exists(), 422, '生产需求已生效或被消耗，不能直接取消，请走订单变更流程');

            $beforeStatus = $order->order_status;
            $order->update([
                'order_status' => 'cancelled',
                'cancel_reason' => $payload['reason'],
                'cancelled_at' => now(),
                'cancelled_by' => $this->operatorName($request),
            ]);
            app(InventoryReservationService::class)->releaseForSalesOrder($order, '订单取消：'.$payload['reason']);
            SalesOrderProductionRequirement::where('sales_order_id', $order->id)
                ->where('is_active', true)
                ->whereIn('requirement_status', ['draft', 'blocked'])
                ->update(['requirement_status' => 'cancelled', 'is_active' => false, 'closed_qty' => DB::raw('remaining_qty')]);
            $this->log($order->id, null, 'cancel', $beforeStatus, 'cancelled', '取消销售订单：'.$payload['reason']);

            return response()->json(['message' => '订单已取消', 'data' => $order->fresh(['lines', 'fulfillments'])]);
        });
    }

    public function cancel(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.cancel');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        abort_if($order->shipment_status !== 'not_shipped', 422, '已有发货数量的订单不能直接取消，请进入售后/退货流程');

        $order->update([
            'order_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $this->operatorName($request),
        ]);
        app(InventoryReservationService::class)->releaseForSalesOrder($order, '订单取消释放库存占用');
        SalesOrderProductionRequirement::where('sales_order_id', $order->id)
            ->where('is_active', true)
            ->whereIn('requirement_status', ['draft', 'blocked'])
            ->update(['requirement_status' => 'cancelled', 'is_active' => false, 'closed_qty' => DB::raw('remaining_qty')]);
        $this->log($order->id, null, 'cancel', null, 'cancelled', '取消销售订单，历史履约记录保留');

        return response()->json(['message' => '订单已取消', 'data' => $order->fresh(['lines', 'fulfillments'])]);
    }

    public function logs(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        return response()->json(SalesOrderLog::where('sales_order_id', $id)->latest('id')->paginate($this->perPage($request)));
    }

    public function versions(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);
        return response()->json(SalesOrderVersion::where('sales_order_id', $id)->latest('version_no')->paginate($this->perPage($request)));
    }

    public function changes(Request $request, int $id)
    {
        $this->abortUnlessPermission($request, 'sales_order.view');
        $order = SalesOrder::findOrFail($id);
        $this->abortUnlessOrderVisible($request, $order);

        return response()->json(
            SalesOrderChange::query()
                ->where('sales_order_id', $id)
                ->latest('applied_at')
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    public function uploadAttachment(Request $request)
    {
        $this->abortUnlessAnyPermission($request, ['sales.order.create', 'sales.order.edit']);
        $payload = $request->validate([
            'file' => 'required|file|max:51200',
            'attachment_scope' => ['required', Rule::in(['order', 'line'])],
            'attachment_type' => ['required', Rule::in(['contract', 'public_attachment', 'customer_agreement', 'design_drawing', 'customer_drawing', 'technical_agreement', 'configuration_note', 'other'])],
            'sales_order_id' => 'nullable|integer|exists:erp_sales_orders,id',
            'sales_order_line_id' => 'nullable|integer|exists:erp_sales_order_lines,id',
            'draft_token' => 'nullable|string|max:120',
            'line_uuid' => 'nullable|string|max:80',
        ]);
        abort_if($payload['attachment_scope'] === 'line' && empty($payload['sales_order_line_id']) && (empty($payload['draft_token']) || empty($payload['line_uuid'])), 422, '订单行附件必须绑定 sales_order_line_id 或 draft_token + line_uuid');

        if (!empty($payload['sales_order_id'])) {
            $order = SalesOrder::with('lines')->findOrFail($payload['sales_order_id']);
            $this->abortUnlessOrderVisible($request, $order);
            abort_if($order->order_status !== 'draft', 422, '订单确认后不能直接上传附件，请走订单变更流程');
            if (!empty($payload['sales_order_line_id'])) {
                abort_if(!$order->lines->contains('id', (int) $payload['sales_order_line_id']), 422, '订单行不属于当前订单');
            }
        }

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $storedName = \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('erp/sales-order/'.date('Ymd'), $storedName, 'public');
        $user = app(AuthContextService::class)->currentUser($request);
        $attachment = SalesOrderAttachment::create([
            'draft_token' => $payload['draft_token'] ?? null,
            'line_uuid' => $payload['line_uuid'] ?? null,
            'sales_order_id' => $payload['sales_order_id'] ?? null,
            'sales_order_line_id' => $payload['sales_order_line_id'] ?? null,
            'attachment_scope' => $payload['attachment_scope'],
            'attachment_type' => $payload['attachment_type'],
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'url' => preg_match('#^https?://#i', Storage::disk('public')->url($path))
                ? preg_replace('#^https?://[^/]+#i', $request->getSchemeAndHttpHost(), Storage::disk('public')->url($path))
                : rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim(Storage::disk('public')->url($path), '/'),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_hash' => $hash,
            'uploaded_by_legacy_id' => $user->legacy_id ?? null,
            'uploaded_by' => $user ? ($user->nickname ?: $user->username) : null,
            'uploaded_at' => now(),
            'status' => 'active',
        ]);

        return response()->json(['message' => '附件已上传', 'data' => $attachment], 201);
    }

    public function deleteAttachment(Request $request, int $id)
    {
        $this->abortUnlessAnyPermission($request, ['sales.order.create', 'sales.order.edit']);
        $attachment = SalesOrderAttachment::where('status', 'active')->findOrFail($id);

        if ($attachment->sales_order_id) {
            $order = SalesOrder::findOrFail($attachment->sales_order_id);
            $this->abortUnlessOrderVisible($request, $order);
            abort_if($order->order_status !== 'draft', 422, '订单确认后不能直接删除附件，请走订单变更流程');
        }

        $attachment->update(['status' => 'deleted']);
        if ($attachment->storage_disk && $attachment->storage_path) {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
        }

        return response()->json(['message' => '附件已删除', 'data' => ['id' => $attachment->id]]);
    }

    public function interfaceContract(Request $request)
    {
        $this->abortUnlessPermission($request, 'sales.order');
        return response()->json([
            'message' => '销售订单到生产工单的数据契约。通过工单开发前置门禁前，生产模块只能消费该契约，不能自行猜订单配置。',
            'work_order_gate' => [
                'order_id',
                'sales_order_line_id',
                'production_qty',
                'product_id',
                'sku_id',
                'item_id',
                'configuration_snapshot',
                'bom_snapshot',
                'routing_snapshot',
                'drawing_snapshot',
                'technical_attachment_snapshot',
                'inspection_snapshot',
                'required_delivery_date',
                'is_urgent',
                'is_delay',
                'delay_date',
            ],
            'shipment_contract' => [
                'sales_order_id', 'shipment_no', 'carrier_id', 'carrier_name_snapshot', 'tracking_no',
                'actual_freight', 'shipped_at', 'shipment_status', 'receiver_snapshot',
                'shipping_address_snapshot', 'created_by', 'confirmed_by', 'posted_at', 'remark',
                'lines.shipment_id', 'lines.sales_order_line_id', 'lines.product_id', 'lines.sku_id',
                'lines.item_id', 'lines.shipped_qty', 'lines.unit_id', 'lines.unit_name_snapshot',
                'lines.batch_no', 'lines.serial_no', 'lines.inventory_reservation_id', 'lines.inventory_transaction_id',
            ],
            'reservation_lifecycle' => [
                'active', 'partially_released', 'released', 'converted_to_shipment', 'consumed', 'cancelled',
            ],
            'forbidden_before_gate_passed' => [
                '全部库存履约订单生成工单',
                '服务类/无需发货订单行生成制造工单',
                '绕过库存履约数量直接生成工单',
                '绕过 BOM、路线、图纸、交付检验快照生成工单',
            ],
        ]);
    }

    private function validateOrder(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'sales_order_no' => 'nullable|string|max:80',
            'reservation_token' => 'nullable|uuid',
            'creation_session_id' => 'nullable|uuid',
            'origin_order_no' => 'nullable|string|max:120',
            'legacy_order_no' => 'nullable|string|max:120',
            'legacy_order_id' => 'nullable|integer|min:1',
            'trade_type' => 'nullable|string|max:40',
            'order_source' => 'nullable|string|max:60',
            'platform' => 'nullable|string|max:80',
            'platform2' => 'nullable|string|max:80',
            'sales_channel_id' => 'nullable|integer|exists:erp_sales_channels,id',
            'funding_policy_id' => 'nullable|integer|exists:erp_sales_funding_policies,id',
            'transaction_mode' => ['nullable', Rule::in(['cash_sale', 'contract', 'online_prepay'])],
            'external_order_no' => 'nullable|string|max:160',
            'channel_ordered_at' => 'nullable|date',
            'contract_no' => 'nullable|string|max:120',
            'payment_terms_snapshot' => 'nullable|array',
            'platform_buyer_id' => 'nullable|string|max:160',
            'pay_type' => 'nullable|string|max:80',
            'created_by_legacy_id' => 'nullable|integer|min:1',
            'sales_user_legacy_id' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|integer|exists:erp_sales_customers,id',
            'customer_contact_id' => 'nullable|integer|min:1',
            'customer_address_id' => 'nullable|integer|min:1',
            'customer_name' => 'required_without:customer_id|nullable|string|max:160',
            'customer_kind' => ['nullable', Rule::in(['enterprise', 'individual'])],
            'customer_phone' => 'nullable|string|max:40',
            'contact_name' => 'nullable|string|max:80',
            'contact_phone' => 'nullable|string|max:40',
            'country_id' => 'nullable|string|max:40',
            'province_id' => 'nullable|string|max:40',
            'city_id' => 'nullable|string|max:40',
            'area_id' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:500',
            'full_address' => 'nullable|string',
            'order_time' => 'nullable|date',
            'order_date' => 'nullable|date',
            'required_delivery_date' => 'nullable|date',
            'is_urgent' => 'nullable|boolean',
            'quickly' => 'nullable|boolean',
            'is_customized' => 'nullable|boolean',
            'is_special_customized' => 'nullable|boolean',
            'is_delay' => 'nullable|boolean',
            'delay' => 'nullable|boolean',
            'delay_date' => 'nullable|date',
            'need_pump' => 'nullable|boolean',
            'electric' => 'nullable|string|max:80',
            'order_flags' => 'nullable|array',
            'freight_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:20',
            'is_share' => 'nullable|boolean',
            'share_user' => 'nullable',
            'carrier_id' => 'nullable|string|max:80',
            'default_carrier_id' => 'nullable|string|max:80',
            'carrier_fee' => 'nullable|numeric|min:0',
            'salesperson_id' => 'nullable|integer|min:1',
            'sales_department_id' => 'nullable|integer|min:1',
            'logistics_requirement' => 'nullable|string',
            'customer_remark' => 'nullable|string',
            'order_remark' => 'nullable|string',
            'legacy_clue_id' => 'nullable|integer|min:1',
            'legacy_clue_cop_id' => 'nullable|string|max:80',
            'legacy_clue_source' => 'nullable|string|max:120',
            'crm_snapshot' => 'nullable|array',
            'customer_snapshot' => 'nullable|array',
            'shipping_snapshot' => 'nullable|array',
            'logistics_snapshot' => 'nullable|array',
            'legacy_payload' => 'nullable|array',
            'contract_attachments' => 'nullable|string',
            'remark' => 'nullable|string',
            'created_by' => 'nullable|string|max:80',
            'draft_token' => 'nullable|string|max:120',
            'deleted_line_ids' => 'nullable|array',
            'deleted_line_ids.*' => 'integer|min:1',
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'nullable|integer|min:1',
            'lines.*.line_uuid' => 'nullable|string|max:80',
            'lines.*.line_no' => 'nullable|integer|min:1',
            'lines.*.legacy_order_product_id' => 'nullable|integer|min:1',
            'lines.*.legacy_goods_id' => 'nullable|integer|min:1',
            'lines.*.legacy_sku_id' => 'nullable|integer|min:1',
            'lines.*.product_id' => 'required|exists:erp_products,id',
            'lines.*.sku_id' => 'required|exists:erp_skus,id',
            'lines.*.item_id' => 'nullable|exists:erp_items,id',
            'lines.*.product_name' => 'nullable|string|max:160',
            'lines.*.sku_name' => 'nullable|string|max:160',
            'lines.*.item_name' => 'nullable|string|max:160',
            'lines.*.legacy_goods_type' => 'nullable|string|max:30',
            'lines.*.line_type' => ['nullable', Rule::in(['physical', 'service', 'no_delivery', 'fee', 'auxiliary'])],
            'lines.*.order_qty' => 'required|numeric|min:0.0001',
            'lines.*.unit_id' => 'nullable|exists:erp_units,id',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.fulfillment_method' => ['nullable', Rule::in(['auto', 'inventory', 'production', 'service', 'no_delivery'])],
            'lines.*.price_tax_mode' => ['nullable', Rule::in(['tax_inclusive', 'tax_exclusive'])],
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:1',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.need_pump' => 'nullable|boolean',
            'lines.*.electric' => 'nullable|string|max:80',
            'lines.*.is_customized' => 'nullable|boolean',
            'lines.*.is_special_customized' => 'nullable|boolean',
            'lines.*.sort_order' => 'nullable|integer|min:0',
            'lines.*.customization_description' => 'nullable|string|max:2000',
            'lines.*.configuration_snapshot' => 'nullable|array',
            'lines.*.product_snapshot' => 'nullable|array',
            'lines.*.sku_snapshot' => 'nullable|array',
            'lines.*.item_snapshot' => 'nullable|array',
            'lines.*.bom_snapshot' => 'nullable|array',
            'lines.*.routing_snapshot' => 'nullable|array',
            'lines.*.drawing_snapshot' => 'nullable|array',
            'lines.*.technical_attachment_snapshot' => 'nullable|array',
            'lines.*.inspection_snapshot' => 'nullable|array',
            'lines.*.image' => 'nullable|string',
            'lines.*.design' => 'nullable|string',
            'lines.*.remark' => 'nullable|string',
        ]);
    }

    private function normalizeOrderPayload(array $payload): array
    {
        foreach (['is_urgent', 'is_customized', 'is_special_customized', 'is_delay', 'need_pump', 'is_share'] as $field) {
            $payload[$field] = (bool) ($payload[$field] ?? false);
        }
        if (isset($payload['share_user']) && is_array($payload['share_user'])) {
            $payload['share_user'] = implode(',', array_values(array_filter($payload['share_user'], fn ($value) => $value !== null && $value !== '')));
        }
        if (!$payload['is_share']) {
            $payload['share_user'] = null;
        }
        $payload['order_source'] = $payload['order_source'] ?? 'manual';
        $payload['currency'] = $payload['currency'] ?? 'CNY';
        $payload['freight_amount'] = $payload['freight_amount'] ?? 0;
        $payload['carrier_fee'] = $payload['carrier_fee'] ?? 0;
        $shipping = is_array($payload['shipping_snapshot'] ?? null) ? $payload['shipping_snapshot'] : [];
        foreach (['shipment_no', 'tracking_no', 'actual_freight', 'shipped_at', 'shipment_status'] as $field) {
            unset($shipping[$field]);
        }
        $shipping['default_carrier_id'] = $payload['carrier_id'] ?? ($shipping['default_carrier_id'] ?? null);
        $shipping['default_carrier_name'] = $shipping['carrier_name'] ?? ($shipping['default_carrier_name'] ?? null);
        unset($shipping['carrier_name']);
        $payload['shipping_snapshot'] = $shipping;
        $logistics = is_array($payload['logistics_snapshot'] ?? null) ? $payload['logistics_snapshot'] : [];
        foreach (['shipment_no', 'tracking_no', 'express_no', 'actual_freight', 'shipped_at'] as $field) {
            unset($logistics[$field]);
        }
        $payload['logistics_snapshot'] = $logistics;
        $this->resolveCustomerFromOrder($payload);
        $this->lockCustomerSnapshots($payload);
        return $payload;
    }

    private function resolveCustomerFromOrder(array &$payload): void
    {
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        if ($customerName === '') return;

        $kind = in_array($payload['customer_kind'] ?? null, ['enterprise', 'individual'], true)
            ? $payload['customer_kind']
            : 'individual';
        $sourcePlatform = $this->customerSourcePlatform($payload);
        $buyerId = trim((string) ($payload['platform_buyer_id'] ?? '')) ?: null;
        $platformIdentityKey = $this->customerPlatformIdentityKey($sourcePlatform, $buyerId);
        $nameKey = $this->customerDedupeKey($customerName);
        $address = trim((string) ($payload['full_address'] ?? ''));
        $addressKey = $this->customerDedupeKey($address);

        $customer = !empty($payload['customer_id'])
            ? SalesCustomer::findOrFail($payload['customer_id'])
            : $this->findDuplicateCustomer($kind, $platformIdentityKey, $nameKey, $addressKey);
        if ($customer && $kind === 'individual' && $platformIdentityKey) {
            $matchedByBuyerId = SalesCustomer::where('platform_identity_key', $platformIdentityKey)->first();
            if ($matchedByBuyerId) $customer = $matchedByBuyerId;
        }
        abort_if($customer?->status === 'blacklisted', 422, '该客户已在黑名单，不能新增销售订单');

        $contactName = trim((string) ($payload['contact_name'] ?? data_get($payload, 'customer_snapshot.contact_name') ?? ''));
        if ($contactName === '' && $kind === 'individual') $contactName = $customerName;
        $contactPhone = trim((string) ($payload['customer_phone'] ?? $payload['contact_phone'] ?? '')) ?: null;
        $masterData = array_filter([
            'customer_name' => $customerName,
            'customer_kind' => $kind,
            'dedupe_name_key' => $nameKey,
            'dedupe_address_key' => $addressKey,
            'source_platform' => $sourcePlatform,
            'platform_buyer_id' => $buyerId,
            'platform_identity_key' => $platformIdentityKey,
            'contact_name' => $contactName ?: null,
            'contact_phone' => $contactPhone,
            'full_address' => $address ?: null,
            'status' => 'enabled',
        ], fn ($value) => $value !== null && $value !== '');

        if ($customer) {
            $customer->update($masterData);
        } else {
            $customer = SalesCustomer::create([
                'customer_code' => 'CUST-'.now()->format('YmdHis').'-'.random_int(100, 999),
                ...$masterData,
            ]);
        }

        $contact = $this->upsertDefaultOrderContact($customer, $contactName, $contactPhone);
        $customerAddress = $this->upsertDefaultOrderAddress($customer, $address, $contactName ?: $customerName, $contactPhone);
        $payload['customer_id'] = $customer->id;
        $payload['customer_contact_id'] = $contact?->id;
        $payload['customer_address_id'] = $customerAddress?->id;
    }

    private function findDuplicateCustomer(string $kind, ?string $platformIdentityKey, ?string $nameKey, ?string $addressKey): ?SalesCustomer
    {
        if ($kind === 'individual' && $platformIdentityKey) {
            return SalesCustomer::where('platform_identity_key', $platformIdentityKey)->first();
        }
        if ($kind === 'enterprise' && $nameKey) {
            return SalesCustomer::where('dedupe_name_key', $nameKey)->first();
        }
        if ($kind === 'individual' && $nameKey && $addressKey) {
            return SalesCustomer::where('customer_kind', 'individual')
                ->where('dedupe_name_key', $nameKey)->where('dedupe_address_key', $addressKey)->first();
        }
        return null;
    }

    private function upsertDefaultOrderContact(SalesCustomer $customer, string $name, ?string $phone): ?SalesCustomerContact
    {
        if ($name === '' && !$phone) return null;
        $contact = $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $data = array_filter(['contact_name' => $name ?: null, 'mobile' => $phone, 'phone' => $phone, 'is_default' => true, 'status' => 'enabled'], fn ($value) => $value !== null && $value !== '');
        if ($contact) {
            $contact->update($data);
            return $contact->fresh();
        }
        return $customer->contacts()->create($data + ['contact_name' => $name ?: $customer->customer_name, 'is_default' => true, 'status' => 'enabled']);
    }

    private function upsertDefaultOrderAddress(SalesCustomer $customer, string $fullAddress, string $receiverName, ?string $receiverPhone): ?SalesCustomerAddress
    {
        if ($fullAddress === '') return null;
        $address = $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $data = array_filter([
            'receiver_name' => $receiverName ?: null, 'receiver_phone' => $receiverPhone,
            'detail_address' => $fullAddress, 'full_address' => $fullAddress,
            'is_default' => true, 'status' => 'enabled',
        ], fn ($value) => $value !== null && $value !== '');
        if ($address) {
            $address->update($data);
            return $address->fresh();
        }
        return $customer->addresses()->create($data + ['receiver_name' => $receiverName ?: $customer->customer_name, 'detail_address' => $fullAddress, 'full_address' => $fullAddress, 'is_default' => true, 'status' => 'enabled']);
    }

    private function customerSourcePlatform(array $payload): ?string
    {
        $parts = array_filter([trim((string) ($payload['platform'] ?? '')), trim((string) ($payload['platform2'] ?? ''))]);
        return $parts ? implode(':', $parts) : null;
    }

    private function customerDedupeKey(?string $value): ?string
    {
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower(trim((string) $value)));
        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function customerPlatformIdentityKey(?string $sourcePlatform, ?string $buyerId): ?string
    {
        return $sourcePlatform && $buyerId ? hash('sha256', $sourcePlatform."\0".$buyerId) : null;
    }

    private function lockCustomerSnapshots(array &$payload): void
    {
        if (empty($payload['customer_id'])) return;
        $customer = SalesCustomer::findOrFail($payload['customer_id']);
        $contact = !empty($payload['customer_contact_id'])
            ? SalesCustomerContact::where('customer_id', $customer->id)->where('status', 'enabled')->findOrFail($payload['customer_contact_id'])
            : $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $address = !empty($payload['customer_address_id'])
            ? SalesCustomerAddress::where('customer_id', $customer->id)->where('status', 'enabled')->findOrFail($payload['customer_address_id'])
            : $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $payload['customer_contact_id'] = $contact?->id;
        $payload['customer_address_id'] = $address?->id;
        $payload['customer_name'] = $customer->customer_name;
        $payload['customer_name_snapshot'] = $customer->customer_name;
        $payload['contact_name'] = $contact?->contact_name;
        $payload['contact_phone'] = $contact?->mobile ?: $contact?->phone;
        $payload['customer_phone'] = $payload['contact_phone'];
        $payload['contact_name_snapshot'] = $payload['contact_name'];
        $payload['contact_phone_snapshot'] = $payload['contact_phone'];
        $payload['full_address'] = $address?->full_address;
        $payload['shipping_address_snapshot'] = $address ? [
            'id' => $address->id, 'receiver_name' => $address->receiver_name, 'receiver_phone' => $address->receiver_phone,
            'province' => $address->province, 'city' => $address->city, 'district' => $address->district,
            'detail_address' => $address->detail_address, 'full_address' => $address->full_address,
        ] : null;
        $payload['customer_snapshot'] = [
            'id' => $customer->id, 'customer_code' => $customer->customer_code, 'name' => $customer->customer_name,
            'customer_kind' => $customer->customer_kind, 'source_platform' => $customer->source_platform,
            'platform_buyer_id' => $customer->platform_buyer_id,
            'contact' => $contact ? ['id' => $contact->id, 'name' => $payload['contact_name'], 'phone' => $payload['contact_phone']] : null,
            'address' => $payload['shipping_address_snapshot'],
        ];
    }

    private function applySalesOrderVisibility(Builder $query, Request $request): void
    {
        $legacyAdminId = $this->currentLegacyAdminId($request);
        if (!$legacyAdminId) {
            return;
        }

        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyAdminId)->first();
        if (!$user) {
            $query->whereRaw('1 = 0');
            return;
        }

        $auth = app(AuthContextService::class);
        $groupNames = json_decode($user->auth_group_names ?: '[]', true) ?: [];
        $isPrivileged = ($user->username === 'admin')
            || in_array('Admin group', $groupNames, true)
            || in_array('销售负责人', $groupNames, true)
            || $auth->dataScope($user) === 'all';

        if ($isPrivileged) {
            return;
        }
        if ($auth->dataScope($user) === 'department') {
            $query->whereIn('sales_user_legacy_id', $auth->departmentUserIds($user));
            return;
        }

        $query->where(function (Builder $q) use ($legacyAdminId) {
            $q->where('sales_user_legacy_id', $legacyAdminId)
                ->orWhere(function (Builder $fallback) use ($legacyAdminId) {
                    $fallback->whereNull('sales_user_legacy_id')
                        ->where('created_by_legacy_id', $legacyAdminId);
                });
        });
    }

    private function currentLegacyAdminId(Request $request): ?int
    {
        return app(AuthContextService::class)->currentLegacyId($request);
    }

    /** 订单按钮由后端权限与状态共同裁定，前端不得自行猜测。 */
    private function orderAllowedActions(Request $request, SalesOrder $order, ?array $changeEligibility = null): array
    {
        $user = app(AuthContextService::class)->currentUser($request);
        $codes = $user ? app(AuthContextService::class)->permissionCodes($user) : [];
        $draft = $order->order_status === 'draft';
        $pending = $order->confirm_status !== 'pending_confirmation';
        $canConfirm = in_array('sales_order.formal_confirm', $codes, true);
        return [
            'edit_draft' => $draft && in_array('sales_order.edit_draft', $codes, true),
            'delete_draft' => $draft && in_array('sales_order.delete_draft', $codes, true),
            'submit_confirmation' => $draft && $pending && in_array('sales_order.submit_confirmation', $codes, true),
            'formal_confirm' => $draft && !$pending && $canConfirm,
            'production_confirmation' => $order->order_status === 'confirmed'
                && in_array($order->production_confirm_status, ['pending', 'blocked'], true)
                && in_array('sales_order.production_confirm', $codes, true),
            'lock_inventory' => $order->order_status === 'confirmed'
                && $order->shipment_status !== 'shipped'
                && in_array('sales_order.inventory_lock', $codes, true),
            'cancel' => $order->order_status !== 'cancelled'
                && $order->shipment_status === 'not_shipped'
                && in_array('sales_order.cancel', $codes, true),
            'edit' => ($draft && in_array('sales_order.edit_draft', $codes, true))
                || (($changeEligibility['allowed'] ?? ($order->order_status === 'confirmed' && $order->shipment_status === 'not_shipped'))
                    && (in_array('sales_order.change', $codes, true) || in_array('sales_order.change.submit', $codes, true))),
            'change' => false,
            'view_attachment' => in_array('sales_order.view_attachment', $codes, true),
            'upload_attachment' => $draft && in_array('sales_order.upload_attachment', $codes, true),
            'delete_attachment' => $draft && in_array('sales_order.delete_attachment', $codes, true),
        ];
    }

    private function attachmentPayload(Request $request, SalesOrderAttachment $attachment): array
    {
        $user = app(AuthContextService::class)->currentUser($request);
        $codes = $user ? app(AuthContextService::class)->permissionCodes($user) : [];
        $temporary = !$attachment->sales_order_id && !optional($attachment->line)->sales_order_id;
        $owner = $user && (int) $attachment->uploaded_by_legacy_id === (int) $user->legacy_id;
        $superAdmin = $user && app(AuthContextService::class)->isSuperAdmin($user);
        $canView = in_array('sales_order.view_attachment', $codes, true) && (!$temporary || $owner || $superAdmin);
        $canDelete = in_array('sales_order.delete_attachment', $codes, true) && (!$temporary || $owner || $superAdmin);

        return [
            'id' => $attachment->id,
            'attachment_id' => $attachment->id,
            'temporary' => $temporary,
            'original_name' => $attachment->original_name,
            'file_name' => $attachment->original_name,
            'attachment_scope' => $attachment->attachment_scope,
            'attachment_type' => $attachment->attachment_type,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'uploaded_by' => $attachment->uploaded_by,
            'uploaded_at' => $attachment->uploaded_at,
            'version_no' => (int) ($attachment->version_no ?: 1),
            'status' => $attachment->status,
            'can_preview' => $canView && $this->attachmentPreviewable($attachment),
            'can_download' => $canView,
            'can_delete' => $canDelete,
        ];
    }

    private function decorateAttachment(SalesOrderAttachment $attachment, array $allowedActions): void
    {
        $attachment->makeHidden([
            'stored_name', 'storage_disk', 'storage_path', 'url', 'file_hash', 'metadata',
            'deleted_by', 'deleted_at', 'uploaded_by_legacy_id', 'draft_token', 'line_uuid',
        ]);
        $attachment->setAttribute('temporary', false);
        $attachment->setAttribute('can_preview', $allowedActions['view_attachment'] && $this->attachmentPreviewable($attachment));
        $attachment->setAttribute('can_download', $allowedActions['view_attachment']);
        $attachment->setAttribute('can_delete', $allowedActions['delete_attachment']);
    }

    private function attachmentPreviewable(SalesOrderAttachment $attachment): bool
    {
        return in_array(strtolower((string) $attachment->mime_type), [
            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        ], true);
    }

    private function workOrderTrackingProjection(SalesOrder $order): array
    {
        // 当前库尚未进入生产工单阶段，禁止在销售草稿阶段伪造工单或工序数据。
        return [];
    }

    /** 订单详情只读展示下单时的 Item 快照，不允许借详情页改写历史关联。 */
    private function orderLineItemProjection($line): array
    {
        $lineType = $line->line_type
            ?: data_get($line->sku_snapshot, 'order_line_type')
            ?: data_get($line->sku_snapshot, 'fulfillment_type')
            ?: 'physical';

        if (in_array($lineType, ['service', 'no_delivery', 'fee', 'auxiliary'], true)) {
            return [
                'status' => 'not_required',
                'item_code' => null,
                'item_name' => null,
                'message' => '无需 Item',
            ];
        }

        $itemCode = data_get($line->item_snapshot, 'item_code') ?: optional($line->item)->item_code;
        $itemName = data_get($line->item_snapshot, 'item_name') ?: optional($line->item)->item_name;
        if ($itemCode && $itemName && $line->item_match_status === 'matched') {
            return [
                'status' => 'matched',
                'item_code' => $itemCode,
                'item_name' => $itemName,
                'message' => null,
            ];
        }

        return [
            'status' => 'abnormal',
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'message' => $line->item_match_block_reason ?: '历史订单行缺少有效系统 Item 快照',
        ];
    }

    /** 按业务附件类型、扩展名和服务端识别 MIME 三重校验，禁止仅相信前端类型。 */
    private function assertAttachmentFileAllowed($file, string $attachmentType): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $documents = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $images = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed = in_array($attachmentType, ['design_drawing', 'customer_drawing'], true)
            ? array_merge($documents, $images, ['dwg', 'dxf'])
            : (in_array($attachmentType, ['contract', 'customer_agreement', 'technical_agreement'], true)
                ? array_merge($documents, $images)
                : array_merge($documents, $images, ['txt', 'zip', 'rar']));
        abort_unless(in_array($extension, $allowed, true), 422, '该附件类型不允许此文件扩展名');
        abort_unless($mime !== '' && $mime !== 'application/octet-stream', 422, '无法识别文件真实 MIME 类型');
        $mimeRules = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'txt' => ['text/plain'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/vnd.rar', 'application/x-rar-compressed'],
            'dwg' => ['application/acad', 'application/x-acad', 'image/vnd.dwg', 'application/dwg'],
            'dxf' => ['application/dxf', 'image/vnd.dxf', 'application/x-autocad'],
        ];
        abort_unless(
            isset($mimeRules[$extension]) && in_array($mime, $mimeRules[$extension], true),
            422,
            '附件扩展名与真实 MIME 类型不匹配'
        );
    }

    private function abortUnlessPermission(Request $request, string $permissionCode): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (!$user) {
            abort(401, '未登录或登录已过期');
        }

        abort_unless(in_array($permissionCode, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permissionCode);
    }

    private function abortUnlessAnyPermission(Request $request, array $permissionCodes): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (!$user) abort(401, '未登录或登录已过期');
        $allowed = array_intersect($permissionCodes, $auth->permissionCodes($user));
        abort_unless(!empty($allowed), 403, '无按钮权限：'.implode(',', $permissionCodes));
    }

    private function abortUnlessOrderVisible(Request $request, SalesOrder $order): void
    {
        if (!$this->currentLegacyAdminId($request)) {
            abort(401, '未登录或登录已过期');
        }

        $query = SalesOrder::query()->whereKey($order->id);
        $this->applySalesOrderVisibility($query, $request);
        abort_unless($query->exists(), 403, '无权访问该销售订单');
    }

    private function attachCurrentOperator(array $payload, Request $request): array
    {
        $legacyAdminId = $this->currentLegacyAdminId($request);
        if (!$legacyAdminId) {
            return $payload;
        }

        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyAdminId)->first();
        if (!$user) {
            return $payload;
        }

        $payload['created_by_legacy_id'] = $payload['created_by_legacy_id'] ?? $legacyAdminId;
        if (empty($payload['sales_user_legacy_id'])) {
            $payload['sales_user_legacy_id'] = $legacyAdminId;
        }
        if (empty($payload['created_by'])) {
            $payload['created_by'] = $user->nickname ?: $user->username;
        }

        return $payload;
    }

    private function resolveLineUnit(array $line, Sku $sku, Product $product, ?Item $item): ?Unit
    {
        $unitId = $line['unit_id'] ?? null;
        if ($unitId) return Unit::find($unitId);
        if ($item?->unit_id) return Unit::find($item->unit_id);
        if ($product->unit_id) return Unit::find($product->unit_id);
        return Unit::where('unit_code', 'PCS')->orWhere('unit_name', '台')->first();
    }

    private function bindDraftLineAttachments(SalesOrder $order, SalesOrderLine $line, ?string $draftToken, ?string $lineUuid): void
    {
        if (!$draftToken || !$lineUuid) return;
        SalesOrderAttachment::where('draft_token', $draftToken)
            ->where('line_uuid', $lineUuid)
            ->whereNull('sales_order_line_id')
            ->where('status', 'active')
            ->update([
                'sales_order_id' => $order->id,
                'sales_order_line_id' => $line->id,
                'updated_at' => now(),
            ]);
    }

    private function bindDraftOrderAttachments(SalesOrder $order, ?string $draftToken): void
    {
        if (!$draftToken) return;
        SalesOrderAttachment::where('draft_token', $draftToken)
            ->where('attachment_scope', 'order')
            ->whereNull('sales_order_id')
            ->where('status', 'active')
            ->update([
                'sales_order_id' => $order->id,
                'updated_at' => now(),
            ]);
    }

    private function saveLines(SalesOrder $order, array $lines, ?string $draftToken = null): void
    {
        foreach (array_values($lines) as $index => $line) {
            $created = SalesOrderLine::create($this->buildLinePayload($order, $line, $index));
            $this->bindDraftLineAttachments($order, $created, $draftToken, $created->line_uuid);
        }
    }

    private function syncDraftLines(SalesOrder $order, array $lines, array $deletedLineIds = [], ?string $draftToken = null): void
    {
        if ($deletedLineIds) {
            $deletable = SalesOrderLine::where('sales_order_id', $order->id)
                ->whereIn('id', $deletedLineIds)
                ->where('line_status', 'open')
                ->pluck('id')
                ->all();
            SalesOrderAttachment::whereIn('sales_order_line_id', $deletable)->update(['status' => 'deleted']);
            SalesOrderLine::whereIn('id', $deletable)->delete();
        }

        foreach (array_values($lines) as $index => $line) {
            $existing = null;
            if (!empty($line['id'])) {
                $existing = SalesOrderLine::where('sales_order_id', $order->id)->find($line['id']);
            }
            if ($existing) {
                $existing->update($this->buildLinePayload($order, $line, $index, $existing));
                $this->bindDraftLineAttachments($order, $existing->fresh(), $draftToken, $existing->line_uuid);
            } else {
                $created = SalesOrderLine::create($this->buildLinePayload($order, $line, $index));
                $this->bindDraftLineAttachments($order, $created, $draftToken, $created->line_uuid);
            }
        }
    }

    private function buildLinePayload(SalesOrder $order, array $line, int $index, ?SalesOrderLine $existing = null): array
    {
        $sku = isset($line['sku_id']) ? Sku::with('product')->find($line['sku_id']) : null;
        abort_if(!$sku, 422, '订单行必须选择有效 SKU');
        abort_if($sku->status !== 'enabled', 422, 'SKU 已停用，不能用于销售订单');
        abort_if(!$sku->is_sale_item, 422, 'SKU 未启用销售属性，不能用于销售订单');

        $product = isset($line['product_id']) ? Product::find($line['product_id']) : $sku->product;
        abort_if(!$product, 422, '订单行必须选择有效 Product');
        abort_if($product->status !== 'enabled', 422, 'Product 已停用，不能用于销售订单');
        abort_if((int) $sku->product_id !== (int) $product->id, 422, 'SKU 不属于当前 Product');

        $isCustomized = (bool) ($line['is_customized'] ?? false);
        $isSpecialCustomized = (bool) ($line['is_special_customized'] ?? false);
        abort_if($isCustomized && !$sku->allow_customized, 422, '当前 SKU 不允许普通定制。');
        abort_if($isSpecialCustomized && !$sku->allow_special_customized, 422, '当前 SKU 不允许特殊定制。');
        abort_if($isCustomized && !$sku->is_customizable && !$sku->is_custom_sku, 422, '当前 SKU 不允许普通定制');
        abort_if($isSpecialCustomized && !$sku->is_customizable && !$sku->is_custom_sku, 422, '当前 SKU 不允许特殊定制');

        $electric = trim((string) ($line['electric'] ?? '')) ?: null;
        $needPump = array_key_exists('need_pump', $line) && $line['need_pump'] !== null
            ? (bool) $line['need_pump']
            : null;
        $configuration = is_array($line['configuration_snapshot'] ?? null) ? $line['configuration_snapshot'] : [];
        unset($configuration['electric'], $configuration['need_pump']);

        if ($electric !== null && $sku->supports_electric && !empty($sku->electric_options) && !in_array($electric, $sku->electric_options, true)) {
            abort(422, '当前 SKU 不支持所选电压');
        }

        $match = app(SkuItemMatcher::class)->match([
            'product_id' => $product->id,
            'sku_id' => $sku->id,
        ]);
        $item = $match['matched_item_id'] ? Item::with('unit')->find($match['matched_item_id']) : null;
        $unit = app(\App\Services\Erp\UnitConversionDomainService::class)
            ->canonicalUnit($this->resolveLineUnit($line, $sku, $product, $item));
        $qty = (float) $line['order_qty'];
        $price = (float) ($line['unit_price'] ?? 0);
        $lineType = $this->normalizeLineType(null, $line['legacy_goods_type'] ?? null, $sku, $product);
        $lineUuid = $line['line_uuid'] ?? $existing?->line_uuid ?? (string) \Illuminate\Support\Str::uuid();

        return [
            'sales_order_id' => $order->id,
            'line_uuid' => $lineUuid,
            'line_no' => $line['line_no'] ?? ($index + 1),
            'legacy_order_product_id' => $line['legacy_order_product_id'] ?? $existing?->legacy_order_product_id,
            'legacy_goods_id' => $line['legacy_goods_id'] ?? $existing?->legacy_goods_id,
            'legacy_sku_id' => $line['legacy_sku_id'] ?? $existing?->legacy_sku_id,
            'product_id' => $product->id,
            'sku_id' => $sku->id,
            'item_id' => $item?->id,
            'item_match_status' => $match['match_status'],
            'item_match_rule' => $match['match_rule'],
            'item_match_block_reason' => $match['block_reason'],
            'item_match_snapshot' => ['candidates' => $match['conflict_candidates']],
            'product_name' => $product->product_name,
            'sku_name' => $sku->sku_name,
            'item_name' => $item?->item_name,
            'legacy_goods_type' => $line['legacy_goods_type'] ?? null,
            'line_type' => $lineType,
            'order_qty' => $qty,
            'unit_id' => $unit?->id,
            'unit_name_snapshot' => $unit?->unit_name,
            'unit_code_snapshot' => $unit?->unit_code,
            'unit_conversion_ratio_snapshot' => 1,
            'unit_price' => $price,
            'amount' => $qty * $price,
            'need_pump' => $needPump,
            'electric' => $electric,
            'is_customized' => $isCustomized || (bool) $sku->is_custom_sku,
            'is_special_customized' => $isSpecialCustomized,
            'configuration_snapshot' => [
                'need_pump' => $needPump,
                'electric' => $electric,
                'is_customized' => $isCustomized || (bool) $sku->is_custom_sku,
                'is_special_customized' => $isSpecialCustomized,
            ] + $configuration,
            'product_snapshot' => null,
            'sku_snapshot' => null,
            'item_snapshot' => null,
            'bom_snapshot' => null,
            'routing_snapshot' => null,
            'drawing_snapshot' => $line['drawing_snapshot'] ?? $existing?->drawing_snapshot,
            'technical_attachment_snapshot' => $line['technical_attachment_snapshot'] ?? $existing?->technical_attachment_snapshot,
            'inspection_snapshot' => ['delivery_inspection_required' => (bool) $sku->delivery_inspection_required],
            'image' => null,
            'design' => null,
            'remark' => $line['remark'] ?? null,
        ];
    }

    private function assertLineOrderAttributes(SalesOrderLine $line): void
    {
        $sku = $line->sku ?: Sku::find($line->sku_id);
        if (!$sku) return;

        $electric = trim((string) ($line->electric ?? ''));
        if ($sku->supports_electric && $sku->electric_required && $electric === '') {
            abort(422, "订单行 {$line->line_no} 的 SKU 要求填写电压");
        }
        if ($sku->supports_electric && $electric !== '' && !empty($sku->electric_options)
            && !in_array($electric, $sku->electric_options, true)) {
            abort(422, "订单行 {$line->line_no} 的电压不在 SKU 允许范围内");
        }
        if ($sku->supports_need_pump && $sku->need_pump_required && is_null($line->need_pump)) {
            abort(422, "订单行 {$line->line_no} 的 SKU 要求选择原水泵控制");
        }
    }

    private function hasLineTechnicalAttachment(SalesOrderLine $line): bool
    {
        $sku = $line->sku ?: Sku::find($line->sku_id);
        if (!$sku) return false;
        $requirements = [
            'design_drawing' => (bool) $sku->special_custom_drawing_required,
            'technical_agreement' => (bool) $sku->special_custom_agreement_required,
        ];
        if (!$sku->allow_special_customized || !array_filter($requirements)) {
            return !$sku->special_custom_description_required || !blank(data_get($line->configuration_snapshot, 'special_custom_description'));
        }

        foreach ($requirements as $attachmentType => $required) {
            if ($required && !SalesOrderAttachment::where('sales_order_line_id', $line->id)->where('status', 'active')->where('attachment_type', $attachmentType)->exists()) return false;
        }
        if ($sku->special_custom_description_required && blank(data_get($line->configuration_snapshot, 'special_custom_description'))) return false;
        return true;

    }

    private function lineAttachmentSnapshot(SalesOrderLine $line): array
    {
        return SalesOrderAttachment::where('sales_order_line_id', $line->id)
            ->where('status', 'active')
            ->get(['id as attachment_id', 'attachment_type', 'original_name', 'stored_name', 'storage_disk', 'storage_path', 'url', 'mime_type', 'file_size', 'file_hash', 'uploaded_by', 'uploaded_at'])
            ->toArray();
    }

    private function resolveProductionRequirementStatus(SalesOrderLine $line, array $bom): string
    {
        if ($line->item_match_status !== 'matched') return 'blocked';
        if ($bom['status'] !== 'matched') return 'blocked';
        return 'blocked';
    }

    private function productionContract(SalesOrder $order): array
    {
        return $order->lines
            ->filter(fn (SalesOrderLine $line) => (float) $line->production_required_qty > 0)
            ->map(fn (SalesOrderLine $line) => [
                'order_id' => $order->id,
                'sales_order_no' => $order->sales_order_no,
                'sales_order_line_id' => $line->id,
                'production_qty' => $line->production_required_qty,
                'product_id' => $line->product_id,
                'sku_id' => $line->sku_id,
                'item_id' => $line->item_id,
                'configuration_snapshot' => $line->configuration_snapshot,
                'bom_snapshot' => $line->bom_snapshot,
                'routing_snapshot' => $line->routing_snapshot,
                'drawing_snapshot' => $line->drawing_snapshot,
                'technical_attachment_snapshot' => $line->technical_attachment_snapshot,
                'inspection_snapshot' => $line->inspection_snapshot,
                'required_delivery_date' => $order->required_delivery_date,
                'is_urgent' => $order->is_urgent,
                'is_delay' => $order->is_delay,
                'delay_date' => $order->delay_date,
            ])
            ->values()
            ->all();
    }

    private function primaryItemForSku(?int $skuId): ?Item
    {
        if (!$skuId) return null;
        $relation = SkuItemRelation::where('sku_id', $skuId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->whereNotNull('item_id')
            ->first();
        return $relation ? Item::find($relation->item_id) : null;
    }

    private function normalizeLineType(?string $lineType, ?string $legacyGoodsType, ?Sku $sku, ?Product $product = null): string
    {
        if ($legacyGoodsType === '6') return 'no_delivery';
        if ($legacyGoodsType === '5') return 'auxiliary';
        $skuLineType = $sku ? ($sku->order_line_type ?: $sku->fulfillment_type) : null;
        if ($skuLineType === 'virtual') $skuLineType = 'no_delivery';
        if ($skuLineType === 'service') return 'service';
        if (in_array($skuLineType, ['no_delivery', 'fee', 'auxiliary'], true)) return $skuLineType;
        if ($product && $product->product_type === 'service') return 'service';
        return 'physical';
    }

    private function decorateFulfillmentComposition(SalesOrder $order): void
    {
        $rows = $order->relationLoaded('fulfillments') ? $order->fulfillments : collect();
        $types = $rows
            ->where('demand_status', 'confirmed')
            ->filter(fn (SalesOrderFulfillment $row) => in_array($row->fulfillment_type, ['inventory', 'production', 'service', 'no_delivery'], true)
                && (float) ($row->sales_qty ?? $row->fulfillment_qty) > 0)
            ->pluck('fulfillment_type')->unique()->values();
        $quantities = $rows->groupBy('fulfillment_type')->map(fn ($group) => round($group->sum(fn ($row) => (float) ($row->sales_qty ?? $row->fulfillment_qty)), 8));
        foreach (['inventory', 'production', 'service', 'no_delivery'] as $type) {
            $quantities->put($type, (float) $quantities->get($type, 0));
        }
        $effectiveQty = $order->lines->sum(fn (SalesOrderLine $line) => max(0, (float) $line->order_qty - (float) $line->cancelled_qty));
        $unallocatedQty = max(0, $effectiveQty - (float) $quantities->sum());
        $planStatus = $quantities->sum() <= 0.00000001
            ? 'unallocated'
            : ($unallocatedQty > 0.00000001 ? 'partially_allocated' : 'allocated');
        $quantities->put('undetermined', round($unallocatedQty, 8));

        if ($types->isEmpty()) {
            $label = '尚未形成履约明细';
        } elseif ($types->count() === 1 && $types->first() === 'inventory' && $planStatus === 'allocated') {
            $label = '全部库存';
        } elseif ($types->count() === 1 && $types->first() === 'production' && $planStatus === 'allocated') {
            $label = '全部生产';
        } elseif ($types->contains('inventory') && $types->contains('production')) {
            $label = '部分库存 + 部分生产';
        } else {
            $names = ['inventory' => '库存', 'production' => '生产', 'service' => '服务', 'no_delivery' => '无需发货'];
            $label = $types->map(fn ($type) => $names[$type] ?? $type)->implode(' + ');
        }

        $order->setAttribute('fulfillment_composition_label', $label);
        $order->setAttribute('fulfillment_composition', $quantities);
        $order->setAttribute('fulfillment_plan_status', $planStatus);
        $order->setAttribute('fulfillment_plan_status_label', [
            'unallocated' => '未分配',
            'partially_allocated' => '部分分配',
            'allocated' => '已分配完成',
        ][$planStatus]);
    }

    private function refreshOrderTotals(int $orderId): void
    {
        $totals = SalesOrderLine::where('sales_order_id', $orderId)
            ->selectRaw('COALESCE(SUM(order_qty),0) qty, COALESCE(SUM(amount),0) amount')
            ->first();
        $order = SalesOrder::findOrFail($orderId);
        $order->update([
            'total_qty' => $totals->qty,
            'total_amount' => (float) $totals->amount + (float) $order->freight_amount,
        ]);
    }

    private function version(int $orderId, string $changeType, ?array $before, ?array $after, string $operator): void
    {
        $next = (int) SalesOrderVersion::where('sales_order_id', $orderId)->max('version_no') + 1;
        SalesOrderVersion::create([
            'sales_order_id' => $orderId,
            'version_no' => $next,
            'change_type' => $changeType,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'operator' => $operator,
        ]);
    }

    private function log(int $orderId, ?int $lineId, string $action, ?string $before, ?string $after, string $content, array $payload = []): void
    {
        SalesOrderLog::create([
            'sales_order_id' => $orderId,
            'sales_order_line_id' => $lineId,
            'action' => $action,
            'before_status' => $before,
            'after_status' => $after,
            'payload' => $payload,
            'operator' => 'system',
            'content' => $content,
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(5, (int) $request->input('per_page', 20)));
    }

    private function nextNo(string $prefix): string
    {
        return app(DocumentNumberService::class)->next($prefix, $prefix);
    }

    private function operatorName(Request $request): string
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return $user ? ($user->nickname ?: $user->username ?: 'system') : 'system';
    }
}
