<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{Item, Location, MfgCuttingBatch, MfgCuttingRemnantReceipt, MfgCuttingRun, MfgTaskParticipant, MfgWorkOrder, Warehouse};
use App\Services\Erp\{AuthContextService, CuttingDecimal, DocumentNumberService, InventoryAmountVisibilityService, MaterialPhysicalService};
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use Illuminate\Support\Facades\DB;

/** 产出数量先成为生产事实，余料和最终净耗只能在仓库确认后记账。 */
final class CuttingRemnantReceiptService
{
    use ProductionActor;

    public const STATUS_LABELS = ['pending' => '待仓库确认', 'confirmed' => '已确认', 'rejected' => '已驳回', 'cancelled' => '已撤回'];

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly MaterialPhysicalService $physicals,
        private readonly DocumentNumberService $numbers,
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
    public function submitForBatch(MfgCuttingBatch $batch, array $rows, object $actor): ?MfgCuttingRemnantReceipt
    {
        if (DB::transactionLevel() < 1 || $batch->status !== 'awaiting_confirm') throw new \LogicException('Remnant requests require a locked registered batch.');
        $this->assertRequestActor($batch, $actor);
        $last = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->lockForUpdate()->first();
        if ($last?->status === 'pending') $this->fail('remnant_request_pending', '批次 '.$batch->batch_no.' 已有余料申请待仓库确认，不能重复提交。', 409);
        if ($last && (int) $last->requested_by_legacy_id !== (int) $this->actorId($actor))
            $this->fail('permission_denied', '请原登记人更正批次 '.$batch->batch_no.' 的余料后重新提交。', 403);
        $lines = $this->lines($batch, $rows);
        $this->assertSharedConservation($batch, $lines, false);
        $positive = collect($lines)->contains(fn ($line) => bccomp($line['measure_qty'], '0', 8) > 0);
        $batch->update(['remnants' => $lines]);
        if (! $positive) {
            $this->log($batch, 'remnant_zero_confirmed', '批次 '.$batch->batch_no.' 登记人明确确认真实零余料，直接结算', $actor,
                ['previous_receipt_id' => $last?->id, 'lines' => $lines]);
            $this->settleBatch($batch, $lines, $actor);
            return null;
        }
        $receipt = MfgCuttingRemnantReceipt::create(['receipt_no' => $this->numbers->next('mfg_cutting_remnant_receipt', 'MCRR'),
            'cutting_batch_id' => $batch->id, 'status' => 'pending', 'requested_lines' => $lines,
            'requested_by_legacy_id' => $this->actorId($actor), 'requested_by_name' => $this->actorName($actor),
            'requested_at' => now(), 'business_version' => 1]);
        $this->log($batch, 'remnant_requested', '批次 '.$batch->batch_no.' 已登记产出，余料待仓库确认（'.$receipt->receipt_no.'）', $actor,
            ['remnant_receipt_id' => $receipt->id]);
        return $receipt;
    }

