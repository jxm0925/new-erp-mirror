<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Bom, BomItem, BomLog, InventoryBalance, Item, Product, Sku};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    private const RELATIONS = [
        'product', 'sku.product', 'outputItem.unit', 'items.componentItem.category', 'items.componentItem.unit',
        'items.unit', 'sourceProduct', 'sourceSku', 'sourceStandardBom', 'logs',
    ];

    public function index(Request $request)
    {
        $query = Bom::with(['product', 'sku.product', 'outputItem.unit'])->latest('updated_at');
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
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function store(Request $request)
    {
        $payload = $this->validateBom($request);
        $this->assertProductionTarget($payload);
        $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items']);
        $operatorId = app(AuthContextService::class)->currentLegacyId($request);
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
            $this->log($bom, 'create', '新增 BOM 草稿');
            return response()->json(['message' => 'BOM 已保存为草稿', 'data' => $bom->fresh(self::RELATIONS)], 201);
        });
    }

    public function show(int $id)
    {
        $bom = Bom::with(self::RELATIONS)->findOrFail($id);
        $bom->setAttribute('version_history', $this->versionHistory($bom));
        return response()->json($bom);
    }

    public function update(Request $request, int $id)
    {
        $bom = Bom::findOrFail($id);
        abort_if($bom->status !== 'draft', 422, '只有草稿 BOM 可以编辑；启用或停用 BOM 请复制为新版本后修改。');
        abort_if($bom->submitted_at, 422, 'BOM 已提交审核，不能继续编辑；如需修改请先驳回或复制新版本。');
        $payload = $this->validateBom($request, $id);
        $this->assertProductionTarget($payload);
        $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items'], $bom->id);
        return DB::transaction(function () use ($bom, $payload) {
            $bom->update($this->mainPayload($payload));
            BomItem::where('bom_id', $bom->id)->delete();
            $this->saveItems($bom, $payload['items']);
            $this->assertNoCycle($bom->fresh(['items.componentItem']));
            $this->log($bom, 'update', '编辑 BOM 草稿');
            return response()->json(['message' => 'BOM 已更新', 'data' => $bom->fresh(self::RELATIONS)]);
        });
    }

    public function submit(int $id)
    {
        $bom = Bom::with('items.componentItem')->findOrFail($id);
        abort_if($bom->status !== 'draft', 422, '只有草稿 BOM 可以提交审核。');
        abort_if($bom->submitted_at, 422, 'BOM 已提交审核，请勿重复提交。');
        abort_if($bom->items->isEmpty(), 422, 'BOM 至少需要一行物料明细。');
        $this->assertNoCycle($bom);
        $bom->update(['audit_status' => 'pending', 'submitted_at' => now()]);
        $this->log($bom, 'submit', '提交审核');
        return response()->json(['message' => 'BOM 已提交审核', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function approve(int $id)
    {
        $bom = Bom::with('items.componentItem')->findOrFail($id);
        abort_if($bom->audit_status !== 'pending' || !$bom->submitted_at, 422, '只有已提交的待审核 BOM 可以审核通过。');
        $this->assertNoCycle($bom);
        $bom->update(['audit_status' => 'approved']);
        $this->log($bom, 'approve', '审核通过');
        return response()->json(['message' => 'BOM 已审核通过', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function reject(int $id)
    {
        $bom = Bom::findOrFail($id);
        abort_if($bom->audit_status !== 'pending' || !$bom->submitted_at, 422, '只有已提交的待审核 BOM 可以驳回。');
        $bom->update(['audit_status' => 'rejected', 'submitted_at' => null]);
        $this->log($bom, 'reject', '审核驳回');
        return response()->json(['message' => 'BOM 已驳回', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function activate(int $id)
    {
        $bom = Bom::with('items.componentItem')->findOrFail($id);
        abort_if($bom->audit_status !== 'approved', 422, 'BOM 审核通过后才能启用。');
        abort_if($bom->status === 'archived', 422, '已归档 BOM 不能启用。');
        $this->assertNoCycle($bom);
        $bom->update(['status' => 'active']);
        $this->log($bom, 'activate', '启用 BOM');
        return response()->json(['message' => 'BOM 已启用', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function deactivate(int $id)
    {
        $bom = Bom::findOrFail($id);
        abort_if($bom->status !== 'active', 422, '只有启用 BOM 可以停用。');
        abort_if($bom->is_default, 422, '默认 BOM 不能直接停用；请先为同一生产对象选择新的默认 BOM。');
        $bom->update(['status' => 'inactive']);
        $this->log($bom, 'deactivate', '停用 BOM');
        return response()->json(['message' => 'BOM 已停用', 'data' => $bom->fresh(self::RELATIONS)]);
    }

    public function setDefault(int $id)
    {
        $bom = Bom::findOrFail($id);
        abort_if(
            $bom->status !== 'active' || $bom->audit_status !== 'approved' || !$this->isEffective($bom, now()),
            422,
            '只有已审核、已启用且在有效期内的 BOM 才能设为默认。'
        );
        abort_if($bom->is_default, 422, '当前 BOM 已是默认，无需重复设置。');
        return DB::transaction(function () use ($bom) {
            $scope = $this->productionScopeQuery(Bom::query(), $bom)->lockForUpdate()->get();
            $scope->where('id', '!=', $bom->id)->each->update(['is_default' => false]);
            $bom->update(['is_default' => true]);
            $this->log($bom, 'set_default', '设为默认 BOM');
            return response()->json(['message' => '默认 BOM 已更新', 'data' => $bom->fresh(self::RELATIONS)]);
        });
    }

    public function copyVersion(Request $request, int $id)
    {
        $source = Bom::with('items')->findOrFail($id);
        $data = $request->validate([
            'version' => 'nullable|string|max:40',
            'bom_name' => 'nullable|string|max:160',
        ]);
        return DB::transaction(function () use ($source, $data) {
            $copy = Bom::create([
                'bom_no' => $this->nextNo('BOM'),
                'bom_name' => $data['bom_name'] ?? ($source->bom_name . ' - 新版本'),
                'product_id' => $source->product_id,
                'sku_id' => $source->sku_id,
                'output_item_id' => $source->output_item_id,
                'bom_type' => $source->bom_type,
                'version' => $data['version'] ?? $this->nextVersion($source->version),
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
            foreach ($source->items as $line) {
                BomItem::create($line->only([
                    'line_no', 'component_item_id', 'component_item_code', 'component_item_name',
                    'qty', 'unit_id', 'loss_rate', 'fixed_qty', 'replaceable', 'remark',
                ]) + ['bom_id' => $copy->id]);
            }
            $this->log($copy, 'copy_version', "由 {$source->bom_no} 复制为新版本");
            return response()->json(['message' => '已复制为新版本草稿', 'data' => $copy->fresh(self::RELATIONS)], 201);
        });
    }

    public function expand(Request $request)
    {
        $data = $request->validate([
            'bom_id' => 'nullable|exists:erp_boms,id',
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
            'product_id' => 'required|exists:erp_products,id',
            'sku_id' => 'required|exists:erp_skus,id',
            'output_item_id' => 'required|exists:erp_items,id',
            'bom_type' => 'required|in:standard,custom,trial',
            'version' => 'required|string|max:40',
            'effective_date' => 'nullable|date',
            'expire_date' => 'nullable|date|after_or_equal:effective_date',
            'source_product_id' => 'nullable|exists:erp_products,id',
            'source_sku_id' => 'nullable|exists:erp_skus,id',
            'source_standard_bom_id' => 'nullable|exists:erp_boms,id',
            'custom_description' => 'nullable|string',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.line_no' => 'nullable|integer|min:1',
            'items.*.component_item_id' => 'required|exists:erp_items,id',
            'items.*.qty' => 'required|numeric|min:0',
            'items.*.unit_id' => 'nullable|exists:erp_units,id',
            'items.*.loss_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.fixed_qty' => 'nullable|numeric|min:0',
            'items.*.replaceable' => 'boolean',
            'items.*.remark' => 'nullable|string',
        ]);
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
        $duplicates = collect($items)->countBy(fn (array $line) => (int) $line['component_item_id'])->filter(fn (int $count) => $count > 1);
        abort_if($duplicates->isNotEmpty(), 422, 'BOM 明细不能包含重复组件 Item。');
        foreach (array_values($items) as $index => $line) {
            abort_if((int) $line['component_item_id'] === (int) $bom->output_item_id, 422, 'BOM 组成物料不能等于产出 Item。');
            $item = Item::with('unit')->findOrFail($line['component_item_id']);
            abort_if(in_array($item->status, ['disabled', 'inactive'], true), 422, '停用物料不能作为 BOM 组成物料。');
            abort_if(!$item->unit || $item->unit->status !== 'enabled', 422, 'BOM 组成 Item 必须维护启用的库存基本单位。');
            $domain = app(\App\Services\Erp\UnitConversionDomainService::class);
            $baseUnit = $domain->canonicalUnit($item->unit);
            $domain->assertUnitPrecision($line['qty'], $baseUnit, 'items.'.$index.'.qty');
            $domain->assertUnitPrecision($line['fixed_qty'] ?? 0, $baseUnit, 'items.'.$index.'.fixed_qty');
            BomItem::create([
                'bom_id' => $bom->id,
                'line_no' => $line['line_no'] ?? (($index + 1) * 10),
                'component_item_id' => $item->id,
                'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name,
                'qty' => $line['qty'],
                'unit_id' => $baseUnit->id,
                'loss_rate' => $line['loss_rate'] ?? 0,
                'fixed_qty' => $line['fixed_qty'] ?? 0,
                'replaceable' => $line['replaceable'] ?? false,
                'remark' => $line['remark'] ?? null,
            ]);
        }
    }

    private function assertProductionTarget(array $payload): void
    {
        $product = Product::whereKey($payload['product_id'])->where('status', 'enabled')->first();
        $sku = Sku::whereKey($payload['sku_id'])->where('status', 'enabled')->first();
        $outputItem = Item::whereKey($payload['output_item_id'])->where('status', 'enabled')->first();
        abort_unless($product && $sku && $outputItem, 422, '归属 Product、关联 SKU 和产出 Item 必须全部启用。');
        abort_if((int) $sku->product_id !== (int) $product->id, 422, '关联 SKU 不属于所选 Product。');
        $validOutput = DB::table('erp_sku_item_relations')
            ->where('sku_id', $sku->id)
            ->where('item_id', $outputItem->id)
            ->where('status', 'active')
            ->where('is_primary', true)
            ->exists();
        abort_unless($validOutput, 422, '产出 Item 必须是该 SKU 当前有效的默认生产/履约 Item。');
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

        foreach ($bom->items as $line) {
            $unitQty = (float) $line->qty;
            $lossRate = (float) $line->loss_rate;
            $fixedQty = (float) $line->fixed_qty;
            $demandQty = round($unitQty * $plannedQty * (1 + $lossRate / 100) + $fixedQty, 4);
            $componentLabel = $line->component_item_name ?: $line->componentItem?->item_name ?: $line->component_item_code;
            $pathLabels = array_merge($labelPath, [$componentLabel]);
            $childBom = $this->findDefaultBom(null, null, (int) $line->component_item_id, $businessDate);

            $tree[] = [
                'level' => $level + 1,
                'path' => implode(' → ', $pathLabels),
                'parent_bom_id' => $bom->id,
                'parent_bom_no' => $bom->bom_no,
                'component_item_id' => $line->component_item_id,
                'component_item_code' => $line->component_item_code,
                'component_item_name' => $componentLabel,
                'unit_name' => $line->unit?->unit_name ?: $line->componentItem?->unit?->unit_name,
                'unit_qty' => $unitQty,
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
                $this->mergeAggregate($aggregate, [
                    'component_item_id' => $line->component_item_id,
                    'component_item_code' => $line->component_item_code,
                    'component_item_name' => $componentLabel,
                    'unit_name' => $line->unit?->unit_name ?: $line->componentItem?->unit?->unit_name,
                    'unit_qty' => $unitQty,
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
        $key = (int) $leaf['component_item_id'];
        if (!isset($aggregate[$key])) {
            $aggregate[$key] = $leaf;
            return;
        }
        $aggregate[$key]['demand_qty'] = round($aggregate[$key]['demand_qty'] + $leaf['demand_qty'], 4);
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

    private function log(Bom $bom, string $action, string $message): void
    {
        BomLog::create(['bom_id' => $bom->id, 'action' => $action, 'message' => $message]);
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
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
