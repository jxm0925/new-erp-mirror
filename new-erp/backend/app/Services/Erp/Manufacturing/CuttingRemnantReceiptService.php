<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{Location, MfgCuttingBatch, MfgCuttingRemnantReceipt, MfgCuttingRun, MfgTaskParticipant, MfgWorkOrder, Warehouse};
use App\Services\Erp\{AuthContextService, CuttingDecimal, InventoryAmountVisibilityService, MaterialPhysicalService};
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use Illuminate\Support\Facades\DB;

/** 下料人核对真实余料后直接记库存、实物谱系和净耗；旧申请只保留历史。 */
final class CuttingRemnantReceiptService
{
    use ProductionActor;

    public const STATUS_LABELS = ['pending' => '待仓库确认', 'confirmed' => '已确认', 'rejected' => '已驳回', 'cancelled' => '已撤回'];

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly MaterialPhysicalService $physicals,
    ) {}

    public static function requestRules(): array
    {
        return ['expected_version' => 'required|integer|min:1', 'confirmed' => 'required|accepted',
            'idempotency_key' => 'nullable|string|min:16|max:80', 'remark' => 'nullable|string|max:500',
            'unit_qty' => 'nullable|integer|min:1|max:1000', 'assignments' => 'sometimes|array|max:10000',
            'assignments.*.source_key' => 'required|string|max:120', 'assignments.*.part_key' => 'required|string|max:80',
            'assignments.*.qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D',
            'remnants' => 'required|array|min:1|max:1000', 'remnants.*.source_key' => 'required|string|max:120|distinct',
            'remnants.*.measure_qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D',
            'remnants.*.warehouse_id' => 'nullable|integer|min:1', 'remnants.*.location_id' => 'nullable|integer|min:1'];
    }

    public static function confirmRules(bool $scrap = false): array
    {
        return ['expected_version' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:1000',
            'lines.*.source_key' => 'required|string|max:120|distinct',
            'lines.*.measure_qty' => 'required|string|regex:/^\d{1,10}(?:\.\d{1,8})?$/D',
            'lines.*.warehouse_id' => 'nullable|integer|min:1', 'lines.*.location_id' => 'nullable|integer|min:1']
            + ($scrap ? ['reason' => 'required|string|max:500'] : []);
    }

    /** 调用方已经锁批次并冻结首次实际产出；重提只能改余料，不能重新满足一次用料数量。 */
    public function submitForBatch(MfgCuttingBatch $batch, array $rows, object $actor): void
    {
        if (DB::transactionLevel() < 1 || $batch->status !== 'awaiting_confirm') throw new \LogicException('Remnant requests require a locked registered batch.');
        $this->assertRequestActor($batch, $actor);
        $last = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->lockForUpdate()->first();
        if ($last && (int) $last->requested_by_legacy_id !== (int) $this->actorId($actor))
            $this->fail('permission_denied', '请原登记人更正批次 '.$batch->batch_no.' 的余料后重新提交。', 403);
        $lines = $this->lines($batch, $rows);
        foreach ($lines as &$line) $line += ['self_recorded' => true, 'recorded_by_legacy_id' => $this->actorId($actor), 'recorded_by_name' => $this->actorName($actor), 'recorded_at' => now()->toDateTimeString()];
        unset($line);
        $output = $this->assertSharedConservation($batch, $lines, false);
        if ($output) foreach ($lines as &$line) $line['run_output_snapshot'] = $output;
        unset($line);
        $batch->update(['remnants' => $lines]);
        $this->log($batch, 'remnant_recorded', '批次 '.$batch->batch_no.' 下料人已核对实际余料', $actor,
            ['previous_receipt_id' => $last?->id, 'lines' => $lines]);
        // 集中开料先登记余料时尚无实际件数，等切完结束在同一事务核对全批守恒后结算。
        if ($batch->batch_scope !== 'shared' || $output) $this->settleBatch($batch, $lines, $actor);
    }

    public function settleRecordedBatch(MfgCuttingBatch $batch, object $actor): void
    {
        if (DB::transactionLevel() < 1 || $batch->status !== 'awaiting_confirm' || ! $batch->settlement_key || ! $batch->remnants) return;
        // 旧仓库申请必须由原登记人主动重核，不把历史 pending 当作已完成的新登记。
        if (! collect($batch->remnants)->every(fn ($line) => ! empty($line['self_recorded']))) return;
        $lines = $this->lines($batch, $batch->remnants);
        foreach ($lines as $index => &$line) $line += array_intersect_key($batch->remnants[$index], array_flip(['self_recorded', 'recorded_by_legacy_id', 'recorded_by_name', 'recorded_at']));
        unset($line);
        $output = $this->assertSharedConservation($batch, $lines, true);
        if ($output) foreach ($lines as &$line) $line['run_output_snapshot'] = $output;
        unset($line);
        $this->settleBatch($batch, $lines, $actor);
    }

    public function confirm(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->fail('remnant_history_readonly', '旧余料申请仅供查看，请由原登记人在生产开料批次核对实际余料后登记结算。', 409);
    }

    public function scrap(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->fail('remnant_history_readonly', '旧余料申请仅供查看，请由原登记人在生产开料批次核对实际余料后登记结算。', 409);
    }

    public function reject(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->fail('remnant_history_readonly', '旧余料申请仅供查看，请由原登记人在生产开料批次核对实际余料后登记结算。', 409);
    }

    public function cancel(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->fail('remnant_history_readonly', '旧余料申请仅供查看，请由原登记人在生产开料批次核对实际余料后登记结算。', 409);
    }

    /** 来源与产出来自冻结批次；请求只允许提交实测面积/长度与库位。 */
    private function lines(MfgCuttingBatch $batch, array $rows): array
    {
        $bySource = collect($rows)->keyBy('source_key');
        $inputs = collect($batch->input_lines);
        if ($bySource->count() !== count($rows) || $bySource->count() !== $inputs->count()
            || $bySource->keys()->diff($inputs->pluck('source_key'))->isNotEmpty()) $this->fail('remnant_sources_invalid', '必须逐一核对本批全部真实来源，无余料请明确填零。');
        $output = collect($batch->actual_outputs ?? [])->groupBy('source_key');
        $lines = [];
        foreach ($inputs as $input) {
            $row = $bySource->get($input['source_key']);
            $measure = CuttingDecimal::value($row['measure_qty'] ?? '', 8, true);
            if (in_array($input['unit_kind'], ['m', 'm2'], true) && bccomp($measure, bcadd($measure, '0', 4), 8) !== 0)
                $this->fail('stock_precision_invalid', '按米或平方米入库的实际余料最多保留四位小数，不能让库存数量被四舍五入。');
            if ($input['mode'] === 'length' && bccomp($measure, bcdiv(bcmul($measure, '1000', 2), '1000', 8), 8) !== 0)
                $this->fail('remnant_precision_invalid', '管材余料以米登记，最多五位小数，必须能无损保存毫米长度。');
            $used = $batch->batch_scope === 'shared' ? null : ($output->get($input['source_key']) ?? collect())
                ->reduce(fn ($sum, $line) => bcadd($sum, (string) $line['measure_qty'], 8), '0.00000000');
            $max = $used === null ? (string) $input['input_measure'] : bcsub((string) $input['input_measure'], $used, 8);
            if (bccomp($measure, $max, 8) > 0) $this->fail('remnant_exceeds_source', '批次 '.$batch->batch_no.' 来源 '.($input['physical_no'] ?? $input['source_key']).' 的余料超过真实投入减实际产出的剩余量。');
            $warehouse = null; $location = null;
            if (bccomp($measure, '0', 8) > 0) {
                $location = Location::query()->lockForUpdate()->find((int) ($row['location_id'] ?? 0));
                $warehouseId = (int) ($row['warehouse_id'] ?? $location?->warehouse_id ?? 0);
                $warehouse = Warehouse::query()->lockForUpdate()->find($warehouseId);
                if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
                    $this->fail('remnant_warehouse_invalid', '余料必须回到启用的工厂仓库。');
                if (! $location || (int) $location->warehouse_id !== $warehouseId || ! in_array($location->status, ['enabled', 'active'], true) || $location->location_purpose !== 'general')
                    $this->fail('remnant_location_invalid', '正余料必须明确选择该工厂仓库内启用的普通库存库位。');
            }
            $cost = bccomp($measure, '0', 8) === 0 ? '0.0000' : CuttingDecimal::share((string) $input['cost_amount'], (string) $input['input_measure'], $measure);
            $physical = empty($input['physical_material_id']) ? null : DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first();
            $uncut = bccomp($measure, (string) $input['input_measure'], 8) === 0 && ($used === null || bccomp($used, '0', 8) === 0);
            $dimensions = $input['mode'] === 'sheet' ? ['area_m2' => $measure, 'thickness_mm' => $input['dimensions']['thickness_mm']]
                : ['length_mm' => bcmul($measure, '1000', 2)];
            if ($uncut && $input['mode'] === 'sheet') $dimensions = $input['dimensions'];
            $lines[] = ['source_key' => $input['source_key'], 'item_id' => $input['item_id'], 'item_name' => $input['item_name'] ?? null,
                'physical_material_id' => $input['physical_material_id'] ?? null, 'physical_no' => $input['physical_no'] ?? null,
                'inventory_balance_id' => $input['inventory_balance_id'], 'material_lot_id' => $input['material_lot_id'] ?? null,
                'source_physical_material_id' => $input['physical_material_id'] ?? null,
                'source_root_physical_id' => $physical ? ($physical->root_physical_id ?: $physical->id) : null,
                'source_inventory_balance_id' => $input['inventory_balance_id'], 'source_material_lot_id' => $input['material_lot_id'] ?? null,
                'source_batch_no' => $input['batch_no'], 'source_transaction_item_id' => $input['transaction_item_id'] ?? null,
                'cutting_holding_id' => $input['cutting_holding_id'] ?? null, 'mode' => $input['mode'], 'measure_unit' => $input['measure_unit'],
                'source_shape' => $input['shape'], 'source_dimensions' => $input['dimensions'], 'input_measure' => $input['input_measure'],
                'output_measure' => $used, 'max_remnant_measure' => $max, 'measure_qty' => $measure,
                'consumed_measure' => bcsub((string) $input['input_measure'], $measure, 8), 'scrap_measure' => bcsub($max, $measure, 8),
                'shape' => $input['mode'] === 'sheet' ? ($uncut ? $input['shape'] : 'AREA_ONLY') : 'LENGTH', 'dimensions' => $dimensions,
                'cut_length_mm' => $input['mode'] === 'length' ? bcmul($measure, '1000', 2) : null,
                'warehouse_id' => $warehouse?->id, 'location_id' => $location?->id, 'warehouse_name' => $warehouse?->warehouse_name,
                'location_name' => $location?->location_name, 'location_code' => $location?->location_code,
                'source_cost_amount' => $input['cost_amount'], 'cost_amount' => $cost];
        }
        return $lines;
    }

    /** 集中开料只证明全批守恒，绝不从总量反推每张板实际切了哪些件。 */
    private function assertSharedConservation(MfgCuttingBatch $batch, array $candidate, bool $requireOutput): ?array
    {
        if ($batch->batch_scope !== 'shared') return null;
        $run = MfgCuttingRun::query()->lockForUpdate()->findOrFail($batch->cutting_run_id);
        if (! in_array($run->status, ['awaiting_confirm', 'finished'], true)) {
            if ($requireOutput) $this->fail('cutting_outputs_required', '开料记录 '.$run->run_no.' 尚未登记实际件数，请先返回「切完结束」登记件数并停止计时，再核对批次 '.$batch->batch_no.' 的余料。');
            return null;
        }
        $siblings = MfgCuttingBatch::query()->where('cutting_run_id', $run->id)->where('item_id', $batch->item_id)->orderBy('id')->lockForUpdate()->get();
        $inputMeasure = '0.00000000'; $remaining = '0.00000000';
        foreach ($siblings as $sibling) {
            foreach ($sibling->input_lines as $input) $inputMeasure = bcadd($inputMeasure, (string) $input['input_measure'], 8);
            foreach ((int) $sibling->id === (int) $batch->id ? $candidate : ($sibling->remnants ?? []) as $line)
                $remaining = bcadd($remaining, (string) $line['measure_qty'], 8);
        }
        $actual = '0.00000000'; $facts = [];
        foreach ($run->lines as $line) {
            if ((int) $line['component_item_id'] !== (int) $batch->item_id || (int) $line['actual_pieces'] <= 0) continue;
            $measure = CuttingRunService::lineMeasure($line);
            $actual = bcadd($actual, $measure, 8);
            $facts[] = array_intersect_key($line, array_flip(['line_key', 'component_item_id', 'length_mm', 'width_mm', 'thickness_mm', 'actual_pieces'])) + ['measure_qty' => $measure];
        }
        if (bccomp(bcadd($actual, $remaining, 8), $inputMeasure, 8) > 0)
            $this->fail('remnant_exceeds_actual_output', '开料记录 '.$run->run_no.' 的实际板件面积与本次及其他批次余料之和超过真实投入，请核对批次 '.$batch->batch_no.' 和关联余料申请。');
        return ['run_id' => $run->id, 'run_no' => $run->run_no, 'output_lines' => $facts,
            'input_measure' => $inputMeasure, 'actual_output_measure' => $actual, 'all_remnant_measure' => $remaining];
    }

    private function settleBatch(MfgCuttingBatch $batch, array $lines, object $actor): void
    {
        $returned = '0.0000'; $pool = '0.00000000';
        foreach ($lines as $line) {
            $returned = bcadd($returned, $line['cost_amount'], 4);
            $pool = bcadd($pool, $line['consumed_measure'], 8);
        }
        $net = bcsub((string) $batch->input_cost_amount, $returned, 4);
        if (bccomp($net, '0', 4) < 0) $this->fail('cost_invalid', '余料回收成本不能超过真实投入。');
        $batch->update(['remnants' => $lines, 'remnant_cost_amount' => $returned, 'net_cost_amount' => $net,
            'pool_measure' => $batch->batch_scope === 'shared' && ! empty($batch->input_lines[0]['physical_material_id']) && ($batch->input_lines[0]['mode'] ?? null) === 'sheet' ? $pool : '0.00000000']);
        $wo = $batch->work_order_id ? MfgWorkOrder::query()->findOrFail($batch->work_order_id) : null;
        $posted = $this->inventory->postManufacturingCuttingSettlement($batch, $wo, $this->actorId($actor));
        foreach ($lines as &$line) $line = array_replace($line, $posted['lines'][$line['source_key']] ?? []);
        unset($line);
        $batch->refresh();
        $movements = [];
        foreach ($batch->input_lines as $input) {
            if (empty($input['physical_material_id'])) continue;
            $line = collect($lines)->firstWhere('source_key', $input['source_key']);
            $movements[$input['source_key']] = $this->physicals->completeManufacturingCut($batch, $input,
                bcsub((string) $input['cost_amount'], (string) $line['cost_amount'], 4), $this->actorId($actor) ?? 0);
        }
        $batch->update(['remnants' => $lines, 'status' => 'settled', 'business_version' => (int) $batch->business_version + 1,
            'settled_by_legacy_id' => $this->actorId($actor), 'settled_by_name' => $this->actorName($actor), 'settled_at' => now()]);
        if ($wo) {
            $wo->update(['business_version' => (int) $wo->business_version + 1]);
            app(WorkOrderService::class)->refreshCompletion($wo, $actor);
        }
        $this->log($batch, 'cutting_settled', '批次 '.$batch->batch_no.' 实际余料记录及净耗结算完成',
            $actor, ['physical_movement_ids' => $movements, 'lines' => $lines]);
    }

    public function assertRequestActor(MfgCuttingBatch $batch, object $actor): void
    {
        $this->assertPermission($actor, ['production.task.execute', 'production.work_order.edit']);
        if (! $this->canRequestActor($batch, $actor)) $this->fail('permission_denied', '只能登记本人当前参与工序的余料。', 403);
    }

    private function canRequestActor(MfgCuttingBatch $batch, ?object $actor): bool
    {
        if (! $actor) return false;
        $auth = app(AuthContextService::class);
        if ($auth->isSuperAdmin($actor)) return true;
        $codes = $auth->permissionCodes($actor);
        if (! array_intersect(['production.task.execute', 'production.work_order.edit'], $codes)) return false;
        return ! $batch->work_order_id || in_array('production.work_order.edit', $codes, true)
            || MfgTaskParticipant::query()->where('work_order_id', $batch->work_order_id)->where('work_order_operation_id', $batch->work_order_operation_id)
                ->where('employee_legacy_id', $this->actorId($actor))->where('is_active', true)->exists();
    }

    private function assertPermission(object $actor, array $any): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($any, $auth->permissionCodes($actor))) $this->fail('permission_denied', '当前账号没有这项余料操作权限。', 403);
    }

    private function log(MfgCuttingBatch $batch, string $action, string $content, object $actor, array $facts): void
    {
        if ($batch->work_order_id) {
            $wo = MfgWorkOrder::query()->findOrFail($batch->work_order_id);
            app(WorkOrderService::class)->log($wo, $action, $wo->status, $wo->status, $content, $actor, $facts + ['cutting_batch_id' => $batch->id]);
        }
    }

    public function paginate(array $filters, object $actor): array
    {
        $query = $this->readQuery($actor)->with('batch');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['cutting_batch_id'])) $query->where('cutting_batch_id', (int) $filters['cutting_batch_id']);
        if ($key = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('receipt_no', 'like', '%'.$key.'%')
            ->orWhereHas('batch', fn ($b) => $b->where('batch_no', 'like', '%'.$key.'%')));
        $page = $query->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $amounts = in_array('inventory.amount.view', app(AuthContextService::class)->permissionCodes($actor), true);
        $page->setCollection($page->getCollection()->map(fn ($receipt) => $this->present($receipt, $amounts, $actor)));
        return $page->toArray();
    }

    public function show(int $id, object $actor): array
    {
        $receipt = $this->readQuery($actor)->findOrFail($id);
        return $this->present($receipt, in_array('inventory.amount.view', app(AuthContextService::class)->permissionCodes($actor), true), $actor);
    }

    private function readQuery(object $actor)
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm', 'production.work_order.view', 'production.work_order.edit', 'production.task.execute', 'production.task.view']);
        $auth = app(AuthContextService::class);
        $all = $auth->isSuperAdmin($actor) || array_intersect(['inventory.inbound.confirm', 'production.work_order.view', 'production.work_order.edit'], $auth->permissionCodes($actor));
        return MfgCuttingRemnantReceipt::query()->when(! $all, fn ($q) => $q->where('requested_by_legacy_id', $this->actorId($actor)));
    }

    public function present(MfgCuttingRemnantReceipt $receipt, bool $amounts, ?object $actor = null): array
    {
        $receipt->loadMissing('batch');
        $batch = $receipt->batch;
        $seconds = max(0, (int) ($receipt->requested_at?->diffInSeconds($receipt->confirmed_at ?? $receipt->rejected_at ?? $receipt->cancelled_at ?? now(), false) ?? 0));
        $codes = $actor ? app(AuthContextService::class)->permissionCodes($actor) : [];
        $pending = $receipt->status === 'pending';
        $confirm = false;
        $row = $receipt->toArray() + ['batch_no' => $batch?->batch_no, 'batch_scope' => $batch?->batch_scope,
            'batch_status' => $batch?->status, 'batch_business_version' => (int) $batch?->business_version,
            'work_order_id' => $batch?->work_order_id, 'cutting_run_id' => $batch?->cutting_run_id,
            'status_label' => self::STATUS_LABELS[$receipt->status] ?? $receipt->status,
            'waiting_seconds' => $seconds, 'waiting_hours' => round($seconds / 3600, 1), 'overdue' => $pending && $seconds > 86400,
            'waiting_reason' => $batch ? $this->waitingReason($batch) : null, 'requires_serial_check' => false,
            'cost_status' => $receipt->status === 'confirmed' ? 'confirmed' : 'pending_confirmation',
            'cost_status_label' => $receipt->status === 'confirmed' ? '已确认' : '待确认',
            'can_resubmit' => $batch?->status === 'awaiting_confirm'
                && $actor && (int) $receipt->requested_by_legacy_id === (int) $this->actorId($actor)
                && $this->canRequestActor($batch, $actor),
            'actions' => ['confirm' => (bool) $confirm, 'reject' => (bool) $confirm,
                'scrap' => $confirm && in_array('production.scrap.approve', $codes, true),
                'cancel' => false]];
        unset($row['batch']);
        foreach ($row['requested_lines'] as &$line) {
            $line['cost_amount'] = null;
            $line['cost_status'] = 'pending_confirmation';
        }
        unset($line);
        return $amounts ? $row : app(InventoryAmountVisibilityService::class)->redact($row, true);
    }

    public function batchConfirmation(MfgCuttingBatch $batch, bool $amounts, ?object $actor): array
    {
        $receipt = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->first();
        $dto = $receipt ? $this->present($receipt, $amounts, $actor) : null;
        $editable = $actor && $batch->status === 'awaiting_confirm' && (! $receipt || (int) $receipt->requested_by_legacy_id === (int) $this->actorId($actor))
            && $this->canRequestActor($batch, $actor);
        return ['remnant_receipt' => $dto,
            'can_resubmit' => (bool) $editable, 'remnant_editable' => (bool) $editable,
            'waiting_reason' => $this->waitingReason($batch),
            'cost_status' => $batch->status === 'settled' ? 'confirmed' : 'pending_confirmation',
            'cost_status_label' => $batch->status === 'settled' ? '已结算' : '待登记结算'];
    }

    public function waitingReason(MfgCuttingBatch $batch): ?string
    {
        if ($batch->status !== 'awaiting_confirm') return null;
        $receipt = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->first();
        $text = '批次 '.$batch->batch_no.' ';
        if ($batch->cutting_run_id && ($run = MfgCuttingRun::query()->find($batch->cutting_run_id))?->status === 'running')
            return $text.'余料已记录；开料记录 '.$run->run_no.' 请「切完结束」登记实际件数并停止计时后结算。';
        return $text.($receipt ? '旧余料申请保留历史，请原登记人在本批重新核对实际余料并登记结算。' : '请下料人核对实际余料后登记结算。');
    }
}