    public function confirm(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $data, $actor) {
            [$receipt, $batch] = $this->pending($id, $data, $actor);
            $lines = $this->lines($batch, $data['lines'] ?? []);
            $output = $this->assertSharedConservation($batch, $lines, true);
            if ($output) foreach ($lines as &$line) $line['run_output_snapshot'] = $output;
            unset($line);
            $receipt->update(['status' => 'confirmed', 'confirmed_lines' => $lines,
                'confirmed_by_legacy_id' => $this->actorId($actor), 'confirmed_by_name' => $this->actorName($actor), 'confirmed_at' => now(),
                'business_version' => (int) $receipt->business_version + 1]);
            $this->settleBatch($batch, $lines, $actor, $receipt);
            return $receipt->fresh();
        }, 5);
    }

    /** 报废是明确处置，不能把驳回或测量零余料当作报废。全部成本留制造净耗，不另造损益。 */
    public function scrap(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        $this->assertPermission($actor, ['production.scrap.approve']);
        $reason = $this->reason($data['reason'] ?? null);
        return DB::transaction(function () use ($id, $data, $actor, $reason) {
            [$receipt, $batch] = $this->pending($id, $data, $actor);
            $measured = $this->lines($batch, $data['lines'] ?? [], false);
            $output = $this->assertSharedConservation($batch, $measured, true);
            if (! collect($measured)->contains(fn ($line) => bccomp($line['measure_qty'], '0', 8) > 0))
                $this->fail('scrap_measure_required', '整批报废必须明确本批真实废余料数量，零余料请按实际核对确认。');
            $lines = array_map(function ($line) use ($output) {
                $line['scrapped_measure_qty'] = $line['measure_qty'];
                $line['measure_qty'] = '0.00000000';
                $line['cost_amount'] = '0.0000';
                $line['consumed_measure'] = $line['input_measure'];
                $line['scrap_measure'] = $line['max_remnant_measure'];
                if ($output) $line['run_output_snapshot'] = $output;
                return $line;
            }, $measured);
            $receipt->update(['status' => 'confirmed', 'confirmed_lines' => $lines,
                'confirmed_by_legacy_id' => $this->actorId($actor), 'confirmed_by_name' => $this->actorName($actor), 'confirmed_at' => now(),
                'disposition' => ['action' => 'scrap', 'reason' => $reason, 'operator_legacy_id' => $this->actorId($actor),
                    'operator_name' => $this->actorName($actor), 'occurred_at' => now()->toDateTimeString(), 'measured_lines' => $measured],
                'business_version' => (int) $receipt->business_version + 1]);
            $this->settleBatch($batch, $lines, $actor, $receipt);
            return $receipt->fresh();
        }, 5);
    }

    public function reject(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        $reason = $this->reason($data['reason'] ?? null);
        return DB::transaction(function () use ($id, $data, $actor, $reason) {
            [$receipt, $batch] = $this->pending($id, $data, $actor);
            $receipt->update(['status' => 'rejected', 'rejected_by_legacy_id' => $this->actorId($actor), 'rejected_by_name' => $this->actorName($actor),
                'rejected_at' => now(), 'reject_reason' => $reason, 'business_version' => (int) $receipt->business_version + 1]);
            $batch->update(['business_version' => (int) $batch->business_version + 1]);
            $this->log($batch, 'remnant_rejected', '批次 '.$batch->batch_no.' 余料申请被驳回，请登记人更正重提：'.$reason, $actor, ['remnant_receipt_id' => $receipt->id]);
            return $receipt->fresh();
        }, 5);
    }

    public function cancel(int $id, array $data, object $actor): MfgCuttingRemnantReceipt
    {
        $this->assertPermission($actor, ['production.task.execute', 'production.work_order.edit']);
        return DB::transaction(function () use ($id, $data, $actor) {
            [$receipt, $batch] = $this->pending($id, $data, $actor);
            if ((int) $receipt->requested_by_legacy_id !== (int) $this->actorId($actor)) $this->fail('permission_denied', '只能撤回自己提交的余料申请。', 403);
            $receipt->update(['status' => 'cancelled', 'cancelled_by_legacy_id' => $this->actorId($actor), 'cancelled_at' => now(),
                'business_version' => (int) $receipt->business_version + 1]);
            $batch->update(['business_version' => (int) $batch->business_version + 1]);
            $this->log($batch, 'remnant_cancelled', '批次 '.$batch->batch_no.' 余料申请已撤回，产出事实保留，仍须登记真实余料', $actor, ['remnant_receipt_id' => $receipt->id]);
            return $receipt->fresh();
        }, 5);
    }

    private function pending(int $id, array $data, object $actor): array
    {
        $origin = MfgCuttingRemnantReceipt::query()->findOrFail($id);
        $batch = $this->lockBatch((int) $origin->cutting_batch_id);
        $receipt = MfgCuttingRemnantReceipt::query()->lockForUpdate()->findOrFail($id);
        if ((int) ($data['expected_version'] ?? 0) !== (int) $receipt->business_version) $this->fail('version_conflict', '余料申请已变化，请刷新后重新核对。', 409);
        if ($receipt->status !== 'pending' || $batch->status !== 'awaiting_confirm' || $batch->inventory_return_transaction_id)
            $this->fail('remnant_status_invalid', '批次 '.$batch->batch_no.' 的余料申请已处理，不能重复办理。', 409);
        return [$receipt, $batch];
    }

    private function lockBatch(int $id): MfgCuttingBatch
    {
        $batch = MfgCuttingBatch::query()->findOrFail($id);
        if ($batch->work_order_id) app(CuttingService::class)->lockedWorkOrder((int) $batch->work_order_id);
        elseif ($batch->cutting_run_id) MfgCuttingRun::query()->lockForUpdate()->findOrFail($batch->cutting_run_id);
        return MfgCuttingBatch::query()->lockForUpdate()->findOrFail($id);
    }

    /** 来源与产出来自冻结批次；请求只允许提交实测面积/长度与库位。 */
    private function lines(MfgCuttingBatch $batch, array $rows, bool $locations = true): array
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
            if ($input['mode'] === 'length' && bccomp($measure, bcdiv(bcmul($measure, '1000', 2), '1000', 8), 8) !== 0)
                $this->fail('remnant_precision_invalid', '管材余料以米登记，最多五位小数，必须能无损保存毫米长度。');
            $used = $batch->batch_scope === 'shared' ? null : ($output->get($input['source_key']) ?? collect())
                ->reduce(fn ($sum, $line) => bcadd($sum, (string) $line['measure_qty'], 8), '0.00000000');
            $max = $used === null ? (string) $input['input_measure'] : bcsub((string) $input['input_measure'], $used, 8);
            if (bccomp($measure, $max, 8) > 0) $this->fail('remnant_exceeds_source', '批次 '.$batch->batch_no.' 来源 '.($input['physical_no'] ?? $input['source_key']).' 的余料超过真实投入减实际产出的剩余量。');
            $warehouse = null; $location = null;
            if ($locations && bccomp($measure, '0', 8) > 0) {
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
            if ((float) ($line['length_mm'] ?? 0) <= 0 || (float) ($line['width_mm'] ?? 0) <= 0) $this->fail('cutting_output_dimensions_missing', '开料记录 '.$run->run_no.' 缺少冻结板件尺寸，不能核对余料。');
            $measure = bcdiv(bcmul(bcmul((string) $line['length_mm'], (string) $line['width_mm'], 12), (string) $line['actual_pieces'], 12), '1000000', 8);
            $actual = bcadd($actual, $measure, 8);
            $facts[] = array_intersect_key($line, array_flip(['line_key', 'component_item_id', 'length_mm', 'width_mm', 'thickness_mm', 'actual_pieces'])) + ['measure_qty' => $measure];
        }
        if (bccomp(bcadd($actual, $remaining, 8), $inputMeasure, 8) > 0)
            $this->fail('remnant_exceeds_actual_output', '开料记录 '.$run->run_no.' 的实际板件面积与本次及其他批次余料之和超过真实投入，请核对批次 '.$batch->batch_no.' 和关联余料申请。');
        return ['run_id' => $run->id, 'run_no' => $run->run_no, 'output_lines' => $facts,
            'input_measure' => $inputMeasure, 'actual_output_measure' => $actual, 'all_remnant_measure' => $remaining];
    }

    private function settleBatch(MfgCuttingBatch $batch, array $lines, object $actor, ?MfgCuttingRemnantReceipt $receipt = null): void
    {
        $returned = '0.0000'; $pool = '0.00000000';
        foreach ($lines as $line) {
            $returned = bcadd($returned, $line['cost_amount'], 4);
            $pool = bcadd($pool, $line['consumed_measure'], 8);
        }
        $net = bcsub((string) $batch->input_cost_amount, $returned, 4);
        if (bccomp($net, '0', 4) < 0) $this->fail('cost_invalid', '余料回收成本不能超过真实投入。');
        $batch->update(['remnants' => $lines, 'remnant_cost_amount' => $returned, 'net_cost_amount' => $net,
            'pool_measure' => $batch->batch_scope === 'shared' ? $pool : '0.00000000']);
        $wo = $batch->work_order_id ? MfgWorkOrder::query()->findOrFail($batch->work_order_id) : null;
        $posted = $this->inventory->postManufacturingCuttingSettlement($batch, $wo, $this->actorId($actor));
        foreach ($lines as &$line) $line = array_replace($line, $posted['lines'][$line['source_key']] ?? []);
        unset($line);
        $batch->refresh();
        $movements = [];
        $scrap = ($receipt?->disposition['action'] ?? null) === 'scrap';
        foreach ($batch->input_lines as $input) {
            if (empty($input['physical_material_id'])) continue;
            $line = collect($lines)->firstWhere('source_key', $input['source_key']);
            $movements[$input['source_key']] = $this->physicals->completeManufacturingCut($batch, $input,
                bcsub((string) $input['cost_amount'], (string) $line['cost_amount'], 4), $this->actorId($actor) ?? 0,
                $scrap && bccomp((string) ($line['scrapped_measure_qty'] ?? '0'), '0', 8) > 0);
        }
        $batch->update(['remnants' => $lines, 'status' => 'settled', 'business_version' => (int) $batch->business_version + 1,
            'settled_by_legacy_id' => $this->actorId($actor), 'settled_by_name' => $this->actorName($actor), 'settled_at' => now()]);
        if ($receipt) {
            $facts = ['confirmed_lines' => $lines, 'inventory_transaction_id' => $posted['transaction']?->id,
                'confirmed_at' => $posted['transaction']?->posted_at ?? $receipt->confirmed_at];
            if ($scrap) $facts['disposition'] = $receipt->disposition + ['physical_movement_ids' => $movements];
            $receipt->update($facts);
        }
        if ($wo) {
            $wo->update(['business_version' => (int) $wo->business_version + 1]);
            app(WorkOrderService::class)->refreshCompletion($wo, $actor);
        }
        $this->log($batch, $scrap ? 'remnant_scrapped' : 'cutting_settled', '批次 '.$batch->batch_no.($scrap ? ' 余料已明确整批报废，成本留在制造净耗' : ' 余料确认及净耗结算完成'),
            $actor, ['remnant_receipt_id' => $receipt?->id, 'physical_movement_ids' => $movements, 'lines' => $lines]);
        if ($batch->cutting_run_id) app(CuttingRunService::class)->finalizeAwaitingRun((int) $batch->cutting_run_id, $actor);
    }

    public function assertRequestActor(MfgCuttingBatch $batch, object $actor): void
    {
        $this->assertPermission($actor, ['production.task.execute', 'production.work_order.edit']);
        $auth = app(AuthContextService::class);
        if (! $batch->work_order_id || $auth->isSuperAdmin($actor) || in_array('production.work_order.edit', $auth->permissionCodes($actor), true)) return;
        if (! MfgTaskParticipant::query()->where('work_order_operation_id', $batch->work_order_operation_id)
            ->where('employee_legacy_id', $this->actorId($actor))->where('is_active', true)->exists()) $this->fail('permission_denied', '只能登记本人当前参与工序的余料。', 403);
    }

    private function assertPermission(object $actor, array $any): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($any, $auth->permissionCodes($actor))) $this->fail('permission_denied', '当前账号没有这项余料操作权限。', 403);
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);
        if ($reason === '' || mb_strlen($reason) > 500) $this->fail('reason_required', '请填写500字以内的原因。');
        return $reason;
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
        $confirm = $pending && in_array('inventory.inbound.confirm', $codes, true);
        $row = $receipt->toArray() + ['batch_no' => $batch?->batch_no, 'batch_scope' => $batch?->batch_scope,
            'batch_status' => $batch?->status, 'batch_business_version' => (int) $batch?->business_version,
            'work_order_id' => $batch?->work_order_id, 'cutting_run_id' => $batch?->cutting_run_id,
            'status_label' => self::STATUS_LABELS[$receipt->status] ?? $receipt->status,
            'waiting_seconds' => $seconds, 'waiting_hours' => round($seconds / 3600, 1), 'overdue' => $pending && $seconds > 86400,
            'waiting_reason' => $batch ? $this->waitingReason($batch) : null, 'requires_serial_check' => false,
            'cost_status' => $receipt->status === 'confirmed' ? 'confirmed' : 'pending_confirmation',
            'cost_status_label' => $receipt->status === 'confirmed' ? '已确认' : '待确认',
            'can_resubmit' => $batch?->status === 'awaiting_confirm' && in_array($receipt->status, ['rejected', 'cancelled'], true)
                && $actor && (int) $receipt->requested_by_legacy_id === (int) $this->actorId($actor)
                && (bool) array_intersect(['production.task.execute', 'production.work_order.edit'], $codes),
            'actions' => ['confirm' => (bool) $confirm, 'reject' => (bool) $confirm,
                'scrap' => $confirm && in_array('production.scrap.approve', $codes, true),
                'cancel' => $pending && $actor && (int) $receipt->requested_by_legacy_id === (int) $this->actorId($actor)
                    && (bool) array_intersect(['production.task.execute', 'production.work_order.edit'], $codes)]];
        unset($row['batch']);
        foreach ($row['requested_lines'] as &$line) {
            $line['cost_amount'] = null;
            $line['cost_status'] = 'pending_confirmation';
        }
        unset($line);
        return $amounts ? $row : app(InventoryAmountVisibilityService::class)->redact($row, true);
    }

    public function batchConfirmation(MfgCuttingBatch $batch, bool $amounts, ?object $actor = null): array
    {
        $actor ??= app(AuthContextService::class)->currentUser(request());
        $receipt = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->first();
        $dto = $receipt ? $this->present($receipt, $amounts, $actor) : null;
        return ['remnant_receipt' => $dto,
            'can_resubmit' => (bool) ($dto['can_resubmit'] ?? false),
            'waiting_reason' => $this->waitingReason($batch),
            'cost_status' => $batch->status === 'settled' ? 'confirmed' : 'pending_confirmation',
            'cost_status_label' => $batch->status === 'settled' ? '已确认' : '待确认'];
    }

    public function waitingReason(MfgCuttingBatch $batch): ?string
    {
        if ($batch->status !== 'awaiting_confirm') return null;
        $receipt = MfgCuttingRemnantReceipt::query()->where('cutting_batch_id', $batch->id)->orderByDesc('id')->first();
        $text = '批次 '.$batch->batch_no.' ';
        if ($receipt?->status === 'rejected') return $text.'余料被驳回，请登记人更正后重提。';
        if ($receipt?->status === 'cancelled') return $text.'余料申请已撤回，请重新提交真实余料。';
        if ($batch->cutting_run_id && ($run = MfgCuttingRun::query()->find($batch->cutting_run_id))?->status === 'running')
            return $text.'余料待确认；开料记录 '.$run->run_no.' 请先「切完结束」登记实际件数并停止计时。';
        return $text.'余料待仓库确认，最终净耗和开料池尚不可分配。';
    }
}
