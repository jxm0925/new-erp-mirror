<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\Erp\Bom;
use App\Models\Erp\BomItem;
use App\Models\Erp\BomItemOperation;
use App\Models\Erp\BomLog;
use App\Models\Erp\ProductionRouting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BOM 行用料工序绑定的唯一规则入口。
 *
 * 工单下达时按所用工艺路线的车间工序匹配 BOM 绑定的工序，因此绑定只能选该产出物料
 * 适用路线（草稿或生效）里的车间工序；保存、提交、审核、启用都按同一规则校验，
 * 工序停用时也由这里处理草稿 BOM 上的绑定。
 */
class BomOperationBindingService
{
    /** 产出物料可绑定的工序：适用路线里已启用的车间工序，附带所在路线供用户按情况选择。 */
    public function options(int $outputItemId): Collection
    {
        $routingIds = ProductionRouting::query()->whereIn('status', ['draft', 'active'])
            ->applicableToItem($outputItemId)->pluck('id');
        if ($routingIds->isEmpty()) return collect();
        return DB::table('erp_production_routing_operations as ro')
            ->join('erp_production_routings as r', 'r.id', '=', 'ro.routing_id')
            ->join('erp_production_operations as o', 'o.id', '=', 'ro.operation_id')
            ->whereIn('ro.routing_id', $routingIds)
            ->where('ro.execution_context', 'production')
            ->where('o.status', 'enabled')
            ->orderBy('r.is_default', 'desc')->orderBy('r.id')->orderBy('ro.sequence')
            ->get(['o.id', 'o.operation_no', 'o.operation_name', 'r.id as routing_id', 'r.routing_no', 'r.routing_name', 'r.status as routing_status', 'r.is_default'])
            ->groupBy('id')
            ->map(fn (Collection $rows) => [
                'id' => (int) $rows->first()->id,
                'operation_no' => $rows->first()->operation_no,
                'operation_name' => $rows->first()->operation_name,
                'routings' => $rows->map(fn ($row) => [
                    'id' => (int) $row->routing_id, 'routing_no' => $row->routing_no, 'routing_name' => $row->routing_name,
                    'status' => $row->routing_status, 'is_default' => (bool) $row->is_default,
                ])->unique('id')->values()->all(),
            ])->values();
    }

    /**
     * 校验 BOM 行绑定的工序都在可选范围内。
     *
     * @param  iterable<array{operation_id?: int|null, label?: string}>  $lines
     */
    public function assertBindings(int $outputItemId, iterable $lines, string $field = 'items'): void
    {
        $lines = collect($lines)->filter(fn ($line) => (int) ($line['operation_id'] ?? 0) > 0);
        if ($lines->isEmpty()) return;
        $allowed = $this->options($outputItemId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $errors = [];
        foreach ($lines as $index => $line) {
            if (! in_array((int) $line['operation_id'], $allowed, true)) {
                $errors[$field.'.'.$index.'.operation_id'] = '物料 '.($line['label'] ?? '').' 指定的用料工序已停用或不在该产出物料适用工艺路线的车间工序中，请重新选择。';
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    /** 按已保存 BOM 的明细校验（提交、审核、启用时使用）。 */
    public function assertBomBindings(Bom $bom): void
    {
        $lines = BomItem::query()->where('bom_id', $bom->id)->with('operationBinding')->orderBy('line_no')->get()
            ->map(fn (BomItem $line) => ['operation_id' => $line->operationBinding?->operation_id, 'label' => $line->component_item_name])->all();
        $this->assertBindings((int) $bom->output_item_id, $lines);
    }

    /** 工序在 BOM 中的引用，按 BOM 状态分组，用于停用前提醒和停用、删除保护。 */
    public function referencesForOperation(int $operationId): array
    {
        $rows = DB::table('erp_bom_item_operations as bo')
            ->join('erp_bom_items as bi', 'bi.id', '=', 'bo.bom_item_id')
            ->join('erp_boms as b', 'b.id', '=', 'bi.bom_id')
            ->where('bo.operation_id', $operationId)->whereNull('bi.deleted_at')->whereNull('b.deleted_at')
            ->get(['b.id', 'b.bom_no', 'b.bom_name', 'b.version', 'b.status', 'b.submitted_at', 'bi.component_item_name']);
        $group = fn (string $status) => $rows->where('status', $status)->groupBy('id')->map(fn (Collection $lines) => [
            'id' => (int) $lines->first()->id, 'bom_no' => $lines->first()->bom_no, 'bom_name' => $lines->first()->bom_name,
            'version' => $lines->first()->version, 'submitted' => (bool) $lines->first()->submitted_at,
            'component_item_names' => $lines->pluck('component_item_name')->unique()->values()->all(),
        ])->values()->all();
        return ['active' => $group('active'), 'draft' => $group('draft'), 'inactive' => $group('inactive')];
    }

    /**
     * 工序停用后解除草稿 BOM 上的绑定（该行改按路线规则用料）。已提交审核的草稿内容
     * 变了，原审核不能沿用，同时撤回提交。须在调用方事务内执行。
     */
    public function clearDraftBindings(int $operationId, string $operationName, ?int $operatorId): array
    {
        $bindings = BomItemOperation::query()->from('erp_bom_item_operations as bo')
            ->join('erp_bom_items as bi', 'bi.id', '=', 'bo.bom_item_id')
            ->join('erp_boms as b', 'b.id', '=', 'bi.bom_id')
            ->where('bo.operation_id', $operationId)->whereNull('bi.deleted_at')->whereNull('b.deleted_at')
            ->where('b.status', 'draft')
            ->get(['bo.id', 'bi.bom_id', 'bi.component_item_name']);
        if ($bindings->isEmpty()) return [];
        $boms = Bom::query()->whereIn('id', $bindings->pluck('bom_id')->unique())->orderBy('id')->lockForUpdate()->get();
        BomItemOperation::query()->whereIn('id', $bindings->pluck('id'))->delete();
        foreach ($boms as $bom) {
            $names = $bindings->where('bom_id', $bom->id)->pluck('component_item_name')->unique()->implode('、');
            $withdrawn = (bool) $bom->submitted_at;
            if ($withdrawn) $bom->update(['submitted_at' => null, 'audit_status' => 'pending']);
            $bom->touch();
            BomLog::create(['bom_id' => $bom->id, 'action' => 'operation_unbound', 'created_by' => $operatorId,
                'message' => "工序【{$operationName}】已停用，已解除物料 {$names} 的用料工序绑定，改按工艺路线规则用料".($withdrawn ? '；内容变更，已撤回审核提交，请确认后重新提交。' : '。')]);
        }
        return $boms->map(fn (Bom $bom) => ['id' => (int) $bom->id, 'bom_no' => $bom->bom_no])->all();
    }
}
