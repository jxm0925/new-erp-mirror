<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionKittingConfirmation;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

class ProductionKittingService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductionLaborSessionService $laborSessions,
    ) {}

    public function requirements(int $taskId, string $targetType, int $targetId, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.kitting.view');
        [$task, $target] = $this->taskTarget($taskId, $targetType, $targetId);
        $this->responsible($task, $user);
        return $this->materialRows($targetType, $targetId)->values()->all();
    }

    /** Search before pagination; never load the entire target BOM into a selector. */
    public function materialOptions(int $taskId, string $targetType, int $targetId, array $filters, object $user, array $permissions): array
    {
        $mode = $filters['mode'] ?? 'supplement';
        $this->permission($permissions, $mode === 'return' ? 'production.material_return.create' : 'production.material_supplement.request');
        [$task] = $this->taskTarget($taskId, $targetType, $targetId);
        $this->responsible($task, $user);
        $query = DB::table('erp_production_target_material_requirements as target_requirement')
            ->join('erp_work_order_material_requirements as requirement', 'requirement.id', '=', 'target_requirement.material_requirement_id')
            ->join('erp_items as item', 'item.id', '=', 'target_requirement.component_item_id')
            ->where('target_requirement.target_type', $targetType)->where('target_requirement.target_id', $targetId)
            ->where('target_requirement.work_order_id', $task->work_order_id);

        if ($mode === 'return') {
            // A selectable return is an actual receipt source, not just an item. Subtract
            // active return reservations per warehouse/location/batch before counting pages.
            $received = DB::table('erp_material_receipt_lines as receipt_line')
                ->join('erp_material_delivery_lines as delivery_line', 'delivery_line.id', '=', 'receipt_line.delivery_line_id')
                ->join('erp_material_deliveries as delivery', 'delivery.id', '=', 'delivery_line.delivery_id')
                ->join('erp_material_picking_task_lines as pick_line', 'pick_line.id', '=', 'delivery_line.picking_task_line_id')
                ->where('delivery.production_target_type', $targetType)->where('delivery.production_target_id', $targetId)
                ->where('receipt_line.accepted_qty', '>', 0)
                ->selectRaw("delivery_line.material_requirement_id, pick_line.warehouse_id, pick_line.location_id, COALESCE(delivery_line.batch_no, '') as batch_no, SUM(receipt_line.accepted_qty) as received_base_qty")
                ->groupBy('delivery_line.material_requirement_id', 'pick_line.warehouse_id', 'pick_line.location_id', DB::raw("COALESCE(delivery_line.batch_no, '')"));
            $returned = DB::table('erp_production_material_return_lines as return_line')
                ->join('erp_production_material_returns as material_return', 'material_return.id', '=', 'return_line.return_id')
                ->where('material_return.target_type', $targetType)->where('material_return.target_id', $targetId)
                ->whereIn('material_return.status', ['SUBMITTED', 'WAIT_QUALITY', 'COMPLETED', 'QUARANTINED'])
                ->selectRaw("return_line.material_requirement_id, return_line.warehouse_id, return_line.location_id, COALESCE(return_line.batch_no, '') as batch_no, SUM(return_line.return_base_qty) as returned_base_qty")
                ->groupBy('return_line.material_requirement_id', 'return_line.warehouse_id', 'return_line.location_id', DB::raw("COALESCE(return_line.batch_no, '')"));
            $query->joinSub($received, 'receipt_source', fn ($join) => $join->on('receipt_source.material_requirement_id', '=', 'requirement.id'))
                ->leftJoinSub($returned, 'return_source', function ($join) {
                    $join->on('return_source.material_requirement_id', '=', 'receipt_source.material_requirement_id')
                        ->on('return_source.warehouse_id', '=', 'receipt_source.warehouse_id')
                        ->on('return_source.location_id', '=', 'receipt_source.location_id')
                        ->on('return_source.batch_no', '=', 'receipt_source.batch_no');
                })
                ->join('erp_warehouses as warehouse', 'warehouse.id', '=', 'receipt_source.warehouse_id')
                ->join('erp_locations as location', 'location.id', '=', 'receipt_source.location_id')
                ->whereRaw('receipt_source.received_base_qty - COALESCE(return_source.returned_base_qty, 0) > 0.00000001');
        } else {
            // One selectable frozen requirement even if several supply rules reference it.
            // Different cut lengths of the same Item must remain distinct.
            $query->whereIn('target_requirement.id', DB::table('erp_production_target_material_requirements')
                ->where('target_type', $targetType)->where('target_id', $targetId)->where('requirement_kind', 'standard')
                ->selectRaw('MIN(id)')->groupBy('material_requirement_id'));
        }

        // Classification is small explicit tree metadata, never a full material list.
        $categories = DB::table('erp_item_categories')->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'category_name']);
        $availableIds = (clone $query)->distinct()->pluck('item.category_id')->filter()->map(fn ($id) => (int) $id)->all();
        $categoryMap = $categories->keyBy('id');
        $visibleIds = array_fill_keys($availableIds, true);
        foreach ($availableIds as $id) {
            $visited = [];
            while ($id && ! isset($visited[$id]) && isset($categoryMap[$id])) {
                $visited[$id] = true; $visibleIds[$id] = true;
                $id = (int) $categoryMap[$id]->parent_id;
            }
        }
        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $categoryId = (int) $filters['category_id'];
            if ($categoryId === 0) $query->whereNull('item.category_id');
            else {
                $ids = [$categoryId];
                do {
                    $newIds = $categories->filter(fn ($row) => in_array((int) $row->parent_id, $ids, true) && ! in_array((int) $row->id, $ids, true))->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $ids = array_merge($ids, $newIds);
                } while ($newIds !== []);
                $query->whereIn('item.category_id', $ids);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%'.addcslashes($keyword, '\\%_').'%';
            $query->where(fn ($search) => $search->where('item.item_code', 'like', $like)->orWhere('item.item_name', 'like', $like)->orWhere('item.spec', 'like', $like)->orWhere('item.model', 'like', $like));
        }
        $query->select(['target_requirement.id', 'requirement.id as material_requirement_id', 'item.id as component_item_id',
            'item.item_code as code', 'item.item_name as name', 'item.spec', 'item.model', 'item.category_id', 'requirement.unit_name_snapshot as unit_name',
            'requirement.cut_length_mm_snapshot as cut_length_mm', 'requirement.required_piece_qty']);
        if ($mode === 'return') {
            $query->addSelect(['receipt_source.warehouse_id', 'receipt_source.location_id', 'receipt_source.batch_no', 'receipt_source.received_base_qty',
                'warehouse.warehouse_name', 'location.location_name'])
                ->selectRaw('receipt_source.received_base_qty - COALESCE(return_source.returned_base_qty, 0) as returnable_base_qty');
        }
        $query->orderBy('target_requirement.id');
        if ($mode === 'return') $query->orderBy('receipt_source.warehouse_id')->orderBy('receipt_source.location_id')->orderBy('receipt_source.batch_no');
        $page = $query->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = collect($page->items())->map(function ($row) use ($mode) {
            $data = (array) $row;
            $data['key'] = $mode === 'return'
                ? 'r:'.$row->material_requirement_id.':'.$row->warehouse_id.':'.$row->location_id.':'.base64_encode($row->batch_no)
                : 's:'.$row->material_requirement_id;
            return $data;
        })->all();
        return ['data' => $rows, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
            'categories' => $categories->filter(fn ($row) => isset($visibleIds[$row->id]))->values()->all()];
    }

    public function confirm(int $taskId, string $targetType, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.kitting.confirm');
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['task_id' => $taskId, 'target_type' => $targetType, 'target_id' => $targetId];
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->recordWorkstationStockAttempts($taskId, $targetType, $targetId, $payload, $user, $commandId, $hash);

        return DB::transaction(function () use ($taskId, $targetType, $targetId, $payload, $user, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->command_type !== 'confirm_kitting' || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                if ($existing->status !== 'succeeded') $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
                return $existing->response_snapshot;
            }
            $ledger = ProductionExecutionCommand::create([
                'client_command_id' => $commandId, 'command_type' => 'confirm_kitting',
                'aggregate_type' => $targetType, 'aggregate_id' => $targetId, 'request_hash' => $hash,
                'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now(),
            ]);
            [$task, $target] = $this->taskTarget($taskId, $targetType, $targetId, true);
            $this->responsible($task, $user);
            if ((int) $target->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409);
            if (! in_array($target->status, ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true)) $this->fail('invalid_state', '当前生产目标状态不能确认齐套。');
            if ($target->started_at) $this->fail('operation_already_started_use_resume', '该工序已经正式开工，请使用恢复我的作业。', 409);

            $pendingHandover = DB::table('erp_production_operation_handovers')
                ->where('target_target_type', $targetType)->where('target_target_id', $targetId)
                ->where('status', 'WAIT_RECEIVE')->exists();
            if ($pendingHandover) $this->fail('handover_not_received', '上一工序产出尚未完成交接接收，不能确认齐套。');

            if (! $target->kitting_required) $this->fail('kitting_not_required', '当前工序不需要齐套确认。');
            $this->applyWorkstationStockFacts($task, $targetType, $targetId, $commandId);

            $rows = $this->materialRows($targetType, $targetId);
            $shortages = $rows->filter(fn (array $row): bool => $row['shortage_base_qty'] > 0.00000001)->values();
            if ($shortages->isNotEmpty()) $this->fail('materials_not_ready', '当前工序仍有必需物料未到位，不能确认齐套。', 422, ['shortages' => $shortages->all()]);

            $confirmation = ProductionKittingConfirmation::create([
                'confirmation_no' => $this->numbers->next('production_kitting', 'KIT'),
                'work_order_id' => $task->work_order_id, 'task_id' => $task->id,
                'target_type' => $targetType, 'target_id' => $targetId,
                'target_routing_operation_id_snapshot' => $target->routing_operation_id_snapshot,
                'status' => 'CONFIRMED', 'required_materials_snapshot' => $rows->pluck('required')->values()->all(),
                'received_materials_snapshot' => $rows->pluck('received')->values()->all(),
                'shortage_materials_snapshot' => [], 'confirmed_by_legacy_id' => $this->userId($user),
                'confirmed_at' => now(), 'business_version' => 1,
            ]);
            foreach ($rows as $row) {
                $confirmation->lines()->create([
                    'material_supply_rule_snapshot_id' => $row['material_supply_rule_snapshot_id'],
                    'component_item_id' => $row['component_item_id'],
                    'required_base_qty_snapshot' => $row['required_base_qty'],
                    'received_base_qty_snapshot' => $row['satisfied_base_qty'],
                    'shortage_base_qty_snapshot' => 0,
                    'source_facts_snapshot' => $row['source_facts'],
                ]);
            }
            DB::table('erp_production_workstation_stock_confirmations')
                ->where('task_id', $task->id)->where('target_type', $targetType)->where('target_id', $targetId)
                ->where('client_command_id', $commandId)
                ->update(['kitting_confirmation_id' => $confirmation->id, 'updated_at' => now()]);

            $target->kitting_confirmed_at = $confirmation->confirmed_at;
            $target->kitting_confirmed_by_legacy_id = $this->userId($user);
            // 对需要齐套的工序，负责人亲自确认齐套就是接受现场输入并开始实际加工的业务动作。
            // 此处必须与齐套事实共用同一事务，避免出现“已齐套但未开始计时”的半完成状态。
            $target->started_at = $target->started_at ?: $confirmation->confirmed_at;
            $target->paused_at = null;
            $target->status = 'IN_PROGRESS';
            $target->business_version = (int) $target->business_version + 1;
            $target->save();
            $task->targets()->where('target_type', $targetType)->where('target_id', $targetId)->update(['status_snapshot' => 'IN_PROGRESS']);
            if ($task->status !== 'IN_PROGRESS') {
                $task->update(['status' => 'IN_PROGRESS', 'business_version' => (int) $task->business_version + 1]);
            }
            $this->laborSessions->start($task, $target, $targetType, $this->userId($user), 'owner', 1, $confirmation->confirmed_at, $payload);

            $result = ['id' => (int) $confirmation->id, 'confirmation_no' => $confirmation->confirmation_no,
                'status' => $confirmation->status, 'target_status' => $target->status,
                'target_business_version' => (int) $target->business_version,
                'confirmed_at' => $confirmation->confirmed_at->toISOString(),
                'started_at' => $target->started_at->toISOString()];
            $ledger->update(['result_type' => 'kitting_confirmation', 'result_id' => $confirmation->id,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function materialRows(string $targetType, int $targetId)
    {
        $latestWorkstationChecks = DB::table('erp_production_workstation_stock_confirmations')
            ->selectRaw('target_material_requirement_id, MAX(id) as latest_id')
            ->groupBy('target_material_requirement_id');
        $rows = DB::table('erp_production_target_material_requirements as requirement')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'requirement.material_supply_rule_snapshot_id')
            ->join('erp_work_order_material_requirements as work_requirement', 'work_requirement.id', '=', 'requirement.material_requirement_id')
            ->join('erp_items as item', 'item.id', '=', 'requirement.component_item_id')
            ->where('requirement.target_type', $targetType)->where('requirement.target_id', $targetId)
            ->where('supply.participates_in_kitting_snapshot', true)
            ->leftJoinSub($latestWorkstationChecks, 'latest_workstation', fn ($join) => $join->on('latest_workstation.target_material_requirement_id', '=', 'requirement.id'))
            ->leftJoin('erp_production_workstation_stock_confirmations as workstation', 'workstation.id', '=', 'latest_workstation.latest_id')
            ->select([
                'requirement.id', 'requirement.material_requirement_id', 'requirement.material_supply_rule_snapshot_id', 'requirement.component_item_id',
                'requirement.required_base_qty', 'requirement.satisfied_base_qty', 'requirement.returned_base_qty',
                'requirement.cut_length_mm_snapshot', 'requirement.required_piece_qty_snapshot',
                'work_requirement.received_qty as work_order_received_qty',
                'supply.supply_mode_snapshot', 'item.item_code', 'item.item_name',
                'workstation.workstation_snapshot', 'workstation.onsite_available_base_qty_snapshot',
                'workstation.confirmed_base_qty', 'workstation.confirmed_by_legacy_id', 'workstation.confirmed_at',
            ])
            ->orderBy('requirement.id')->get();

        $requirementIds = $rows->pluck('material_requirement_id')->map(fn ($id) => (int) $id)->unique()->values();
        $returnSources = DB::table('erp_material_receipt_lines as receipt_line')
            ->join('erp_material_delivery_lines as delivery_line', 'delivery_line.id', '=', 'receipt_line.delivery_line_id')
            ->join('erp_material_deliveries as delivery', 'delivery.id', '=', 'delivery_line.delivery_id')
            ->join('erp_material_picking_task_lines as pick_line', 'pick_line.id', '=', 'delivery_line.picking_task_line_id')
            ->join('erp_warehouses as warehouse', 'warehouse.id', '=', 'pick_line.warehouse_id')
            ->join('erp_locations as location', 'location.id', '=', 'pick_line.location_id')
            ->whereIn('delivery_line.material_requirement_id', $requirementIds)
            ->where('delivery.production_target_type', $targetType)->where('delivery.production_target_id', $targetId)
            ->where('receipt_line.accepted_qty', '>', 0)
            ->selectRaw('delivery_line.material_requirement_id, pick_line.warehouse_id, pick_line.location_id, delivery_line.batch_no, warehouse.warehouse_code, warehouse.warehouse_name, location.location_code, location.location_name, SUM(receipt_line.accepted_qty) as received_base_qty')
            ->groupBy('delivery_line.material_requirement_id', 'pick_line.warehouse_id', 'pick_line.location_id', 'delivery_line.batch_no', 'warehouse.warehouse_code', 'warehouse.warehouse_name', 'location.location_code', 'location.location_name')
            ->get()->groupBy(fn ($row) => (int) $row->material_requirement_id);

        $activeReturns = DB::table('erp_production_material_return_lines as return_line')
            ->join('erp_production_material_returns as material_return', 'material_return.id', '=', 'return_line.return_id')
            ->whereIn('return_line.material_requirement_id', $requirementIds)
            ->where('material_return.target_type', $targetType)->where('material_return.target_id', $targetId)
            ->whereIn('material_return.status', ['SUBMITTED', 'WAIT_QUALITY', 'COMPLETED', 'QUARANTINED'])
            ->selectRaw('return_line.material_requirement_id, return_line.warehouse_id, return_line.location_id, COALESCE(return_line.batch_no, \'\') as normalized_batch_no, SUM(return_line.return_base_qty) as returned_base_qty')
            ->groupBy('return_line.material_requirement_id', 'return_line.warehouse_id', 'return_line.location_id', DB::raw('COALESCE(return_line.batch_no, \'\')'))
            ->get()->keyBy(fn ($row) => implode('|', [(int) $row->material_requirement_id, (int) $row->warehouse_id, (int) $row->location_id, (string) $row->normalized_batch_no]));

        return $rows->map(function ($row) use ($returnSources, $activeReturns): array {
                $required = (float) $row->required_base_qty;
                $grossReceived = (float) $row->satisfied_base_qty;
                $returned = (float) $row->returned_base_qty;
                $received = max(0, $grossReceived - $returned);
                $mode = $row->supply_mode_snapshot === 'line_side_stock' ? 'workstation_stock' : $row->supply_mode_snapshot;
                $sourceFacts = ['supply_mode' => $mode];
                if ($mode === 'workstation_stock' && $row->workstation_snapshot !== null) {
                    $sourceFacts += [
                        'workstation' => $row->workstation_snapshot,
                        'onsite_available_base_qty_snapshot' => (float) $row->onsite_available_base_qty_snapshot,
                        'confirmed_base_qty' => (float) $row->confirmed_base_qty,
                        'confirmed_by_legacy_id' => (int) $row->confirmed_by_legacy_id,
                        'confirmed_at' => $row->confirmed_at,
                    ];
                }
                $sources = collect($returnSources->get((int) $row->material_requirement_id, collect()))->map(function ($source) use ($activeReturns, $row): array {
                    $key = implode('|', [(int) $row->material_requirement_id, (int) $source->warehouse_id, (int) $source->location_id, (string) ($source->batch_no ?? '')]);
                    $sourceReceived = (float) $source->received_base_qty;
                    $sourceReturned = (float) optional($activeReturns->get($key))->returned_base_qty;
                    return [
                        'warehouse_id' => (int) $source->warehouse_id,
                        'warehouse_code' => $source->warehouse_code,
                        'warehouse_name' => $source->warehouse_name,
                        'location_id' => (int) $source->location_id,
                        'location_code' => $source->location_code,
                        'location_name' => $source->location_name,
                        'batch_no' => $source->batch_no,
                        'received_base_qty' => $sourceReceived,
                        'returnable_base_qty' => max(0, $sourceReceived - $sourceReturned),
                    ];
                })->filter(fn (array $source): bool => $source['returnable_base_qty'] > 0.00000001)->values()->all();
                return [
                    'id' => (int) $row->id, 'material_requirement_id' => (int) $row->material_requirement_id,
                    'material_supply_rule_snapshot_id' => (int) $row->material_supply_rule_snapshot_id,
                    'component_item_id' => (int) $row->component_item_id, 'component_item_code' => $row->item_code,
                    'component_item_name' => $row->item_name, 'required_base_qty' => $required,
                    'cut_length_mm' => $row->cut_length_mm_snapshot === null ? null : (float) $row->cut_length_mm_snapshot,
                    'required_piece_qty' => $row->required_piece_qty_snapshot === null ? null : (float) $row->required_piece_qty_snapshot,
                    'satisfied_base_qty' => $received, 'gross_received_base_qty' => $grossReceived,
                    'returned_base_qty' => $returned, 'shortage_base_qty' => max(0, $required - $received),
                    'work_order_received_base_qty' => (float) $row->work_order_received_qty,
                    'return_sources' => $sources,
                    'required' => ['component_item_id' => (int) $row->component_item_id, 'base_qty' => $required],
                    'received' => ['component_item_id' => (int) $row->component_item_id, 'base_qty' => $received],
                    'source_facts' => $sourceFacts,
                ];
            });
    }

    private function recordWorkstationStockAttempts(int $taskId, string $targetType, int $targetId, array $payload, object $user, string $commandId, string $hash): void
    {
        $command = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->first();
        if ($command) {
            if ($command->command_type !== 'confirm_kitting' || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
            return;
        }
        DB::transaction(function () use ($taskId, $targetType, $targetId, $payload, $user, $commandId, $hash): void {
            $command = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($command) {
                if ($command->command_type !== 'confirm_kitting' || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                return;
            }
            [$task, $target] = $this->taskTarget($taskId, $targetType, $targetId, true);
            $this->responsible($task, $user);
            if ((int) $target->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409);
            if (! in_array($target->status, ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true)) $this->fail('invalid_state', '当前生产目标状态不能确认齐套。');
            if ($target->started_at) $this->fail('operation_already_started_use_resume', '该工序已经正式开工，请使用恢复我的作业。', 409);
            if (! $target->kitting_required) $this->fail('kitting_not_required', '当前工序不需要齐套确认。');
            if (DB::table('erp_production_operation_handovers')
                ->where('target_target_type', $targetType)->where('target_target_id', $targetId)
                ->where('status', 'WAIT_RECEIVE')->exists()) {
                $this->fail('handover_not_received', '上一工序产出尚未完成交接接收，不能确认齐套。');
            }
            $this->laborSessions->assertStartAllowed($targetType, $targetId, $this->userId($user), $payload);

            $requirements = DB::table('erp_production_target_material_requirements as requirement')
                ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'requirement.material_supply_rule_snapshot_id')
                ->where('requirement.target_type', $targetType)->where('requirement.target_id', $targetId)
                ->whereIn('supply.supply_mode_snapshot', ['workstation_stock', 'line_side_stock'])
                ->select('requirement.*')->lockForUpdate()->get();
            if ($requirements->isEmpty()) return;

            $provided = collect((array) ($payload['workstation_stock_confirmations'] ?? []));
            if ($provided->pluck('requirement_id')->map(fn ($id) => (int) $id)->duplicates()->isNotEmpty()) $this->fail('workstation_stock_confirmation_duplicate', '同一项工位常备料不能重复确认。');
            $provided = $provided->keyBy(fn (array $row): int => (int) ($row['requirement_id'] ?? 0));
            $validIds = $requirements->pluck('id')->map(fn ($id) => (int) $id);
            if ($provided->keys()->map(fn ($id) => (int) $id)->diff($validIds)->isNotEmpty()) $this->fail('workstation_stock_requirement_invalid', '提交的工位常备料不属于当前生产目标。');

            $existing = DB::table('erp_production_workstation_stock_confirmations')->where('client_command_id', $commandId)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn ($row) => $row->request_hash !== $hash)) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                return;
            }

            $defaultWorkstation = trim((string) DB::table('erp_work_orders')->where('id', $task->work_order_id)->value('production_location_name'));
            foreach ($requirements as $requirement) {
                $input = $provided->get((int) $requirement->id);
                if (! is_array($input)) $this->fail('workstation_stock_confirmation_required', '工位常备料必须逐项核对现场可用数量后才能确认齐套。', 422, ['requirement_id' => (int) $requirement->id]);
                $onsite = (float) ($input['onsite_available_base_qty'] ?? 0);
                $required = (float) $requirement->required_base_qty;
                $shortage = max(0, $required - $onsite);
                $workstation = trim((string) ($input['workstation'] ?? $defaultWorkstation));
                if ($workstation === '') $this->fail('workstation_required', '确认工位常备料时必须明确具体工位。');
                $attempt = (int) DB::table('erp_production_workstation_stock_confirmations')->where('target_material_requirement_id', $requirement->id)->max('attempt_no') + 1;
                $now = now();
                DB::table('erp_production_workstation_stock_confirmations')->insert([
                    'work_order_id' => $task->work_order_id, 'task_id' => $task->id,
                    'target_type' => $targetType, 'target_id' => $targetId,
                    'target_material_requirement_id' => $requirement->id, 'attempt_no' => $attempt,
                    'client_command_id' => $commandId, 'request_hash' => $hash, 'workstation_snapshot' => $workstation,
                    'component_item_id' => $requirement->component_item_id,
                    'required_base_qty_snapshot' => $required, 'onsite_available_base_qty_snapshot' => $onsite,
                    'shortage_base_qty_snapshot' => $shortage, 'result' => $shortage > 0.00000001 ? 'INSUFFICIENT' : 'SUFFICIENT',
                    'confirmed_base_qty' => $shortage > 0.00000001 ? 0 : $required,
                    'confirmed_by_legacy_id' => $this->userId($user), 'confirmed_at' => $now,
                    'fact_snapshot' => json_encode(['source' => 'workstation_stock', 'basis' => 'onsite_count'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }, 5);
    }

    private function applyWorkstationStockFacts(ProductionTask $task, string $targetType, int $targetId, string $commandId): void
    {
        $checks = DB::table('erp_production_workstation_stock_confirmations')
            ->where('client_command_id', $commandId)->where('task_id', $task->id)
            ->where('target_type', $targetType)->where('target_id', $targetId)->lockForUpdate()->get();
        $insufficient = $checks->where('result', 'INSUFFICIENT');
        if ($insufficient->isNotEmpty()) $this->fail('workstation_stock_insufficient', '工位常备料现场可用数量不足，不能确认齐套。', 422, [
            'shortages' => $insufficient->map(fn ($row) => ['requirement_id' => (int) $row->target_material_requirement_id,
                'required_base_qty' => (float) $row->required_base_qty_snapshot, 'onsite_available_base_qty' => (float) $row->onsite_available_base_qty_snapshot,
                'shortage_base_qty' => (float) $row->shortage_base_qty_snapshot])->values()->all(),
        ]);
        foreach ($checks as $check) {
            $requirement = DB::table('erp_production_target_material_requirements')->where('id', $check->target_material_requirement_id)->lockForUpdate()->first();
            if (! $requirement) $this->fail('workstation_stock_requirement_invalid', '工位常备料需求不存在。', 409);
            DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
                'satisfied_base_qty' => (float) $requirement->returned_base_qty + (float) $check->required_base_qty_snapshot, 'status' => 'SATISFIED',
                'business_version' => (int) $requirement->business_version + 1, 'updated_at' => now(),
            ]);
        }
    }

    private function taskTarget(int $taskId, string $targetType, int $targetId, bool $lock = false): array
    {
        $taskQuery = ProductionTask::query();
        if ($lock) $taskQuery->lockForUpdate();
        $task = $taskQuery->find($taskId);
        if (! $task || ! $task->targets()->where('target_type', $targetType)->where('target_id', $targetId)->exists()) $this->fail('task_target_not_found', '任务中不存在该生产执行目标。', 404);
        $model = $targetType === 'unit_operation' ? ProductionUnitOperation::class : ($targetType === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $targetQuery = $model::query();
        if ($lock) $targetQuery->lockForUpdate();
        $target = $targetQuery->find($targetId);
        if (! $target) $this->fail('task_target_not_found', '生产执行目标不存在。', 404);
        return [$task, $target];
    }

    private function responsible(ProductionTask $task, object $user): void { if ((int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('responsible_user_required', '只有当前任务负责人可以确认齐套。', 403); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
