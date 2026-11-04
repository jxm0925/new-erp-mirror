<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomLog;
use App\Models\Erp\BomOperationStandard;
use App\Models\Erp\ProductionPerformanceTemplate;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\ProductionRoutingOperation;
use Illuminate\Support\Facades\DB;

/**
 * BOM 工时提成的批量设置与导入导出：
 * 批量预览/应用按“提成模板或另一份 BOM”把值写进目标草稿 BOM 的节点设置；
 * 导出给每道节点一行（含版本令牌），导入按令牌校验后覆盖写入，全部落同一张节点设置表。
 */
final class BomOperationStandardBatchService
{
    /** @return array{data: array, summary: array} */
    public function preview(array $input): array
    {
        return $this->stripWrites($this->compute($this->source($input), $input));
    }

    /** @return array{data: array, summary: array} */
    public function apply(array $input, object $operator): array
    {
        return DB::transaction(function () use ($input, $operator) {
            $boms = Bom::query()->whereIn('id', $input['bom_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $this->source($input);
            $result = $this->compute($source, $input, $boms);
            $written = 0;
            foreach ($result['data'] as $row) {
                if ($row['skipped_reason'] || ! $row['writes']) continue;
                $bom = $boms->get((int) $row['bom_id']);
                if (! $bom) continue;
                $this->write($bom, $row['writes'], $operator);
                $bom->touch();
                BomLog::create(['bom_id' => $bom->id, 'action' => 'batch_standards',
                    'message' => sprintf('批量设置工时提成（来源%s）：%d 项字段改动', $source['label'], count($row['changes'])),
                    'created_by' => (int) ($operator->legacy_id ?? 0) ?: null]);
                $written++;
            }
            $result['summary']['boms_written'] = $written;
            return $this->stripWrites($result);
        }, 5);
    }

    /** 导出：每道节点一行，附带 BOM 更新时间作为版本令牌；无查看权限时不返回比例字段。 */
    public function export(array $bomIds, bool $canView): array
    {
        $boms = Bom::query()->whereIn('id', $bomIds)->orderBy('id')->get()->keyBy('id');
        $routingIds = $boms->pluck('routing_id')->filter()->all();
        $routings = ProductionRouting::query()->whereIn('id', $routingIds ?: [0])->get()->keyBy('id');
        $nodes = ProductionRoutingOperation::query()->with('operation')->whereIn('routing_id', $routingIds ?: [0])->orderBy('sequence')->get()->groupBy('routing_id');
        $saved = BomOperationStandard::query()->whereIn('bom_id', $bomIds)->get()->groupBy('bom_id');
        $rows = [];
        $skipped = [];
        foreach ($bomIds as $bomId) {
            $bom = $boms->get((int) $bomId);
            if (! $bom) continue;
            if (! $bom->routing_id) { $skipped[] = ['bom_no' => $bom->bom_no, 'reason' => '未选工艺路线']; continue; }
            $routing = $routings->get((int) $bom->routing_id);
            $savedMap = ($saved->get($bom->id) ?? collect())->keyBy('routing_operation_id');
            foreach ($nodes->get((int) $bom->routing_id) ?? [] as $node) {
                $s = $savedMap->get($node->id);
                $row = [
                    'bom_id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name,
                    'version_token' => $bom->updated_at?->format('Y-m-d H:i:s'),
                    'routing_no' => $routing?->routing_no, 'routing_version' => $routing?->version,
                    'routing_operation_id' => (int) $node->id, 'sequence' => (int) $node->sequence,
                    'operation_name' => $node->operation?->operation_name,
                    'execution_context' => $node->execution_context ?: 'production',
                    'setup_minutes' => $s && $s->setup_minutes !== null ? (float) $s->setup_minutes : null,
                    'unit_minutes' => $s && $s->unit_minutes !== null ? (float) $s->unit_minutes : null,
                ];
                if ($canView) $row += [
                    'performance_mode' => $s?->performance_mode ?: 'inherit',
                    'performance_rate' => $s && $s->performance_rate !== null ? (float) $s->performance_rate : null,
                ];
                $rows[] = $row;
            }
        }
        return ['data' => $rows, 'skipped' => $skipped];
    }

    /** @return array{data: array, summary: array} */
    public function importPreview(array $rows, bool $canEditRate): array
    {
        return $this->stripWrites($this->checkImport($rows, $canEditRate));
    }

    /** @return array{ok: bool, data: array, summary: array} */
    public function importApply(array $rows, bool $canEditRate, object $operator): array
    {
        return DB::transaction(function () use ($rows, $canEditRate, $operator) {
            $bomNos = collect($rows)->pluck('bom_no')->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();
            $bomIds = Bom::query()->whereIn('bom_no', $bomNos)->pluck('id')->all();
            $boms = Bom::query()->whereIn('id', $bomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $result = $this->checkImport($rows, $canEditRate);
            if ($result['summary']['errors'] > 0) return ['ok' => false] + $this->stripWrites($result);
            $writtenBoms = [];
            foreach ($result['data'] as $row) {
                if ($row['status'] !== 'changed') continue;
                $bom = $boms->get((int) $row['bom_id']);
                if (! $bom) continue;
                $this->write($bom, $row['writes'], $operator);
                $writtenBoms[(int) $bom->id] = $bom;
            }
            foreach ($writtenBoms as $bom) {
                $bom->touch();
                BomLog::create(['bom_id' => $bom->id, 'action' => 'import_standards',
                    'message' => sprintf('导入工时提成：%d 行有改动', collect($result['data'])->where('bom_id', $bom->id)->where('status', 'changed')->count()),
                    'created_by' => (int) ($operator->legacy_id ?? 0) ?: null]);
            }
            return ['ok' => true] + $this->stripWrites($result);
        }, 5);
    }

    private function source(array $input): array
    {
        if ($input['source_type'] === 'template') {
            $template = ProductionPerformanceTemplate::query()->with('lines')->findOrFail((int) $input['template_id']);
            if ($template->status !== 'enabled') throw new WorkOrderDomainException('template_disabled', '来源提成模板已停用，不能作为来源。', 422);
            return ['type' => 'template', 'label' => '提成模板「'.$template->template_name.'」',
                'rates' => $template->lines->mapWithKeys(fn ($l) => [(int) $l->operation_id => (float) $l->performance_rate])->all()];
        }
        $bom = Bom::query()->findOrFail((int) $input['source_bom_id']);
        if (! $bom->routing_id) throw new WorkOrderDomainException('source_no_routing', '来源 BOM 未选工艺路线。', 422);
        return ['type' => 'bom', 'label' => 'BOM '.$bom->bom_no.'「'.$bom->bom_name.'」', 'bom_id' => (int) $bom->id, 'routing_id' => (int) $bom->routing_id,
            'standards' => BomOperationStandard::query()->where('bom_id', $bom->id)->get()->mapWithKeys(fn ($s) => [(int) $s->routing_operation_id => [
                'setup_minutes' => $s->setup_minutes !== null ? (float) $s->setup_minutes : null,
                'unit_minutes' => $s->unit_minutes !== null ? (float) $s->unit_minutes : null,
                'performance_mode' => $s->performance_mode ?: 'inherit',
                'performance_rate' => $s->performance_rate !== null ? (float) $s->performance_rate : null]])->all()];
    }

    /** 计算每个目标 BOM 每道节点的改动；writes 按 routing_operation_id 保存整行新值供 apply 写入。 */
    private function compute(array $source, array $input, $boms = null): array
    {
        $withMinutes = $source['type'] === 'bom' && in_array('minutes', $input['scope'], true);
        $withRates = in_array('rates', $input['scope'], true);
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $boms = $boms ?? Bom::query()->whereIn('id', $input['bom_ids'])->orderBy('id')->get()->keyBy('id');
        $existing = BomOperationStandard::query()->whereIn('bom_id', $input['bom_ids'])->get()->groupBy('bom_id');
        $nodesByRouting = ProductionRoutingOperation::query()->with('operation')
            ->whereIn('routing_id', $boms->pluck('routing_id')->filter()->all() ?: [0])->orderBy('sequence')->get()->groupBy('routing_id');
        $rows = [];
        $bomsChanged = $nodesChanged = $skipped = 0;
        foreach ($input['bom_ids'] as $bomId) {
            $bom = $boms->get((int) $bomId);
            if (! $bom) {
                $skipped++;
                $rows[] = ['bom_id' => (int) $bomId, 'bom_no' => null, 'bom_name' => null, 'skipped_reason' => 'BOM 不存在', 'changes' => [], 'writes' => []];
                continue;
            }
            $reason = ! $bom->routing_id ? '未选工艺路线'
                : ($bom->status !== 'draft' || $bom->submitted_at ? '只有未提交的草稿 BOM 可以批量修改'
                : ($source['type'] === 'bom' && (int) $bom->id === $source['bom_id'] ? '来源 BOM 本身不参与批量修改'
                : ($source['type'] === 'bom' && (int) $bom->routing_id !== $source['routing_id'] ? '与来源 BOM 路线不同' : null)));
            if ($reason) {
                $skipped++;
                $rows[] = ['bom_id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'skipped_reason' => $reason, 'changes' => [], 'writes' => []];
                continue;
            }
            $saved = ($existing->get($bom->id) ?? collect())->keyBy('routing_operation_id');
            $changes = [];
            $writes = [];
            $touched = [];
            foreach ($nodesByRouting->get((int) $bom->routing_id) ?? [] as $node) {
                $old = $saved->get($node->id);
                $cur = [
                    'setup_minutes' => $old && $old->setup_minutes !== null ? (float) $old->setup_minutes : null,
                    'unit_minutes' => $old && $old->unit_minutes !== null ? (float) $old->unit_minutes : null,
                    'performance_mode' => $old?->performance_mode ?: 'inherit',
                    'performance_rate' => $old && $old->performance_rate !== null ? (float) $old->performance_rate : null,
                ];
                $new = $cur;
                $src = null;
                if ($source['type'] === 'bom') {
                    $src = $source['standards'][(int) $node->id]
                        ?? ['setup_minutes' => null, 'unit_minutes' => null, 'performance_mode' => 'inherit', 'performance_rate' => null];
                }
                if ($withMinutes && $src) {
                    foreach (['setup_minutes', 'unit_minutes'] as $field) {
                        $to = $src[$field];
                        if (! $overwrite && $cur[$field] !== null) continue;
                        if ($cur[$field] === $to) continue;
                        $new[$field] = $to;
                        $changes[] = ['routing_operation_id' => (int) $node->id, 'operation_name' => $node->operation?->operation_name,
                            'field' => $field, 'from' => $cur[$field], 'to' => $to];
                    }
                }
                if ($withRates) {
                    [$toMode, $toRate] = $source['type'] === 'template'
                        ? (isset($source['rates'][(int) $node->operation_id]) ? ['override', $source['rates'][(int) $node->operation_id]] : [null, null])
                        : [$src['performance_mode'], $src['performance_rate']];
                    if ($toMode !== null && ($overwrite || $cur['performance_mode'] === 'inherit')
                        && ($cur['performance_mode'] !== $toMode || $cur['performance_rate'] !== $toRate)) {
                        $changes[] = ['routing_operation_id' => (int) $node->id, 'operation_name' => $node->operation?->operation_name,
                            'field' => 'performance', 'from' => ['mode' => $cur['performance_mode'], 'rate' => $cur['performance_rate']],
                            'to' => ['mode' => $toMode, 'rate' => $toMode === 'override' ? $toRate : null]];
                        $new['performance_mode'] = $toMode;
                        $new['performance_rate'] = $toMode === 'override' ? $toRate : null;
                    }
                }
                if ($new !== $cur) { $writes[(int) $node->id] = $new; $touched[(int) $node->id] = true; }
            }
            if ($changes) { $bomsChanged++; $nodesChanged += count($touched); }
            $rows[] = ['bom_id' => (int) $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'skipped_reason' => null, 'changes' => $changes, 'writes' => $writes];
        }
        return ['data' => $rows, 'summary' => ['boms_changed' => $bomsChanged, 'nodes_changed' => $nodesChanged, 'skipped' => $skipped]];
    }

    private function checkImport(array $rows, bool $canEditRate): array
    {
        $boms = Bom::query()->whereIn('bom_no', collect($rows)->pluck('bom_no')->map(fn ($v) => trim((string) $v))->filter()->all())->get()->keyBy('bom_no');
        $saved = BomOperationStandard::query()->whereIn('bom_id', $boms->pluck('id')->all())->get()->groupBy('bom_id');
        $nodesByRouting = ProductionRoutingOperation::query()->with('operation')
            ->whereIn('routing_id', $boms->pluck('routing_id')->filter()->all() ?: [0])->get()->groupBy('routing_id');
        $seen = [];
        $out = [];
        $changed = $unchanged = $errors = 0;
        foreach (array_values($rows) as $i => $row) {
            $bomNo = trim((string) ($row['bom_no'] ?? ''));
            $nodeId = (int) ($row['routing_operation_id'] ?? 0);
            $base = ['bom_no' => $bomNo, 'routing_operation_id' => $nodeId, 'status' => 'error', 'message' => '', 'changes' => [], 'writes' => []];
            $bom = $boms->get($bomNo);
            if (! $bom) $err = 'BOM 不存在';
            elseif ($bom->status !== 'draft' || $bom->submitted_at) $err = '只有未提交的草稿 BOM 可以导入修改';
            elseif (trim((string) ($row['version_token'] ?? '')) !== (string) $bom->updated_at?->format('Y-m-d H:i:s')) $err = '该 BOM 导出后已被修改，请重新导出';
            elseif (isset($seen[$bom->id.':'.$nodeId])) $err = '同一节点出现多行';
            else $err = null;
            if ($err === null) {
                $seen[$bom->id.':'.$nodeId] = true;
                $node = ($nodesByRouting->get((int) $bom->routing_id) ?? collect())->firstWhere('id', $nodeId);
                if (! $node) $err = '节点不属于该 BOM 的工艺路线';
            }
            if ($err === null) {
                foreach (['setup_minutes', 'unit_minutes'] as $key) {
                    $v = $row[$key] ?? null;
                    if ($v !== null && $v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 999999)) { $err = '工时必须是 0 或正数（不超过 999999 分钟）'; break; }
                }
            }
            if ($err === null) {
                $mode = $row['performance_mode'] ?? null;
                $rate = $row['performance_rate'] ?? null;
                if ($mode !== null && $mode !== '' && ! in_array($mode, BomOperationStandard::MODES, true)) $err = '提成方式不正确';
                elseif ($mode === 'override' && (! is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1)) $err = '指定比例必须大于 0% 且不超过 100%';
            }
            if ($err !== null) { $errors++; $out[] = array_replace($base, ['message' => $err]); continue; }
            $base['bom_id'] = (int) $bom->id;
            $base['bom_name'] = $bom->bom_name;
            $base['operation_name'] = $node->operation?->operation_name;
            $old = ($saved->get($bom->id) ?? collect())->firstWhere('routing_operation_id', $nodeId);
            $cur = [
                'setup_minutes' => $old && $old->setup_minutes !== null ? (float) $old->setup_minutes : null,
                'unit_minutes' => $old && $old->unit_minutes !== null ? (float) $old->unit_minutes : null,
                'performance_mode' => $old?->performance_mode ?: 'inherit',
                'performance_rate' => $old && $old->performance_rate !== null ? (float) $old->performance_rate : null,
            ];
            $new = $cur;
            $changes = [];
            foreach (['setup_minutes', 'unit_minutes'] as $key) {
                $v = $row[$key] ?? null;
                $to = $v === null || $v === '' ? null : (float) $v;
                if ($cur[$key] === $to) continue;
                $new[$key] = $to;
                $changes[] = ['field' => $key, 'from' => $cur[$key], 'to' => $to];
            }
            $hasRateField = ($row['performance_mode'] ?? null) !== null && $row['performance_mode'] !== '' || ($row['performance_rate'] ?? null) !== null && $row['performance_rate'] !== '';
            if ($hasRateField) {
                $toMode = $mode !== null && $mode !== '' ? $mode : $cur['performance_mode'];
                $toRate = $toMode === 'override' ? (float) $rate : null;
                if ($cur['performance_mode'] !== $toMode || $cur['performance_rate'] !== $toRate) {
                    if (! $canEditRate) { $errors++; $out[] = array_replace($base, ['status' => 'error', 'message' => '没有提成编辑权限']); continue; }
                    $new['performance_mode'] = $toMode;
                    $new['performance_rate'] = $toRate;
                    $changes[] = ['field' => 'performance', 'from' => ['mode' => $cur['performance_mode'], 'rate' => $cur['performance_rate']], 'to' => ['mode' => $toMode, 'rate' => $toRate]];
                }
            }
            if ($changes) { $changed++; $out[] = array_replace($base, ['status' => 'changed', 'changes' => $changes, 'writes' => [$nodeId => $new]]); }
            else { $unchanged++; $out[] = array_replace($base, ['status' => 'unchanged']); }
        }
        return ['data' => $out, 'summary' => ['changed' => $changed, 'unchanged' => $unchanged, 'errors' => $errors]];
    }

    private function write(Bom $bom, array $writes, object $operator): void
    {
        $actorId = (int) ($operator->legacy_id ?? 0) ?: null;
        $existing = BomOperationStandard::query()->where('bom_id', $bom->id)->get()->keyBy('routing_operation_id');
        foreach ($writes as $nodeId => $row) {
            $old = $existing->get($nodeId);
            BomOperationStandard::query()->updateOrCreate(
                ['bom_id' => $bom->id, 'routing_operation_id' => (int) $nodeId],
                ['setup_minutes' => $row['setup_minutes'], 'unit_minutes' => $row['unit_minutes'],
                    'performance_mode' => $row['performance_mode'], 'performance_rate' => $row['performance_rate'],
                    'updated_by_legacy_id' => $actorId] + ($old ? [] : ['created_by_legacy_id' => $actorId]));
        }
    }

    private function stripWrites(array $result): array
    {
        $result['data'] = array_map(fn ($row) => array_diff_key($row, ['writes' => 1]), $result['data']);
        return $result;
    }
}
