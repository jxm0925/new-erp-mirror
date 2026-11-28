<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Bom, BomItem, BomItemOperation, BomLog, InventoryBalance, Item, Sku};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\BomExplosionEngine;
use App\Services\Erp\BomLifecycleApplicationService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    private const RELATIONS = [
        'product', 'sku.product', 'outputItem.unit', 'items.componentItem.category', 'items.componentItem.unit.standardUnit',
        'items.unit.standardUnit', 'items.operationBinding.operation:id,operation_no,operation_name', 'sourceProduct', 'sourceSku', 'sourceStandardBom', 'logs',
        'routing:id,routing_no,routing_name,version,status',
    ];

    public function index(Request $request)
    {
        $forExpansion = $request->boolean('for_expansion');
        if ($forExpansion) $this->authorizeExpansion($request);
        else $this->authorizePermission($request, ['bom.manage', 'bom.manage.view']);
        $query = ($forExpansion ? app(BomExplosionEngine::class)->eligibleQuery() : Bom::query())
            ->with(['product', 'sku.product', 'outputItem.unit', 'approvalTask'])->latest('updated_at');
        if ($request->has('ids')) {
            $selected = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'required|integer|min:1|distinct']);
            $query->whereIn('id', $selected['ids']);
        }
        if (! $forExpansion && $request->input('deleted') === 'only') $query->onlyTrashed();
        $keyword = trim((string) $request->input('keyword'));
        if ($keyword !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('bom_no', 'like', "%{$keyword}%")
                ->orWhere('bom_name', 'like', "%{$keyword}%")
                ->orWhereHas('outputItem', fn ($iq) => $iq->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"))
                ->orWhereHas('sku', fn ($sq) => $sq->where('sku_code', 'like', "%{$keyword}%")->orWhere('sku_name', 'like', "%{$keyword}%"))
                ->orWhereHas('product', fn ($pq) => $pq->where('product_code', 'like', "%{$keyword}%")->orWhere('product_name', 'like', "%{$keyword}%")));
        }
        foreach (['status', 'audit_status', 'bom_type', 'product_id', 'sku_id', 'output_item_id'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        if ($request->input('submission_state') === 'draft') $query->where('status', 'draft')->where('audit_status', '<>', 'approved')->whereNull('submitted_at');
        if ($request->input('submission_state') === 'pending') $query->where('status', 'draft')->where('audit_status', 'pending')->whereNotNull('submitted_at');
        if ($request->filled('is_default')) {
            $query->where('is_default', filter_var($request->input('is_default'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('date_from')) $query->whereDate('updated_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('updated_at', '<=', $request->input('date_to'));
        $stats = null;
        if (! $forExpansion && $request->boolean('include_stats')) {
            $counts = (clone $query)->reorder()->toBase()->selectRaw("COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'draft' AND audit_status <> 'approved' AND submitted_at IS NULL THEN 1 ELSE 0 END), 0) AS draft,
                COALESCE(SUM(CASE WHEN status = 'draft' AND audit_status = 'pending' AND submitted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active")->first();
            $stats = collect((array) $counts)->map(fn ($count) => (int) $count)->all();
        }
        $page = $query->orderByDesc('id')->paginate(isset($selected) ? count($selected['ids']) : $this->perPage($request));
        $page->through(fn ($bom) => app(\App\Services\Erp\BomApplicationService::class)->withApprovalState($bom));
        return response()->json([
            ...$page->toArray(),
            ...($stats === null ? [] : ['stats' => $stats]),
        ]);
    }

    public function store(Request $request)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.create');
        $payload = $this->validateBom($request);
        $service = app(\App\Services\Erp\BomApplicationService::class);
        $bom = $service->create($payload, $operator, $this->canPerformance($request, 'edit'));
        return response()->json(['message' => $service->resultMessage($bom, 'BOM 已保存为草稿'), 'data' => $this->withStandards($request, $bom->fresh(self::RELATIONS))], 201);
    }

    /** BOM 行可选的用料工序：产出物料适用工艺路线里的车间工序。 */
    public function operationOptions(Request $request)
    {
        $this->authorizePermission($request, ['bom.manage', 'bom.manage.view', 'bom.manage.create', 'bom.manage.edit']);
        $data = $request->validate(['output_item_id' => 'required|integer|exists:erp_items,id']);
        return response()->json(['data' => app(\App\Services\Erp\BomOperationBindingService::class)->options((int) $data['output_item_id'])]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, ['bom.manage', 'bom.manage.view', 'bom.manage.edit']);
        $query = Bom::with(self::RELATIONS);
        if ($request->input('deleted') === 'only') $query->onlyTrashed();
        $bom = $query->findOrFail($id);
        $bom->setAttribute('version_history', $this->versionHistory($bom));
        return response()->json($this->withStandards($request, $bom));
    }

    public function update(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        $payload = $this->validateBom($request, $id);
        $service = app(\App\Services\Erp\BomApplicationService::class);
        $bom = $service->update($id, $payload, $operator, $this->canPerformance($request, 'edit'));
        return response()->json(['message' => $service->resultMessage($bom, 'BOM 草稿已保存'), 'data' => $this->withStandards($request, $bom->fresh(self::RELATIONS))]);
    }

    public function submit(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        $options = $request->validate(['client_command_id' => 'sometimes|string|max:120', 'set_default' => 'sometimes|boolean']);
        $service = app(\App\Services\Erp\BomApplicationService::class);
        $bom = $service->submit($id, $options, $operator);
        return response()->json(['message' => $service->resultMessage($bom, 'BOM 已提交生效'), 'data' => $service->withApprovalState($bom->fresh(self::RELATIONS))]);
    }

    public function approve(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        $bom = app(\App\Services\Erp\BomApplicationService::class)->approveLegacy($id, $operator);
        return response()->json(['message' => '历史待审 BOM 已审核通过，可继续启用', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function reject(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        $data = $request->validate(['remark' => 'nullable|string|max:2000']);
        $bom = app(\App\Services\Erp\BomApplicationService::class)->rejectLegacy($id, trim((string) ($data['remark'] ?? '')), $operator);
        return response()->json(['message' => 'BOM 已驳回', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function activate(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        $bom = app(\App\Services\Erp\BomApplicationService::class)->activate($id, $operator);
        return response()->json(['message' => 'BOM 已启用', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function deactivate(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        $bom = app(\App\Services\Erp\BomApplicationService::class)->deactivate($id, $operator);
        return response()->json(['message' => 'BOM 已停用', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function setDefault(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        $bom = app(\App\Services\Erp\BomApplicationService::class)->setDefault($id, $operator);
        return response()->json(['message' => '默认 BOM 已更新', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function copyVersion(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.create');
        $data = $request->validate([
            'version' => 'nullable|string|max:40',
            'bom_name' => 'nullable|string|max:160',
            'output_item_id' => 'nullable|integer|exists:erp_items,id',
        ]);
        return DB::transaction(function () use ($id, $data, $operator, $request) {
            $source = Bom::with('items.operationBinding')->lockForUpdate()->findOrFail($id);
            $this->assertFactoryBom($source);
            // 指定其他产出物料时为“复制到其他物料”：同类不同规格先复制再改尺寸，不带产品/SKU 限定。
            $targetItemId = (int) ($data['output_item_id'] ?? $source->output_item_id);
            $crossItem = $targetItemId !== (int) $source->output_item_id;
            if ($crossItem) {
                abort_if($source->bom_type === 'custom', 422, '定制 BOM 不能复制到其他物料。');
                abort_if($source->items->contains(fn ($line) => (int) $line->component_item_id === $targetItemId), 422, 'BOM 组成物料不能等于产出物料。');
                $this->assertProductionTarget(['output_item_id' => $targetItemId]);
                $this->assertNoCycleForCandidate($targetItemId, $source->items->map(fn ($line) => ['component_item_id' => (int) $line->component_item_id,
                    'child_bom_id' => $line->child_bom_id ? (int) $line->child_bom_id : null])->all());
                $targetItem = Item::query()->findOrFail($targetItemId);
            }
            $copy = Bom::create([
                'bom_no' => $this->nextNo('BOM'),
                'bom_name' => $data['bom_name'] ?? ($crossItem ? $targetItem->item_name : $source->bom_name . ' - 新版本'),
                'product_id' => $crossItem ? null : $source->product_id,
                'sku_id' => $crossItem ? null : $source->sku_id,
                'output_item_id' => $targetItemId,
                // 复制到其他物料时路线可能不适用，不带路线和工时提成，需重新选择。
                'routing_id' => $crossItem ? null : $source->routing_id,
                'bom_type' => $source->bom_type,
                'version' => $data['version'] ?? ($crossItem ? 'V1.0' : $this->nextVersion($source->version)),
                'is_default' => false,
                'status' => 'draft',
                'audit_status' => 'pending',
                'submitted_at' => null,
                'effective_date' => $source->effective_date,
                'expire_date' => null,
                'source_product_id' => $source->bom_type === 'custom' ? $source->source_product_id : null,
                'source_sku_id' => $source->bom_type === 'custom' ? $source->source_sku_id : null,
                'source_standard_bom_id' => $source->bom_type === 'custom' ? $source->source_standard_bom_id : null,
                'custom_description' => $source->custom_description,
                'remark' => $source->remark,
            ]);
            // 目标物料的适用路线可能不同：不在其路线车间工序里的绑定不复制，该行改按路线规则，并提示用户确认。
            $allowedOperations = app(\App\Services\Erp\BomOperationBindingService::class)->allowedOperationIds($targetItemId,
                $source->items->pluck('operationBinding.operation_id')->filter()->all());
            $unbound = [];
            $uidMap = [];
            foreach ($source->items as $line) {
                $newUid = (string) Str::uuid();
                if ($line->position_uid) $uidMap[$line->position_uid] = $newUid;
                $created = BomItem::create($line->only([
                    'line_no', 'component_item_id', 'component_item_code', 'component_item_name', 'child_bom_id',
                    'cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'allow_cut_rotation', 'piece_qty', 'cutting_quantity_snapshot', 'qty', 'unit_id', 'loss_rate', 'fixed_qty', 'replaceable', 'remark',
                ]) + ['bom_id' => $copy->id, 'position_uid' => $newUid]);
                if (! $line->operationBinding) continue;
                if (in_array((int) $line->operationBinding->operation_id, $allowedOperations, true)) {
                    BomItemOperation::create(['bom_item_id' => $created->id, 'operation_id' => $line->operationBinding->operation_id]);
                } else {
                    $unbound[] = $line->component_item_name;
                }
            }
            if (! $crossItem) {
                $this->standards()->copy($source, $copy);
                $this->positionPerformance()->copy($source, $copy, $uidMap);
            }
            app(\App\Services\Erp\TechnicalDrawingSourceService::class)->copyBom($source, $copy, $uidMap, $operator);
            $unboundNote = $unbound ? '；物料 '.implode('、', array_unique($unbound)).' 原指定的用料工序不在当前适用路线中，已改按路线规则，请确认' : '';
            $this->log($copy, 'copy_version', ($crossItem ? "由 {$source->bom_no} 复制到物料 {$targetItem->item_code}" : "由 {$source->bom_no} 复制为新版本").$unboundNote, (int) $operator->legacy_id);
            return response()->json(['message' => ($crossItem ? '已复制到所选物料，生成草稿' : '已复制为新版本草稿').$unboundNote, 'data' => $this->withStandards($request, $copy->fresh(self::RELATIONS)), 'unbound_component_names' => array_values(array_unique($unbound))], 201);
        }, 5);
    }

    public function destroy(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.delete');
        app(BomLifecycleApplicationService::class)->softDelete($id, (int) $operator->legacy_id);
        return response()->json(['message' => 'BOM 已移入回收站']);
    }

    public function restore(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.delete');
        $bom = app(BomLifecycleApplicationService::class)->restore($id, (int) $operator->legacy_id);
        if ($bom->wasChanged('deleted_at')) app(WorkOrderService::class)->retryBlockedForMasterDataAfterCommit((int) $bom->id, null, $operator);
        return response()->json(['message' => 'BOM 已恢复', 'data' => $bom->load(self::RELATIONS)]);
    }

    public function expand(Request $request)
    {
        $this->authorizeExpansion($request);
        $data = $request->validate([
            'bom_id' => ['nullable', Rule::exists('erp_boms', 'id')->whereNull('deleted_at')],
            'sku_id' => 'nullable|exists:erp_skus,id',
            'product_id' => 'nullable|exists:erp_products,id',
            'output_item_id' => 'nullable|exists:erp_items,id',
            'planned_qty' => 'required|numeric|min:0.0001',
            'business_date' => 'nullable|date',
        ]);
        $businessDate = $this->businessDate($data['business_date'] ?? null);
        $engine = app(BomExplosionEngine::class);
        $itemId = $data['output_item_id'] ?? null;
        $productId = $data['product_id'] ?? null;
        if (isset($data['bom_id'])) $itemId ??= (int) Bom::findOrFail($data['bom_id'])->output_item_id;
        elseif (isset($data['sku_id'])) {
            $itemMatch = app(\App\Services\Erp\SkuItemMatcher::class)->match($data);
            abort_unless($itemMatch['match_status'] === 'matched', 422, $itemMatch['block_reason'] ?: 'SKU 未配置可用于生产的默认物料。');
            $itemId ??= $itemMatch['matched_item_id'];
            $productId ??= (int) Sku::findOrFail($data['sku_id'])->product_id;
        }
        abort_unless($itemId, 422, '请选择 BOM、SKU 或产出物料。');
        $selection = $engine->select($productId, $data['sku_id'] ?? null, (int) $itemId, $data['bom_id'] ?? null, $businessDate->toDateString(), requireMaterials: true, matchScope: ! isset($data['bom_id']));
        abort_unless($selection['status'] === 'matched', 422, $selection['block_reason']);
        $bom = $selection['bom']->loadMissing(self::RELATIONS);

        $plannedQty = (float) $data['planned_qty'];
        $expanded = $engine->expand($bom, $plannedQty, $businessDate->toDateString());
        $itemIds = collect($expanded['aggregate_lines'])->pluck('component_item_id')->unique()->values();
        $stocks = $this->stockMap($itemIds);
        $lines = collect($expanded['aggregate_lines'])->map(function (array $line) use ($stocks, $plannedQty) {
            $stock = $stocks->get($line['component_item_id']);
            $available = (float) ($stock->available ?? 0);
            return $line + [
                'planned_qty' => $plannedQty,
                'quantity_on_hand' => (float) ($stock->on_hand ?? 0),
                'quantity_available' => $available,
                'quantity_locked' => (float) ($stock->locked ?? 0),
                'shortage_qty' => max(0, round($line['demand_qty'] - $available, 4)),
            ];
        })->values();

        return response()->json([
            'bom' => $bom,
            'planned_qty' => $plannedQty,
            'business_date' => $businessDate->toDateString(),
            'lines' => $lines,
            'tree_lines' => $expanded['tree_lines'],
            'summary' => [
                'line_count' => $lines->count(),
                'tree_line_count' => count($expanded['tree_lines']),
                'shortage_line_count' => $lines->filter(fn ($line) => $line['shortage_qty'] > 0)->count(),
                'total_demand_qty' => round($lines->sum('demand_qty'), 4),
                'total_available_qty' => round($lines->sum('quantity_available'), 4),
                'total_shortage_qty' => round($lines->sum('shortage_qty'), 4),
            ],
            'suggestions' => $lines->filter(fn ($line) => $line['shortage_qty'] > 0)->map(fn ($line) => [
                'message' => "建议优先补足 {$line['component_item_name']}，缺口 {$line['shortage_qty']} {$line['unit_name']}" . ($line['replaceable'] ? '，可考虑替代物料' : ''),
                'paths' => $line['paths'],
            ])->values(),
        ]);
    }

    public function exportOperationStandards(Request $request)
    {
        $this->authorizePermission($request, 'bom.manage.view');
        $data = $request->validate(['bom_ids' => 'required|array|min:1|max:200', 'bom_ids.*' => 'integer|min:1|distinct']);
        return response()->json($this->batchStandards()->export($data['bom_ids'], $this->canPerformance($request, 'view')));
    }

    public function batchOperationStandardsPreview(Request $request)
    {
        $this->authorizePermission($request, 'bom.manage.edit');
        $data = $this->validateBatchStandards($request);
        $this->checkBatchRatePermission($request, $data);
        return response()->json($this->batchStandards()->preview($data));
    }

    public function batchOperationStandardsApply(Request $request)
    {
        $user = $this->authorizePermission($request, 'bom.manage.edit');
        $data = $this->validateBatchStandards($request);
        $this->checkBatchRatePermission($request, $data);
        return response()->json($this->batchStandards()->apply($data, $user));
    }

    public function importOperationStandardsPreview(Request $request)
    {
        $this->authorizePermission($request, 'bom.manage.edit');
        $rows = $this->validateImportStandards($request);
        return response()->json($this->batchStandards()->importPreview($rows, $this->canPerformance($request, 'edit')));
    }

    public function importOperationStandardsApply(Request $request)
    {
        $user = $this->authorizePermission($request, 'bom.manage.edit');
        $rows = $this->validateImportStandards($request);
        $result = $this->batchStandards()->importApply($rows, $this->canPerformance($request, 'edit'), $user);
        if (! $result['ok']) return response()->json(array_diff_key($result, ['ok' => 1]), 422);
        return response()->json(array_diff_key($result, ['ok' => 1]));
    }

    private function validateBatchStandards(Request $request): array
    {
        $data = $request->validate([
            'bom_ids' => 'required|array|min:1|max:100', 'bom_ids.*' => 'integer|min:1|distinct',
            'source_type' => 'required|in:template,bom',
            'template_id' => 'required_if:source_type,template|integer|min:1',
            'source_bom_id' => 'required_if:source_type,bom|integer|min:1',
            'scope' => 'required|array|min:1', 'scope.*' => 'in:minutes,rates',
            'overwrite' => 'nullable|boolean',
        ]);
        if ($data['source_type'] === 'template' && ! in_array('rates', $data['scope'], true)) {
            abort(422, '提成模板来源只能写入提成设置。');
        }
        $data['overwrite'] = (bool) ($data['overwrite'] ?? false);
        return $data;
    }

    private function checkBatchRatePermission(Request $request, array $data): void
    {
        abort_if(in_array('rates', $data['scope'], true) && ! $this->canPerformance($request, 'edit'), 403, '无提成编辑权限');
    }

    private function validateImportStandards(Request $request): array
    {
        return $request->validate([
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.bom_no' => 'required|string|max:80', 'rows.*.version_token' => 'nullable|string|max:40',
            'rows.*.routing_operation_id' => 'required|integer|min:1',
            'rows.*.setup_minutes' => 'nullable|numeric|min:0|max:999999', 'rows.*.unit_minutes' => 'nullable|numeric|min:0|max:999999',
            'rows.*.performance_mode' => 'nullable|string|max:20', 'rows.*.performance_rate' => 'nullable|numeric|min:0|max:1',
        ])['rows'];
    }

    private function batchStandards(): \App\Services\Erp\BomOperationStandardBatchService
    {
        return app(\App\Services\Erp\BomOperationStandardBatchService::class);
    }

    private function validateBom(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            // 编号只认预占；已提交但响应丢失的同命令重试不能被唯一校验提前拦截。
            'bom_no' => ['nullable', 'string', 'max:80'],
            'client_command_id' => 'sometimes|string|max:120',
            'submit_after_save' => 'sometimes|boolean',
            'set_default' => 'sometimes|boolean',
            'reservation_token' => $id ? 'prohibited' : 'required|uuid',
            'creation_session_id' => $id ? 'prohibited' : 'required|uuid',
            'bom_name' => 'required|string|max:160',
            'product_id' => 'nullable|required_with:sku_id|exists:erp_products,id',
            'sku_id' => 'nullable|required_with:product_id|exists:erp_skus,id',
            'output_item_id' => 'required|exists:erp_items,id',
            'routing_id' => 'nullable|integer|exists:erp_production_routings,id',
            'operation_standards' => 'sometimes|array|max:200',
            'operation_standards.*.routing_operation_id' => 'required|integer|min:1',
            'operation_standards.*.setup_minutes' => 'nullable|numeric|min:0|max:999999',
            'operation_standards.*.unit_minutes' => 'nullable|numeric|min:0|max:999999',
            'operation_standards.*.performance_mode' => 'sometimes|nullable|in:inherit,override,zero',
            'operation_standards.*.performance_rate' => 'sometimes|nullable|numeric|min:0|max:1',
            'position_performance' => 'sometimes|array|max:2000',
            'position_performance.*.position_path' => 'required|string|max:512',
            'position_performance.*.operation_id' => 'required|integer',
            'position_performance.*.occurrence' => 'required|integer|min:1',
            'position_performance.*.performance_mode' => 'required|in:inherit,override,zero',
            'position_performance.*.performance_rate' => 'nullable|numeric|min:0|max:1',
            'bom_type' => 'required|in:standard,custom,trial',
            'version' => 'required|string|max:40',
            'effective_date' => 'nullable|date',
            'expire_date' => 'nullable|date|after_or_equal:effective_date',
            'source_product_id' => 'nullable|exists:erp_products,id',
            'source_sku_id' => 'nullable|exists:erp_skus,id',
            'source_standard_bom_id' => ['nullable', Rule::exists('erp_boms', 'id')->whereNull('deleted_at')],
            'custom_description' => 'nullable|string',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.position_uid' => 'nullable|string|uuid',
            'items.*.child_bom_id' => ['nullable', 'integer', Rule::exists('erp_boms', 'id')->whereNull('deleted_at')],
            'items.*.component_item_id' => 'required|exists:erp_items,id',
            'items.*.cutting_quantity_basis' => 'nullable|in:dimensions_pieces,total_length',
            'items.*.total_length_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.cut_length_mm' => 'nullable|required_with:items.*.piece_qty|numeric|min:0.01|max:9999999999.99',
            'items.*.cut_width_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.cut_thickness_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.allow_cut_rotation' => 'sometimes|boolean',
            'items.*.piece_qty' => 'nullable|required_with:items.*.cut_length_mm|integer|min:1',
            'items.*.stock_dimensions' => 'nullable|array:length_mm,width_mm,thickness_mm,nominal_thickness_mm',
            'items.*.stock_dimensions.length_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.stock_dimensions.width_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.stock_dimensions.thickness_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.stock_dimensions.nominal_thickness_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
            'items.*.qty' => 'required|numeric|min:0',
            'items.*.unit_id' => 'nullable|exists:erp_units,id',
            'items.*.loss_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.fixed_qty' => 'nullable|numeric|min:0',
            'items.*.replaceable' => 'boolean',
            'items.*.remark' => 'nullable|string',
            'items.*.operation_id' => 'nullable|integer|exists:erp_production_operations,id',
        ]);
        $data['product_id'] = $data['product_id'] ?? null;
        $data['sku_id'] = $data['sku_id'] ?? null;
        if (($data['bom_type'] ?? null) !== 'custom') {
            $data['source_product_id'] = null;
            $data['source_sku_id'] = null;
            $data['source_standard_bom_id'] = null;
        }
        abort_if(
            ($data['bom_type'] ?? null) === 'custom'
            && (empty($data['source_product_id']) || empty($data['source_sku_id']) || empty($data['source_standard_bom_id'])),
            422,
            '定制 BOM 必须填写来源商品、来源SKU和来源标准BOM，用于后续追溯。'
        );
        return $data;
    }

    private function authorizePermission(Request $request, string|array $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || array_intersect((array) $permission, $auth->permissionCodes($user)),
            403,
            '无按钮权限：'.implode(' / ', (array) $permission)
        );
        return $user;
    }

    private function authorizeExpansion(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || array_intersect(['bom.expand', 'bom.manage.view', 'bom.manage'], $auth->permissionCodes($user)), 403, '无 BOM 展开算量权限。');
        return $user;
    }

    private function standards(): \App\Services\Erp\BomOperationStandardService
    {
        return app(\App\Services\Erp\BomOperationStandardService::class);
    }

    private function positionPerformance(): \App\Services\Erp\BomPositionPerformanceService
    {
        return app(\App\Services\Erp\BomPositionPerformanceService::class);
    }

    /** 提成权限：超级管理员照常拥有；编辑同时要求查看。 */
    private function canPerformance(Request $request, string $level): bool
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) return false;
        if ($auth->isSuperAdmin($user)) return true;
        $codes = $auth->permissionCodes($user);
        $view = in_array('master.bom.performance.view', $codes, true);
        return $level === 'view' ? $view : $view && in_array('master.bom.performance.edit', $codes, true);
    }

    private function withStandards(Request $request, Bom $bom): Bom
    {
        $bom->setAttribute('operation_standards', $this->standards()->describe($bom, $this->canPerformance($request, 'view')));
        $bom->setAttribute('performance_permissions', ['view' => $this->canPerformance($request, 'view'), 'edit' => $this->canPerformance($request, 'edit')]);
        $bom->setAttribute('position_performance', $this->positionPerformance()->tree($bom, $this->canPerformance($request, 'view')));
        $bom->setAttribute('technical_drawings', app(\App\Services\Erp\TechnicalDrawingSourceService::class)->forSource('bom', $bom->id));
        return app(\App\Services\Erp\BomApplicationService::class)->withApprovalState($bom);
    }

    private function assertProductionTarget(array $payload): void
    {
        app(\App\Services\Erp\BomApplicationService::class)->assertProductionTarget($payload);
    }

    private function assertFactoryBom(Bom $bom): void
    {
        app(\App\Services\Erp\BomApplicationService::class)->assertFactoryBom($bom);
    }

    private function assertNoCycleForCandidate(int $outputItemId, array $items, ?int $ignoreBomId = null): void
    {
        app(\App\Services\Erp\BomApplicationService::class)->assertNoCycleForCandidate($outputItemId, $items, $ignoreBomId);
    }

    private function stockMap($itemIds)
    {
        return InventoryBalance::query()
            ->select('item_id')
            ->selectRaw('COALESCE(SUM(quantity_on_hand),0) as on_hand')
            ->selectRaw('COALESCE(SUM(quantity_available),0) as available')
            ->selectRaw('COALESCE(SUM(quantity_locked),0) as locked')
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->get()
            ->keyBy('item_id');
    }

    private function businessDate(?string $date): Carbon
    {
        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }

    private function versionHistory(Bom $bom)
    {
        return Bom::query()
            ->where('product_id', $bom->product_id)
            ->where('sku_id', $bom->sku_id)
            ->where('output_item_id', $bom->output_item_id)
            ->orderBy('created_at')
            ->get(['id', 'bom_no', 'bom_name', 'version', 'status', 'audit_status', 'is_default', 'created_at', 'updated_at']);
    }

    private function log(Bom $bom, string $action, string $message, ?int $operatorId = null): void
    {
        BomLog::create(['bom_id' => $bom->id, 'action' => $action, 'message' => $message, 'created_by' => $operatorId]);
    }

    private function nextNo(string $prefix): string
    {
        return app(DocumentNumberService::class)->next('bom', $prefix);
    }

    private function nextVersion(string $version): string
    {
        if (preg_match('/^(.*?)(\d+)$/', $version, $m)) {
            return $m[1] . ((int) $m[2] + 1);
        }
        return $version . '-2';
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 10)));
    }
}
