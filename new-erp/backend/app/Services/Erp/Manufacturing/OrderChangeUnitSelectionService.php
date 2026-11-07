<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{SalesOrder, SalesOrderFulfillment, MfgWorkOrder, MfgWorkOrderUnit, MfgWorkOrderLink, MfgWorkOrderMaterial, MfgUnitComponent};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Erp\SalesOrderFulfillmentPolicy;

/** 订单变更选择真实台件；候选只冻结选择，不提前停止生产。 */
final class OrderChangeUnitSelectionService
{
    private const LABELS = ['waiting' => '未开始', 'in_progress' => '加工中', 'rework' => '返工中', 'completed' => '已做好',
        'pending_receipt' => '待仓库入库', 'stored' => '已入库', 'scrapped' => '已报废', 'cancelled' => '已取消'];

    public function paginate(SalesOrder $order, array $filters): array
    {
        $scope = CancellationProductionService::affectedScope($order, [(int) $filters['line_id']]);
        $wo = MfgWorkOrder::whereIn('id', $scope['review_ids'])->findOrFail((int) $filters['work_order_id']);
        $parent = !empty($filters['parent_unit_id']) ? MfgWorkOrderUnit::findOrFail((int) $filters['parent_unit_id']) : null;
        if ($parent && !MfgWorkOrderLink::where('parent_work_order_id', $parent->work_order_id)->where('child_work_order_id', $wo->id)->exists()) {
            throw ValidationException::withMessages(['parent_unit_id' => '该设备与所选半成品工单没有有效供给关系。']);
        }
        $units = $this->eligibleUnits($wo, $parent?->id);
        $revision = $this->revision($wo, $units);
        $query = MfgWorkOrderUnit::whereIn('id', $units->pluck('id'));
        if ($keyword = trim($filters['keyword'] ?? '')) $query->where('serial_no', 'like', '%'.$keyword.'%');
        $page = $query->orderBy('unit_no')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $requiredIds = $parent ? MfgWorkOrderUnit::whereIn('id', $units->pluck('id'))
            ->whereIn('serial_no', MfgUnitComponent::installed()->where('unit_id', $parent->id)->select('component_serial_no'))->pluck('id')->all() : [];
        $page->getCollection()->transform(fn ($unit) => $this->unitRow($unit, $wo) + ['required' => in_array((int) $unit->id, $requiredIds, true)]);
        return $page->toArray() + ['expected_work_order_version' => (int) $wo->business_version, 'selection_revision' => $revision];
    }

