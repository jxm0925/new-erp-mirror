<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Bom, BomItem, BomItemOperation, BomLog, InventoryBalance, Item, Product, Sku};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\BomLifecycleApplicationService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\ItemManagementScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    private const RELATIONS = [
        'product', 'sku.product', 'outputItem.unit', 'items.componentItem.category', 'items.componentItem.unit.standardUnit',
        'items.unit.standardUnit', 'items.entryUnit:id,unit_name,unit_code,decimal_places', 'items.operationBinding.operation:id,operation_no,operation_name', 'sourceProduct', 'sourceSku', 'sourceStandardBom', 'logs',
    ];

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'bom.manage.view');
        $query = Bom::with(['product', 'sku.product', 'outputItem.unit'])->latest('updated_at');
        if ($request->has('ids')) {
            $selected = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'required|integer|min:1|distinct']);
            $query->whereIn('id', $selected['ids']);
        }
        if ($request->input('deleted') === 'only') $query->onlyTrashed();
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
        if ($request->filled('is_default')) {
            $query->where('is_default', filter_var($request->input('is_default'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('date_from')) $query->whereDate('updated_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('updated_at', '<=', $request->input('date_to'));
        $stats = [
            'total' => Bom::count(),
            'active' => Bom::where('status', 'active')->count(),
            'pending' => Bom::where('audit_status', 'pending')->count(),
            'default' => Bom::where('is_default', true)->count(),
        ];
        return response()->json([
            ...$query->orderByDesc('id')->paginate(isset($selected) ? count($selected['ids']) : $this->perPage($request))->toArray(),
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.create');
        $payload = $this->validateBom($request);
        $this->assertProductionTarget($payload);
        $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items']);
        $operatorId = (int) $operator->legacy_id;
        return DB::transaction(function () use ($payload, $operatorId) {
            $numbers = app(DocumentNumberService::class);
            $bomNo = $numbers->reservedNumber($payload['reservation_token'], 'bom', $operatorId, $payload['creation_session_id']);
            $bom = Bom::create($this->mainPayload($payload) + [
                'bom_no' => $bomNo,
                'status' => 'draft',
                'audit_status' => 'pending',
                'submitted_at' => null,
                'is_default' => false,
            ]);
            $this->saveItems($bom, $payload['items']);
            $this->assertNoCycle($bom->fresh(['items.componentItem']));
            $numbers->consume($payload['reservation_token'], 'bom', $bomNo, $operatorId, 'bom', $bom->id);
            $this->log($bom, 'create', '新增 BOM 草稿', $operatorId);
            return response()->json(['message' => 'BOM 已保存为草稿', 'data' => $bom->fresh(self::RELATIONS)], 201);
        }, 5);
    }

    /** BOM 行可选的用料工序：产出物料适用工艺路线里的车间工序。 */
    public function operationOptions(Request $request)
    {
        $this->authorizePermission($request, 'bom.manage.view');
        $data = $request->validate(['output_item_id' => 'required|integer|exists:erp_items,id']);
        return response()->json(['data' => app(\App\Services\Erp\BomOperationBindingService::class)->options((int) $data['output_item_id'])]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'bom.manage.view');
        $query = Bom::with(self::RELATIONS);
        if ($request->input('deleted') === 'only') $query->onlyTrashed();
        $bom = $query->findOrFail($id);
        $bom->setAttribute('version_history', $this->versionHistory($bom));
        return response()->json($bom);
    }

    public function update(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        $payload = $this->validateBom($request, $id);
        return DB::transaction(function () use ($id, $payload, $operator) {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $reopen = $bom->status === 'inactive';
            abort_unless($reopen || $bom->status === 'draft', 422, '只有草稿或停用 BOM 可以编辑。');
            abort_if($bom->submitted_at && (! $reopen || $bom->audit_status === 'pending'), 422, 'BOM 已提交审核，不能继续编辑；如需修改请先驳回或复制新版本。');
            $this->assertProductionTarget($payload);
            $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items'], $bom->id);
            // 停用版本修改后必须重新提交审核，不能沿用修改前的审批结论。
            $state = $reopen ? ['status' => 'draft', 'audit_status' => 'pending', 'submitted_at' => null, 'is_default' => false] : [];
            $bom->update($this->mainPayload($payload) + $state + ['updated_by' => $operator->legacy_id]);
            // 旧明细可能已被下料产出引用，仅软删并以新行保存当前明细。
            BomItem::where('bom_id', $bom->id)->delete();
            $this->saveItems($bom, $payload['items']);
            $this->assertNoCycle($bom->fresh(['items.componentItem']));
            $this->log($bom, 'update', $reopen ? '编辑停用 BOM，已转为草稿，须重新提交审核。' : '编辑 BOM 草稿', (int) $operator->legacy_id);
            return response()->json(['message' => $reopen ? 'BOM 已保存为草稿，请重新提交审核' : 'BOM 已更新', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function submit(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        return DB::transaction(function () use ($id, $operator) {
            // 与编辑、删除和恢复共用主记录锁，避免过期状态在删除后继续流转。
            $bom = Bom::with('items.componentItem')->lockForUpdate()->findOrFail($id);
            abort_if($bom->status !== 'draft', 422, '只有草稿 BOM 可以提交审核。');
            abort_if($bom->submitted_at, 422, 'BOM 已提交审核，请勿重复提交。');
            abort_if($bom->items->isEmpty(), 422, 'BOM 至少需要一行物料明细。');
            $this->assertFactoryBom($bom);
            app(\App\Services\Erp\BomOperationBindingService::class)->assertBomBindings($bom);
            $this->assertNoCycle($bom);
            $bom->update(['audit_status' => 'pending', 'submitted_at' => now()]);
            $this->log($bom, 'submit', '提交审核', (int) $operator->legacy_id);
            return response()->json(['message' => 'BOM 已提交审核', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function approve(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        return DB::transaction(function () use ($id, $operator) {
            $bom = Bom::with('items.componentItem')->lockForUpdate()->findOrFail($id);
            abort_if($bom->audit_status !== 'pending' || !$bom->submitted_at, 422, '只有已提交的待审核 BOM 可以审核通过。');
            $this->assertFactoryBom($bom);
            app(\App\Services\Erp\BomOperationBindingService::class)->assertBomBindings($bom);
            $this->assertNoCycle($bom);
            $bom->update(['audit_status' => 'approved']);
            $this->log($bom, 'approve', '审核通过', (int) $operator->legacy_id);
            return response()->json(['message' => 'BOM 已审核通过', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function reject(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.edit');
        return DB::transaction(function () use ($id, $operator) {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            abort_if($bom->audit_status !== 'pending' || !$bom->submitted_at, 422, '只有已提交的待审核 BOM 可以驳回。');
            $bom->update(['audit_status' => 'rejected', 'submitted_at' => null]);
            $this->log($bom, 'reject', '审核驳回', (int) $operator->legacy_id);
            return response()->json(['message' => 'BOM 已驳回', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function activate(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $operator) {
            $bom = Bom::with('items.componentItem')->lockForUpdate()->findOrFail($id);
            abort_if($bom->audit_status !== 'approved', 422, 'BOM 审核通过后才能启用。');
            abort_if($bom->status === 'archived', 422, '已归档 BOM 不能启用。');
            $this->assertFactoryBom($bom);
            // 停用期间工序或路线可能已调整，启用前按当前路线重新校验用料工序。
            app(\App\Services\Erp\BomOperationBindingService::class)->assertBomBindings($bom);
            $this->assertNoCycle($bom);
            $bom->update(['status' => 'active']);
            $this->log($bom, 'activate', '启用 BOM', (int) $operator->legacy_id);
            return response()->json(['message' => 'BOM 已启用', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function deactivate(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $operator) {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            abort_if($bom->status !== 'active', 422, '只有启用 BOM 可以停用。');
            abort_if($bom->is_default, 422, '默认 BOM 不能直接停用；请先为同一生产对象选择新的默认 BOM。');
            $bom->update(['status' => 'inactive']);
            $this->log($bom, 'deactivate', '停用 BOM', (int) $operator->legacy_id);
            return response()->json(['message' => 'BOM 已停用', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function setDefault(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $operator) {
            $candidate = Bom::findOrFail($id);
            // 同一生产对象按 ID 取锁，状态判断使用取锁后的记录。
            $scope = $this->productionScopeQuery(Bom::query(), $candidate)->orderBy('id')->lockForUpdate()->get();
            $bom = $scope->firstWhere('id', $id);
            abort_unless($bom, 404);
            abort_if(
                $bom->status !== 'active' || $bom->audit_status !== 'approved' || !$this->isEffective($bom, now()),
                422,
                '只有已审核、已启用且在有效期内的 BOM 才能设为默认。'
            );
            abort_if($bom->is_default, 422, '当前 BOM 已是默认，无需重复设置。');
            $this->assertFactoryBom($bom);
            $scope->where('id', '!=', $bom->id)->each->update(['is_default' => false]);
            $bom->update(['is_default' => true]);
            $this->log($bom, 'set_default', '设为默认 BOM', (int) $operator->legacy_id);
            return response()->json(['message' => '默认 BOM 已更新', 'data' => $bom->fresh(self::RELATIONS)]);
        }, 5);
    }

    public function copyVersion(Request $request, int $id)
    {
        $operator = $this->authorizePermission($request, 'bom.manage.create');
        $data = $request->validate([
            'version' => 'nullable|string|max:40',
            'bom_name' => 'nullable|string|max:160',
            'output_item_id' => 'nullable|integer|exists:erp_items,id',
        ]);
        return DB::transaction(function () use ($id, $data, $operator) {
            $source = Bom::with('items.operationBinding')->lockForUpdate()->findOrFail($id);
            $this->assertFactoryBom($source);
            // 指定其他产出物料时为“复制到其他物料”：同类不同规格先复制再改尺寸，不带产品/SKU 限定。
            $targetItemId = (int) ($data['output_item_id'] ?? $source->output_item_id);
            $crossItem = $targetItemId !== (int) $source->output_item_id;
            if ($crossItem) {
                abort_if($source->bom_type === 'custom', 422, '定制 BOM 不能复制到其他物料。');
                abort_if($source->items->contains(fn ($line) => (int) $line->component_item_id === $targetItemId), 422, 'BOM 组成物料不能等于产出物料。');
                $this->assertProductionTarget(['output_item_id' => $targetItemId]);
                $this->assertNoCycleForCandidate($targetItemId, $source->items->map(fn ($line) => ['component_item_id' => (int) $line->component_item_id])->all());
                $targetItem = Item::query()->findOrFail($targetItemId);
            }
            $copy = Bom::create([
                'bom_no' => $this->nextNo('BOM'),
                'bom_name' => $data['bom_name'] ?? ($crossItem ? $targetItem->item_name : $source->bom_name . ' - 新版本'),
                'product_id' => $crossItem ? null : $source->product_id,
                'sku_id' => $crossItem ? null : $source->sku_id,
                'output_item_id' => $targetItemId,
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
            foreach ($source->items as $line) {
                $created = BomItem::create($line->only([
                    'line_no', 'component_item_id', 'component_item_code', 'component_item_name',
                    'cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'allow_cut_rotation', 'piece_qty', 'cutting_quantity_snapshot', 'qty', 'unit_id', 'loss_rate', 'fixed_qty', 'replaceable', 'remark',
                    'entry_unit_id', 'entry_qty', 'entry_factor',
                ]) + ['bom_id' => $copy->id]);
                if (! $line->operationBinding) continue;
                if (in_array((int) $line->operationBinding->operation_id, $allowedOperations, true)) {
                    BomItemOperation::create(['bom_item_id' => $created->id, 'operation_id' => $line->operationBinding->operation_id]);
                } else {
                    $unbound[] = $line->component_item_name;
                }
            }
            $unboundNote = $unbound ? '；物料 '.implode('、', array_unique($unbound)).' 原指定的用料工序不在当前适用路线中，已改按路线规则，请确认' : '';
            $this->log($copy, 'copy_version', ($crossItem ? "由 {$source->bom_no} 复制到物料 {$targetItem->item_code}" : "由 {$source->bom_no} 复制为新版本").$unboundNote, (int) $operator->legacy_id);
            return response()->json(['message' => ($crossItem ? '已复制到所选物料，生成草稿' : '已复制为新版本草稿').$unboundNote, 'data' => $copy->fresh(self::RELATIONS), 'unbound_component_names' => array_values(array_unique($unbound))], 201);
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
        return response()->json(['message' => 'BOM 已恢复', 'data' => $bom->load(self::RELATIONS)]);
    }

    public function expand(Request $request)
    {
        $data = $request->validate([
            'bom_id' => ['nullable', Rule::exists('erp_boms', 'id')->whereNull('deleted_at')],
            'sku_id' => 'nullable|exists:erp_skus,id',
            'product_id' => 'nullable|exists:erp_products,id',
            'output_item_id' => 'nullable|exists:erp_items,id',
            'planned_qty' => 'required|numeric|min:0.0001',
            'business_date' => 'nullable|date',
        ]);
        $businessDate = $this->businessDate($data['business_date'] ?? null);
        $bom = isset($data['bom_id'])
            ? Bom::with(self::RELATIONS)->findOrFail($data['bom_id'])
            : $this->findDefaultBom($data['product_id'] ?? null, $data['sku_id'] ?? null, $data['output_item_id'] ?? null, $businessDate);
        abort_if(!$bom, 422, '未配置有效 BOM。');
        abort_if(!$this->isUsableBom($bom, $businessDate), 422, '只能展开已审核、启用且在有效期内的 BOM。');

        $plannedQty = (float) $data['planned_qty'];
        $expanded = $this->expandBomRecursive($bom->fresh(self::RELATIONS), $plannedQty, $businessDate);
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

    private function validateBom(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'bom_no' => ['nullable', 'string', 'max:80', Rule::unique('erp_boms', 'bom_no')->ignore($id)],
            'reservation_token' => $id ? 'prohibited' : 'required|uuid',
            'creation_session_id' => $id ? 'prohibited' : 'required|uuid',
            'bom_name' => 'required|string|max:160',
            'product_id' => 'nullable|required_with:sku_id|exists:erp_products,id',
            'sku_id' => 'nullable|required_with:product_id|exists:erp_skus,id',
            'output_item_id' => 'required|exists:erp_items,id',
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
            'items.*.entry_unit_id' => 'nullable|integer|min:1',
            'items.*.entry_qty' => 'nullable|numeric|min:0|max:9999999999.9999',
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

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission
        );
        return $user;
    }

    private function mainPayload(array $payload): array
    {
        return collect($payload)->only([
            'bom_name', 'product_id', 'sku_id', 'output_item_id', 'bom_type', 'version',
            'effective_date', 'expire_date', 'source_product_id', 'source_sku_id',
            'source_standard_bom_id', 'custom_description', 'remark',
        ])->all();
    }

    private function saveItems(Bom $bom, array $items): void
    {
        // 用料工序只能选产出物料适用路线里的车间工序，否则工单下达时匹配不到。
        app(\App\Services\Erp\BomOperationBindingService::class)->assertBindings((int) $bom->output_item_id,
            collect($items)->map(fn (array $line) => ['operation_id' => $line['operation_id'] ?? null,
                'label' => $line['component_item_name'] ?? \App\Models\Erp\Item::query()->whereKey($line['component_item_id'] ?? 0)->value('item_name')])->all());
        app(ItemManagementScopeService::class)->assertProductionAllowed(Item::query()->lockForUpdate()->findOrFail($bom->output_item_id), 'output_item_id');
        $duplicates = collect($items)->countBy(function (array $line): string {
            $length = array_key_exists('cut_length_mm', $line) && $line['cut_length_mm'] !== null
                ? number_format((float) $line['cut_length_mm'], 2, '.', '')
                : 'ordinary';
            return ((int) $line['component_item_id']).'|'.$length.'|'.number_format((float) ($line['cut_width_mm'] ?? 0), 2, '.', '').'|'.number_format((float) ($line['cut_thickness_mm'] ?? 0), 2, '.', '');
        })->filter(fn (int $count) => $count > 1);
        abort_if($duplicates->isNotEmpty(), 422, '同一 BOM 中相同物料与相同下料尺寸不能重复；请合并件数。');
        foreach (array_values($items) as $index => $line) {
            abort_if((int) $line['component_item_id'] === (int) $bom->output_item_id, 422, 'BOM 组成物料不能等于产出物料。');
            $item = Item::with('unit')->lockForUpdate()->findOrFail($line['component_item_id']);
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items.'.$index.'.component_item_id');
            abort_if(in_array($item->status, ['disabled', 'inactive'], true), 422, '停用物料不能作为 BOM 组成物料。');
            abort_if(!$item->unit || $item->unit->status !== 'enabled', 422, 'BOM 组成 Item 必须维护启用的库存基本单位。');
            $hasCutLength = array_key_exists('cut_length_mm', $line) && $line['cut_length_mm'] !== null;
            $hasPieceQty = array_key_exists('piece_qty', $line) && $line['piece_qty'] !== null;
            app(\App\Services\Erp\CuttingOutputEligibilityService::class)->validateBomLine($item, $line);
            $cutting = in_array($item->cuttingMode(), ['length', 'sheet'], true);
            if (! $cutting) {
                abort_if($hasCutLength || $hasPieceQty, 422, "普通物料 {$item->item_name} 不能填写下料长度或段数。");
                abort_if(isset($line['total_length_mm']) || ($line['cutting_quantity_basis'] ?? 'dimensions_pieces') !== 'dimensions_pieces', 422, '普通物料不能填写管材总长度。');
                abort_if(! empty($line['stock_dimensions']), 422, '普通物料不能填写下料原料标准尺寸。');
            }
            $domain = app(\App\Services\Erp\UnitConversionDomainService::class);
            $baseUnit = $domain->canonicalUnit($item->unit);
            $quantitySnapshot = $cutting ? app(\App\Services\Erp\CuttingQuantityCalculator::class)->calculate($item, $baseUnit, $line,
                ! empty($line['stock_dimensions']) ? ['dimensions' => $line['stock_dimensions'], 'source' => ['type' => 'bom_explicit']] : null,
                'items.'.$index) : null;
            // The form prefills this exact master standard; normalize provenance only after validating all submitted dimensions.
            if ($item->cuttingMode() === 'length' && ($quantitySnapshot['stock_source']['type'] ?? null) === 'bom_explicit'
                && array_keys($quantitySnapshot['stock_dimensions'] ?? []) === ['length_mm']
                && bccomp((string) ($item->standard_stock_length_mm ?? '0'), '0', 2) > 0
                && bccomp($quantitySnapshot['stock_dimensions']['length_mm'], (string) $item->standard_stock_length_mm, 2) === 0) {
                $quantitySnapshot['stock_source'] = ['type' => 'item_standard_length', 'item_id' => (int) $item->id];
            }
            // 按其他单位录入（如 0.01 卷）：换算统一取物料档案的采购换算，保存时折成库存单位，原录入一并留存显示。
            $entry = ['entry_unit_id' => null, 'entry_qty' => null, 'entry_factor' => null];
            $entryUnitId = (int) ($line['entry_unit_id'] ?? 0);
            if (! $cutting && $entryUnitId && $entryUnitId !== (int) $baseUnit->id) {
                $option = collect($domain->itemUnitOptions([(int) $item->id])[(int) $item->id] ?? [])->firstWhere('unit_id', $entryUnitId);
                abort_unless($option, 422, "物料 {$item->item_name} 没有所选单位的换算，请先在物料档案维护“采购换算”。");
                $entryQty = round((float) ($line['entry_qty'] ?? 0), 4);
                $line['qty'] = round($entryQty * $option['factor'], 4);
                $entry = ['entry_unit_id' => $entryUnitId, 'entry_qty' => $entryQty, 'entry_factor' => $option['factor']];
            }
            if (! $cutting) {
                $domain->assertUnitPrecision($line['qty'], $baseUnit, 'items.'.$index.'.qty');
                $domain->assertUnitPrecision($line['fixed_qty'] ?? 0, $baseUnit, 'items.'.$index.'.fixed_qty');
            } else {
                // 根/张基本单位用于整件库存；BOM 理论份额允许小数，固定耗用
                // 仍受已有 DECIMAL(14,4) 存储边界约束，不能静默截断。
                $fixed = (float) ($line['fixed_qty'] ?? 0);
                abort_if(abs($fixed - round($fixed, 4)) > 0.00000001 || $fixed > 9999999999.9999, 422, '下料固定用量最多保留四位小数。');
            }
            $operationId = (int) ($line['operation_id'] ?? 0);
            $created = BomItem::create([
                'bom_id' => $bom->id,
                'line_no' => $line['line_no'] ?? (($index + 1) * 10),
                'component_item_id' => $item->id,
                'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name,
                'cut_length_mm' => $hasCutLength ? $line['cut_length_mm'] : null,
                'cut_width_mm' => $line['cut_width_mm'] ?? null,
                'cut_thickness_mm' => $line['cut_thickness_mm'] ?? null,
                'allow_cut_rotation' => $line['allow_cut_rotation'] ?? false,
                'piece_qty' => $hasPieceQty ? $line['piece_qty'] : null,
                'qty' => $quantitySnapshot['stored_qty'] ?? $line['qty'],
                'cutting_quantity_snapshot' => $quantitySnapshot,
                'unit_id' => $baseUnit->id,
                'loss_rate' => $line['loss_rate'] ?? 0,
                'fixed_qty' => $line['fixed_qty'] ?? 0,
                'replaceable' => $line['replaceable'] ?? false,
                'remark' => $line['remark'] ?? null,
            ] + $entry);
            if ($operationId) BomItemOperation::create(['bom_item_id' => $created->id, 'operation_id' => $operationId]);
        }
    }

    private function assertProductionTarget(array $payload): void
    {
        $productId = $payload['product_id'] ?? null;
        $skuId = $payload['sku_id'] ?? null;
        $outputItem = Item::whereKey($payload['output_item_id'])->where('status', 'enabled')->first();
        if ($outputItem) app(ItemManagementScopeService::class)->assertProductionAllowed($outputItem, 'output_item_id');

        abort_if(
            ($productId === null) !== ($skuId === null),
            422,
            'Product 与 SKU 必须同时关联，或同时留空。'
        );

        if ($productId === null) {
            abort_unless(
                $outputItem && $outputItem->is_production_item,
                422,
                'Item-only BOM 的产出物料必须已启用且可用于生产。'
            );

            return;
        }

        $product = Product::whereKey($productId)->where('status', 'enabled')->first();
        $sku = Sku::whereKey($skuId)->where('status', 'enabled')->first();
        abort_unless($product && $sku && $outputItem, 422, '归属 Product、关联 SKU 和产出物料必须全部启用。');
        abort_if((int) $sku->product_id !== (int) $product->id, 422, '关联 SKU 不属于所选 Product。');
        $validOutput = DB::table('erp_sku_item_relations')
            ->where('sku_id', $sku->id)
            ->where('item_id', $outputItem->id)
            ->where('status', 'active')
            ->where('is_primary', true)
            ->exists();
        abort_unless($validOutput, 422, '产出物料必须是该 SKU 当前有效的默认生产/履约物料。');
    }

    private function assertFactoryBom(Bom $bom): void
    {
        $ids = array_merge([(int) $bom->output_item_id], $bom->items->pluck('component_item_id')->all());
        foreach (Item::query()->whereIn('id', $ids)->get() as $item) {
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items');
        }
    }

    private function expandBomRecursive(Bom $bom, float $plannedQty, Carbon $businessDate, array $itemPath = [], array $labelPath = [], int $level = 0): array
    {
        $currentLabel = $bom->outputItem?->item_name ?: $bom->outputItem?->item_code ?: $bom->bom_no;
        if (in_array((int) $bom->output_item_id, $itemPath, true)) {
            $start = array_search((int) $bom->output_item_id, $itemPath, true);
            $cycle = array_slice($labelPath, $start);
            $cycle[] = $currentLabel;
            abort(422, '循环引用：' . implode(' → ', $cycle));
        }
        $itemPath[] = (int) $bom->output_item_id;
        $labelPath[] = $currentLabel;
        $tree = [];
        $aggregate = [];
        $resolved = app(\App\Services\Erp\LengthCutRequirementService::class)->resolveBomLines($bom, null)->keyBy('id');

        foreach ($bom->items as $line) {
            $calculation = $resolved->get((int) $line->id)['cutting_quantity_snapshot'] ?? null;
            $totalLength = ($calculation['quantity_basis'] ?? null) === 'total_length';
            $lengthFacts = ['cutting_quantity_basis' => $totalLength ? 'total_length' : 'dimensions_pieces',
                'total_length_mm' => $totalLength ? $calculation['total_length_mm'] : null,
                'required_total_length_mm' => $totalLength ? bcmul((string) $calculation['total_length_mm'], (string) $plannedQty, 8) : null];
            $unitQty = (float) ($calculation['theoretical_qty'] ?? $line->qty);
            $lossRate = (float) $line->loss_rate;
            $fixedQty = (float) $line->fixed_qty;
            $demandQty = round($unitQty * $plannedQty * (1 + $lossRate / 100) + $fixedQty, $calculation ? 8 : 4);
            $componentLabel = $line->component_item_name ?: $line->componentItem?->item_name ?: $line->component_item_code;
            $pathLabels = array_merge($labelPath, [$componentLabel]);
            $childBom = $this->findDefaultBom(null, null, (int) $line->component_item_id, $businessDate);

            $tree[] = $lengthFacts + [
                'level' => $level + 1,
                'path' => implode(' → ', $pathLabels),
                'parent_bom_id' => $bom->id,
                'parent_bom_no' => $bom->bom_no,
                'component_item_id' => $line->component_item_id,
                'component_item_code' => $line->component_item_code,
                'component_item_name' => $componentLabel,
                'unit_name' => $line->unit?->unit_name ?: $line->componentItem?->unit?->unit_name,
                'unit_qty' => $unitQty,
                'cut_length_mm' => $line->cut_length_mm === null ? null : (float) $line->cut_length_mm,
                'cut_width_mm' => $line->cut_width_mm === null ? null : (float) $line->cut_width_mm,
                'cut_thickness_mm' => $line->cut_thickness_mm === null ? null : (float) $line->cut_thickness_mm,
                'piece_qty' => $line->piece_qty === null ? null : (int) $line->piece_qty,
                'required_piece_qty' => $line->piece_qty === null ? null : round((float) $line->piece_qty * $plannedQty, 4),
                'loss_rate' => $lossRate,
                'fixed_qty' => $fixedQty,
                'demand_qty' => $demandQty,
                'is_leaf' => !$childBom,
                'child_bom_id' => $childBom?->id,
                'child_bom_no' => $childBom?->bom_no,
                'replaceable' => (bool) $line->replaceable,
                'remark' => $line->remark,
            ];

            if ($childBom) {
                $child = $this->expandBomRecursive($childBom, $demandQty, $businessDate, $itemPath, $labelPath, $level + 1);
                $tree = array_merge($tree, $child['tree_lines']);
                foreach ($child['aggregate_lines'] as $leaf) {
                    $this->mergeAggregate($aggregate, $leaf);
                }
            } else {
                $this->mergeAggregate($aggregate, $lengthFacts + [
                    'component_item_id' => $line->component_item_id,
                    'component_item_code' => $line->component_item_code,
                    'component_item_name' => $componentLabel,
                    'unit_name' => $line->unit?->unit_name ?: $line->componentItem?->unit?->unit_name,
                    'unit_qty' => $unitQty,
                    'cut_length_mm' => $line->cut_length_mm === null ? null : (float) $line->cut_length_mm,
                    'cut_width_mm' => $line->cut_width_mm === null ? null : (float) $line->cut_width_mm,
                    'cut_thickness_mm' => $line->cut_thickness_mm === null ? null : (float) $line->cut_thickness_mm,
                    'piece_qty' => $line->piece_qty === null ? null : (int) $line->piece_qty,
                    'required_piece_qty' => $line->piece_qty === null ? null : round((float) $line->piece_qty * $plannedQty, 4),
                    'loss_rate' => $lossRate,
                    'fixed_qty' => $fixedQty,
                    'demand_qty' => $demandQty,
                    'replaceable' => (bool) $line->replaceable,
                    'remark' => $line->remark,
                    'paths' => [implode(' → ', $pathLabels)],
                ]);
            }
        }
        return ['tree_lines' => $tree, 'aggregate_lines' => array_values($aggregate)];
    }

    private function mergeAggregate(array &$aggregate, array $leaf): void
    {
        $lengthKey = ($leaf['cutting_quantity_basis'] ?? null) === 'total_length' ? 'total_length' : ($leaf['cut_length_mm'] === null ? 'ordinary' : number_format((float) $leaf['cut_length_mm'], 2, '.', ''));
        $key = ((int) $leaf['component_item_id']).'|'.$lengthKey.'|'.number_format((float) ($leaf['cut_width_mm'] ?? 0), 2, '.', '').'|'.number_format((float) ($leaf['cut_thickness_mm'] ?? 0), 2, '.', '');
        if (!isset($aggregate[$key])) {
            $aggregate[$key] = $leaf;
            return;
        }
        $totalLength = ($leaf['cutting_quantity_basis'] ?? null) === 'total_length';
        $aggregate[$key]['demand_qty'] = round($aggregate[$key]['demand_qty'] + $leaf['demand_qty'], $totalLength ? 8 : 4);
        if ($totalLength) {
            $aggregate[$key]['required_total_length_mm'] = bcadd((string) $aggregate[$key]['required_total_length_mm'], (string) $leaf['required_total_length_mm'], 8);
            $aggregate[$key]['total_length_mm'] = null;
        }
        if (($leaf['required_piece_qty'] ?? null) !== null) {
            $aggregate[$key]['required_piece_qty'] = round(
                (float) ($aggregate[$key]['required_piece_qty'] ?? 0) + (float) $leaf['required_piece_qty'],
                4
            );
        }
        $aggregate[$key]['paths'] = array_values(array_unique(array_merge($aggregate[$key]['paths'] ?? [], $leaf['paths'] ?? [])));
        $aggregate[$key]['replaceable'] = $aggregate[$key]['replaceable'] || ($leaf['replaceable'] ?? false);
    }

    private function assertNoCycle(Bom $bom): void
    {
        $this->detectCycleFromBom($bom->fresh(['outputItem', 'items.componentItem']), [], []);
    }

    private function assertNoCycleForCandidate(int $outputItemId, array $items, ?int $ignoreBomId = null): void
    {
        $rootLabel = $this->itemLabel($outputItemId);
        foreach ($items as $line) {
            $componentItemId = (int) $line['component_item_id'];
            $this->detectCycleFromCandidateItem(
                $componentItemId,
                $outputItemId,
                [$rootLabel, $this->itemLabel($componentItemId)],
                $ignoreBomId,
                []
            );
        }
    }

    private function detectCycleFromCandidateItem(int $currentItemId, int $targetItemId, array $labelPath, ?int $ignoreBomId, array $visited): void
    {
        if ($currentItemId === $targetItemId) {
            abort(422, '循环引用：' . implode(' → ', $labelPath));
        }

        $visitKey = $currentItemId . ':' . $targetItemId;
        if (isset($visited[$visitKey])) {
            return;
        }
        $visited[$visitKey] = true;

        $children = Bom::with(['outputItem', 'items.componentItem'])
            ->where('output_item_id', $currentItemId)
            ->when($ignoreBomId, fn ($q) => $q->whereKeyNot($ignoreBomId))
            ->get();

        foreach ($children as $childBom) {
            foreach ($childBom->items as $line) {
                $nextItemId = (int) $line->component_item_id;
                $this->detectCycleFromCandidateItem(
                    $nextItemId,
                    $targetItemId,
                    array_merge($labelPath, [$this->itemLabel($nextItemId, $line->component_item_name ?: $line->component_item_code)]),
                    $ignoreBomId,
                    $visited
                );
            }
        }
    }

    private function detectCycleFromBom(Bom $bom, array $itemPath, array $labelPath): void
    {
        $label = $bom->outputItem?->item_name ?: $bom->outputItem?->item_code ?: $bom->bom_no;
        if (in_array((int) $bom->output_item_id, $itemPath, true)) {
            $start = array_search((int) $bom->output_item_id, $itemPath, true);
            $cycle = array_slice($labelPath, $start);
            $cycle[] = $label;
            abort(422, '循环引用：' . implode(' → ', $cycle));
        }
        $itemPath[] = (int) $bom->output_item_id;
        $labelPath[] = $label;
        foreach ($bom->items as $line) {
            $children = Bom::with(['outputItem', 'items.componentItem'])
                ->where('output_item_id', $line->component_item_id)
                ->when($bom->id, fn ($q) => $q->whereKeyNot($bom->id))
                ->get();
            foreach ($children as $child) {
                $this->detectCycleFromBom($child, $itemPath, $labelPath);
            }
        }
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

    private function itemLabel(int $itemId, ?string $fallback = null): string
    {
        $item = Item::query()->find($itemId);
        return $item?->item_name ?: $item?->item_code ?: $fallback ?: ('Item#' . $itemId);
    }

    private function findDefaultBom(?int $productId, ?int $skuId, ?int $outputItemId, ?Carbon $businessDate = null): ?Bom
    {
        $businessDate ??= now();
        return Bom::with(self::RELATIONS)
            ->where('status', 'active')
            ->where('audit_status', 'approved')
            ->where('is_default', true)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($skuId !== null, fn ($q) => $q->where('sku_id', $skuId))
            ->when($outputItemId !== null, fn ($q) => $q->where('output_item_id', $outputItemId))
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', $businessDate))
            ->where(fn ($q) => $q->whereNull('expire_date')->orWhereDate('expire_date', '>=', $businessDate))
            ->first();
    }

    private function isUsableBom(Bom $bom, Carbon $businessDate): bool
    {
        return $bom->status === 'active' && $bom->audit_status === 'approved' && $this->isEffective($bom, $businessDate);
    }

    private function isEffective(Bom $bom, Carbon $businessDate): bool
    {
        $effectiveOk = !$bom->effective_date || Carbon::parse($bom->effective_date)->startOfDay()->lte($businessDate);
        $expireOk = !$bom->expire_date || Carbon::parse($bom->expire_date)->endOfDay()->gte($businessDate);
        return $effectiveOk && $expireOk;
    }

    private function businessDate(?string $date): Carbon
    {
        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }

    private function productionScopeQuery(Builder $query, Bom $bom): Builder
    {
        return $query
            ->where('product_id', $bom->product_id)
            ->where('sku_id', $bom->sku_id)
            ->where('output_item_id', $bom->output_item_id);
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