    public function analyse(SalesOrder $order, array $candidate, array $diffs, array $resetLineIds, bool $checkVersions = false): array
    {
        $submitted = collect($candidate['production_unit_selections'] ?? []);
        $groups = []; $errors = []; $valid = []; $used = []; $unnumbered = []; $adjustments = [];
        $reductions = collect($diffs)->filter(fn ($d) => ($d['semantic_key'] ?? '') === 'order_qty' && (float) $d['after'] < (float) $d['before'])
            ->keyBy('line_id');
        $lineIds = array_values(array_unique([...$resetLineIds, ...$reductions->keys()->map(fn ($id) => (int) $id)->all()]));
        foreach ($lineIds as $lineId) {
            $line = $order->lines->firstWhere('id', $lineId);
            if (!$line || $line->line_type !== 'physical') continue;
            if (in_array($lineId, $resetLineIds, true)) {
                $scope = CancellationProductionService::affectedScope($order, [$lineId]);
                foreach (MfgWorkOrder::whereIn('id', $scope['ids'])->where('status', 'planned')->get(['id']) as $planned)
                    if (!MfgWorkOrderUnit::where('work_order_id', $planned->id)->exists()) $unnumbered[] = (int) $planned->id;
            }
            $roots = MfgWorkOrder::where('sales_order_id', $order->id)->where('sales_order_line_id', $lineId)->where('source_type', 'sales_order')
                ->whereNotIn('status', ['closed', 'cancelled'])->orderByDesc('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
            $remaining = null; $minimum = null; $maximum = null; $planned = collect(); $inventory = collect();
            if (!in_array($lineId, $resetLineIds, true)) {
                $fulfillments = SalesOrderFulfillment::where('sales_order_line_id', $lineId)->where('demand_status', 'confirmed')->get();
                $committed = $fulfillments->sum(fn ($f) => (float) ($f->item_base_qty ?? $f->fulfillment_qty));
                $remaining = max(0, round($committed - SalesOrderFulfillmentPolicy::effectiveQuantity((float) $reductions[$lineId]['after'],
                    (float) $line->cancelled_qty) * (float) $line->fulfillment_factor_snapshot, 8));
                $planned = $roots->filter(fn ($wo) => $wo->status === 'planned' && !$wo->units()->exists()
                    && !CancellationProductionService::hasMaterialFacts((int) $wo->id) && !OrderChangeLaborService::hasActualWork([(int) $wo->id]));
                $inventory = $fulfillments->where('fulfillment_type', 'inventory')->sortByDesc('id');
                $shippedByFulfillment = SalesOrderFulfillmentPolicy::shippedLines()->whereIn('sales_order_fulfillment_id', $inventory->pluck('id'))
                    ->selectRaw('sales_order_fulfillment_id, SUM(base_qty) AS qty')->groupBy('sales_order_fulfillment_id')->pluck('qty', 'sales_order_fulfillment_id');
                $capacity = $planned->sum('planned_qty') + $inventory->sum(fn ($f) => max(0,
                    (float) ($f->item_base_qty ?? $f->fulfillment_qty) - (float) ($shippedByFulfillment[$f->id] ?? 0)));
                $minimum = max(0, $remaining - (float) $capacity); $maximum = $remaining;
                if ($minimum > 0 && abs($minimum - round($minimum)) > 0.00001) $errors[] = '本次需要减少的生产设备不是完整台件，请核对冻结单位换算。';
            }
            $rootIds = $roots->pluck('id')->map(fn ($id) => (int) $id)->all();
            $rootInputs = $submitted->filter(fn ($sel) => (int) ($sel['line_id'] ?? 0) === $lineId && empty($sel['parent_unit_id'])
                && in_array((int) ($sel['work_order_id'] ?? 0), $rootIds, true));
            $selectedRootCount = $rootInputs->sum(fn ($sel) => count((array) ($sel['unit_ids'] ?? [])));
            $lineRequired = $remaining === null ? null : max($minimum, $selectedRootCount);
            $recommended = $lineRequired;
            foreach ($roots as $wo) {
                $available = $this->eligibleUnits($wo);
                if (!$available->count()) continue;
                $input = $rootInputs->first(fn ($sel) => (int) $sel['work_order_id'] === (int) $wo->id);
                $required = $remaining === null ? $available->count() : ($rootInputs->isNotEmpty()
                    ? count((array) ($input['unit_ids'] ?? [])) : min($available->count(), (int) round($recommended)));
                if ($remaining !== null && $rootInputs->isEmpty()) $recommended = max(0, $recommended - $required);
                $this->appendGroup($groups, $errors, $valid, $used, $submitted, $lineId, $wo, null, $required, $available,
                    $checkVersions, $lineRequired, $minimum, $maximum);
            }
            if ($remaining !== null) {
                if ($selectedRootCount < $minimum - 0.00001 || $selectedRootCount > $maximum + 0.00001)
                    $errors[] = '该行本次应选择 '.$this->number($minimum).' 至 '.$this->number($maximum).' 个生产编号，其余差额由计划或库存承接。';
                $left = max(0, $remaining - $lineRequired);
                foreach ($planned as $wo) {
                    $take = min((float) $wo->planned_qty, $left);
                    if ($take > 0.00000001) $adjustments[] = ['line_id' => $lineId, 'work_order_id' => (int) $wo->id,
                        'fulfillment_id' => (int) $wo->sales_fulfillment_id, 'origin' => 'planned', 'item_name' => $wo->item_name,
                        'unit_name' => $wo->unit_name ?: $line->item_base_unit_name_snapshot, 'qty' => $take];
                    $left -= $take;
                }
                foreach ($inventory as $fulfillment) {
                    $take = min(max(0, (float) ($fulfillment->item_base_qty ?? $fulfillment->fulfillment_qty)
                        - (float) ($shippedByFulfillment[$fulfillment->id] ?? 0)), $left);
                    if ($take > 0.00000001) $adjustments[] = ['line_id' => $lineId, 'work_order_id' => null, 'fulfillment_id' => (int) $fulfillment->id,
                        'origin' => 'inventory', 'item_name' => $line->item_name ?: $line->sku_name,
                        'unit_name' => $line->item_base_unit_name_snapshot, 'qty' => $take];
                    $left -= $take;
                }
                if ($left > 0.00000001) $errors[] = '该行还有 '.$this->number($left).' 的减少数量未找到可调整的计划或库存来源，请重新预览。';
            }
        }
        // 父单尚未下达时没有主机编号；其已有编号的子件仍按真实差额选择，绝不虚构父台。
        $rawParents = array_map(fn ($id) => ['work_order_id' => $id, 'qty' => null], $unnumbered);
        foreach ($adjustments as $adjustment) if ($adjustment['origin'] === 'planned') $rawParents[] = $adjustment;
        foreach ($rawParents as $raw) {
            $parentWo = MfgWorkOrder::find($raw['work_order_id']);
            foreach (MfgWorkOrderLink::where('parent_work_order_id', $parentWo->id)->orderByDesc('id')->get()->groupBy('component_item_id') as $itemId => $links) {
                $total = (float) MfgWorkOrderMaterial::where('work_order_id', $parentWo->id)->where('component_item_id', $itemId)->sum('required_qty');
                $kept = $raw['qty'] === null ? 0 : max(0, (float) $parentWo->planned_qty - (float) $raw['qty']);
                $need = $raw['qty'] === null ? 0 : ceil($total * $kept / max(1, (float) $parentWo->planned_qty) - 0.00001);
                $children = MfgWorkOrder::whereIn('id', $links->pluck('child_work_order_id'))->where('source_type', 'work_order')->get()->keyBy('id');
                $remove = max(0, $children->sum(fn ($child) => count(CancellationProductionService::salesDemandUnitIds((int) $child->id))) - $need);
                foreach ($links as $link) {
                    $child = $children->get($link->child_work_order_id); if (!$child || $remove <= 0) continue;
                    $available = $this->eligibleUnits($child); $required = min($available->count(), (int) $remove);
                    if ($required) $this->appendGroup($groups, $errors, $valid, $used, $submitted,
                        (int) ($raw['line_id'] ?? $parentWo->sales_order_line_id), $child, null, $required, $available, $checkVersions);
                    $remove -= $required;
                }
            }
        }
        // 每个子工单只属于一个父工单；未装配时由人选择具体编号，已经装配的必须对应所选父设备。
        for ($i = 0; $i < count($groups); $i++) {
            $group = $groups[$i];
            foreach ($group['selected_unit_ids'] as $parentId) {
                if (!isset($valid[$parentId])) continue;
                $parent = MfgWorkOrderUnit::find($parentId);
                $parentWo = MfgWorkOrder::find($parent->work_order_id);
                $installedForParent = MfgWorkOrderUnit::whereIn('serial_no', MfgUnitComponent::installed()->where('unit_id', $parentId)->select('component_serial_no'))->pluck('work_order_id')->all();
                $links = MfgWorkOrderLink::where('parent_work_order_id', $parentWo->id)
                    ->where(fn ($q) => $q->where('qty', '>', 0)->orWhereIn('child_work_order_id', $installedForParent))->orderByDesc('id')->get()->groupBy('component_item_id');
                foreach ($links as $itemId => $itemLinks) {
                    $total = (float) MfgWorkOrderMaterial::where('work_order_id', $parentWo->id)->where('component_item_id', $itemId)->sum('required_qty');
                    $needed = (int) max(0, ceil($total / max(1, (float) $parentWo->planned_qty) - 0.00001));
                    $installedSerials = MfgUnitComponent::installed()->where('unit_id', $parentId)->where('component_item_id', $itemId)->pluck('component_serial_no')->all();
                    $installedChildIds = MfgWorkOrderUnit::whereIn('serial_no', $installedSerials)->pluck('work_order_id')->all();
                    $itemLinks = $itemLinks->sortByDesc(fn ($link) => in_array((int) $link->child_work_order_id, $installedChildIds, true));
                    $installedUnits = MfgWorkOrderUnit::whereIn('work_order_id', $itemLinks->pluck('child_work_order_id'))->whereIn('serial_no', $installedSerials)->pluck('id')->all();
                    $alreadyReleased = \App\Models\Erp\CancellationProductionDisposition::whereIn('work_order_unit_id', $installedUnits)->count();
                    $needed = max(0, max($needed, count($installedUnits)) - $alreadyReleased);
                    foreach ($itemLinks as $link) {
                        if ($needed <= 0) break;
                        $child = MfgWorkOrder::where('status', '!=', 'cancelled')->find($link->child_work_order_id);
                        if (!$child || $child->source_type === 'stock') continue;
                        $available = $this->eligibleUnits($child, (int) $parentId);
                        $required = min($available->reject(fn ($u) => isset($used[(int) $u->id]))->count(), $needed);
                        if (!$required) continue;
                        $this->appendGroup($groups, $errors, $valid, $used, $submitted, (int) $group['line_id'], $child, (int) $parentId,
                            $required, $available, $checkVersions);
                        $needed -= $required;
                    }
                    // 未开子单、库存供给和已终结的部件不在变更时凭空生成生产设备。
                }
            }
        }
        $expectedKeys = array_map(fn ($g) => $this->key($g), $groups);
        foreach ($submitted as $selection) {
            if (!in_array($this->key($selection), $expectedKeys, true) && !empty($selection['unit_ids'])) $errors[] = '所选设备不属于本次需要调整的主子工单，请重新预览。';
        }
        $unitIds = array_keys($valid);
        return ['can_submit' => !$errors && collect($groups)->every(fn ($g) => !$g['selection_required']),
            'production_selection_groups' => $groups, 'production_selection_errors' => array_values(array_unique($errors)),
            'quantity_adjustments' => $adjustments, 'unit_ids' => $unitIds, 'unnumbered_work_order_ids' => array_values(array_unique($unnumbered)), 'selections' => array_map(fn ($g) => [
                'line_id' => $g['line_id'], 'work_order_id' => $g['work_order_id'], 'parent_unit_id' => $g['parent_unit_id'],
                'unit_ids' => $g['selected_unit_ids'], 'expected_work_order_version' => $g['expected_work_order_version'], 'selection_revision' => $g['selection_revision'],
            ], $groups), 'has_actual_work' => $unitIds && OrderChangeLaborService::hasActualWork(array_unique(array_column($groups, 'work_order_id')), $unitIds)];
    }

    private function appendGroup(array &$groups, array &$errors, array &$valid, array &$used, $submitted, int $lineId, MfgWorkOrder $wo,
        ?int $parentId, int $required, $available, bool $checkVersions, ?float $lineRequired = null, ?float $minimum = null, ?float $maximum = null): void
    {
        $identity = ['line_id' => $lineId, 'work_order_id' => (int) $wo->id, 'parent_unit_id' => $parentId];
        $matches = $submitted->filter(fn ($s) => $this->key($s) === $this->key($identity));
        if ($matches->count() > 1) $errors[] = '同一主子工单的编号选择重复，请重新选择。';
        $input = (array) $matches->first();
        $inputIds = array_map('intval', $input['unit_ids'] ?? []);
        $requiredIds = $parentId ? MfgWorkOrderUnit::whereIn('id', $available->pluck('id'))
            ->whereIn('serial_no', MfgUnitComponent::installed()->where('unit_id', $parentId)->select('component_serial_no'))->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $ids = array_values(array_unique([...$inputIds, ...$requiredIds]));
        $revision = $this->revision($wo, $available);
        $ok = count($inputIds) === $required && count(array_unique($inputIds)) === count($inputIds);
        if (array_diff($requiredIds, $inputIds)) $ok = false;
        $selected = $available->whereIn('id', $ids);
        if ($selected->count() !== count($ids)) $ok = false;
        foreach ($selected as $unit) {
            if (isset($used[(int) $unit->id]) || !$this->unitRow($unit, $wo)['selectable']) $ok = false;
        }
        if ($checkVersions && $ids && ((int) ($input['expected_work_order_version'] ?? 0) !== (int) $wo->business_version
            || !hash_equals($revision, (string) ($input['selection_revision'] ?? '')))) {
            $ok = false; $errors[] = $wo->work_order_no.' 的编号信息发生变化，请重新预览并确认所选编号。';
        }
        if ($ids && !$ok) $errors[] = $wo->work_order_no.' 请准确选择 '.$required.' 个仍属于本次需求的编号，待入库申请须先撤回或办结。';
        if ($ok) foreach ($ids as $id) { $valid[$id] = true; $used[$id] = true; }
        $groups[] = $identity + ['work_order_no' => $wo->work_order_no, 'item_name' => $wo->item_name,
            'parent_unit_serial_no' => $parentId ? MfgWorkOrderUnit::whereKey($parentId)->value('serial_no') : null,
            'required_count' => $required, 'selected_unit_ids' => $ids,
            'required_unit_ids' => $requiredIds, 'line_required_count' => $lineRequired,
            'line_min_numbered_count' => $minimum, 'line_max_numbered_count' => $maximum,
            'allow_count_change' => $parentId === null && $lineRequired !== null, 'max_count' => $available->count(),
            'selected_units' => $selected->map(fn ($u) => $this->unitRow($u, $wo) + ['required' => in_array((int) $u->id, $requiredIds, true)])->values()->all(),
            'expected_work_order_version' => (int) $wo->business_version, 'selection_revision' => $revision, 'selection_required' => !$ok];
    }

    private function eligibleUnits(MfgWorkOrder $wo, ?int $parentId = null)
    {
        $units = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', CancellationProductionService::salesDemandUnitIds((int) $wo->id))
            ->orderBy('unit_no')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        $units = $units->reject(fn ($unit) => $this->hasShippedIdentity($unit))->values();
        if (!$parentId) return $units;
        $installed = MfgUnitComponent::installed()->whereIn('component_serial_no', $units->pluck('serial_no'))->get()->keyBy('component_serial_no');
        return $units->filter(fn ($unit) => !isset($installed[$unit->serial_no]) || (int) $installed[$unit->serial_no]->unit_id === $parentId)->values();
    }

    private function revision(MfgWorkOrder $wo, $units): string
    {
        return hash('sha256', json_encode([(int) $wo->id, (int) $wo->business_version,
            $units->map(fn ($u) => [$u->id, $u->status, $u->current_operation_id, $u->inventory_serial_id, $u->rework_count])->values()->all()]));
    }

    private function unitRow(MfgWorkOrderUnit $unit, MfgWorkOrder $wo): array
    {
        $disabled = $this->hasShippedIdentity($unit) ? '该编号或其装配主机已有正式发货记录，原发货与退货事实保留'
            : ($this->hasAnonymousShippedSource($unit) ? '该历史已入库编号的来源有按数量发货记录，尚无凭据区分已发与未发，请先核对原发货的具体设备'
                : ($unit->status === 'pending_receipt' ? '已有待入库申请，请先撤回或办结' : null));
        return ['id' => (int) $unit->id, 'unit_no' => (int) $unit->unit_no, 'serial_no' => $unit->serial_no,
            'work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no, 'status' => $unit->status,
            'status_label' => self::LABELS[$unit->status] ?? $unit->status, 'selectable' => !$disabled, 'disabled_reason' => $disabled];
    }

    private function hasShippedIdentity(MfgWorkOrderUnit $unit, array $visited = []): bool
    {
        if (in_array((int) $unit->id, $visited, true)) return false;
        $visited[] = (int) $unit->id;
        // 一笔入库预留可能涵盖多台；只能认本次发货的具体编号，不能据来源预留把同批未发台件一起冻结。
        $shipped = SalesOrderFulfillmentPolicy::shippedLines()->where(function ($query) use ($unit) {
            if ($unit->inventory_serial_id) $query->whereJsonContains('serial_snapshot->inventory_serial_ids', (int) $unit->inventory_serial_id);
            $query->orWhereJsonContains('serial_snapshot->serial_nos', $unit->serial_no);
        })->exists();
        if ($shipped) return true;
        $parentIds = MfgUnitComponent::installed()->where('component_serial_no', $unit->serial_no)->pluck('unit_id');
        foreach (MfgWorkOrderUnit::whereIn('id', $parentIds)->get() as $parent) if ($this->hasShippedIdentity($parent, $visited)) return true;
        return false;
    }

    private function hasAnonymousShippedSource(MfgWorkOrderUnit $unit, array $visited = []): bool
    {
        if (in_array((int) $unit->id, $visited, true)) return false;
        $visited[] = (int) $unit->id;
        if (!$unit->inventory_serial_id && $unit->status === 'stored') {
            $sources = \App\Models\Erp\InventoryReservation::whereJsonContains('reservation_snapshot->serial_nos', $unit->serial_no)->select('id');
            $shipped = SalesOrderFulfillmentPolicy::shippedLines()->whereIn('inventory_reservation_id', $sources)->get(['serial_snapshot']);
            if ($shipped->contains(fn ($line) => empty($line->serial_snapshot['inventory_serial_ids']) && empty($line->serial_snapshot['serial_nos']))) return true;
        }
        foreach (MfgWorkOrderUnit::whereIn('id', MfgUnitComponent::installed()->where('component_serial_no', $unit->serial_no)->select('unit_id'))->get() as $parent) {
            if ($this->hasAnonymousShippedSource($parent, $visited)) return true;
        }
        return false;
    }

    private function key(array $row): string { return (int) ($row['line_id'] ?? 0).':'.(int) ($row['work_order_id'] ?? 0).':'.(int) ($row['parent_unit_id'] ?? 0); }
    private function number(float $value): string { return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'); }
}
