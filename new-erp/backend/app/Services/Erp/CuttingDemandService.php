<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\WorkOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One versioned demand identity per formal production target requirement. */
final class CuttingDemandService
{
    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers,
        private readonly ProductionDataScopeResolver $scope,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        [$page, $size] = $this->pagination($filters);
        $query = $this->baseQuery();
        $this->applyVisibility($query, $user, $permissions, $super);

        if (! empty($filters['item_id'])) {
            $query->where('d.item_id', (int) $filters['item_id']);
        }
        if (! empty($filters['consumer_work_order_id'])) {
            $query->where('source.work_order_id', (int) $filters['consumer_work_order_id']);
        }
        if (! empty($filters['source_requirement_id'])) {
            $query->where('d.source_requirement_id', (int) $filters['source_requirement_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('d.status', (string) $filters['status']);
        }
        if (! empty($filters['keyword'])) {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $query->where(function (Builder $builder) use ($keyword): void {
                $builder->where('d.demand_no', 'like', $keyword)
                    ->orWhere('item.item_code', 'like', $keyword)
                    ->orWhere('item.item_name', 'like', $keyword)
                    ->orWhere('item.spec', 'like', $keyword)
                    ->orWhere('consumer.work_order_no', 'like', $keyword)
                    ->orWhere('configuration.configuration_no', 'like', $keyword);
            });
        }

        $result = $query->orderByDesc('d.id')->paginate($size, ['*'], 'page', $page);

        return [
            'data' => $this->projections(collect($result->items())),
            'meta' => [
                'current_page' => $page,
                'per_page' => $size,
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ];
    }

    public function show(int $id, array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        [$page, $size] = $this->revisionPagination($filters);
        $row = $this->visibleRow($id, $user, $permissions, $super);
        $projection = $this->projections(collect([$row]))[0];
        $revisions = DB::table('erp_cutting_demand_revisions')->where('demand_id', $id)
            ->orderByDesc('id')->paginate($size, ['*'], 'revision_page', $page);

        $projection['revisions'] = [
            'data' => collect($revisions->items())->map(fn (object $revision): array => $this->revisionProjection($revision))->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $size,
                'total' => $revisions->total(),
                'last_page' => $revisions->lastPage(),
            ],
        ];

        return $projection;
    }

    public function generate(array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.plan');
        $this->assertFields($payload, [
            'client_command_id', 'expected_version', 'source_requirement_id',
            'producer_work_order_id', 'producer_stage_id', 'configuration_id',
        ]);
        $normalized = $this->generationPayload($payload);
        $this->generationContext($normalized, $user, $permissions, $super, false);

        return $c->run('generate_cutting_demand', $normalized['source_requirement_id'], $normalized, $user,
            function () use ($c, $normalized, $user, $permissions, $super): array {
                if ($normalized['expected_version'] !== 0) {
                    $c->fail('version_required', '首次生成正式下料需求时版本必须为0。');
                }
                $context = $this->generationContext($normalized, $user, $permissions, $super, true);
                [$demand, $created] = $this->ensureDemand(
                    $context['target'],
                    (int) $context['item']->id,
                    $normalized['configuration_id'],
                    $normalized['producer_stage_id'],
                    $user,
                );
                $response = $this->projections(collect([
                    $this->baseQuery()->where('d.id', $demand->id)->lockForUpdate()->first(),
                ]))[0];
                $response['generated_now'] = $created;
                if ($created) {
                    $c->event('demand', (int) $demand->id, 'generate', $user, null, $response);
                }

                return $response;
            });
    }

    public function revise(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.plan');
        $this->assertFields($payload, ['client_command_id', 'expected_version', 'reason']);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            $c->fail('reason_required', '请填写不超过1000个字符的需求变更原因。');
        }
        $payload['reason'] = $reason;
        $this->assertDemandManageable($id, $user, $permissions, $super);

        return $c->run('revise_cutting_demand', $id, $payload, $user,
            function () use ($c, $id, $payload, $user, $permissions, $super): array {
                $seed = DB::table('erp_cutting_demands')->where('id', $id)->first();
                if (! $seed) {
                    $c->fail('cutting_demand_missing', '正式下料需求不存在。', 404);
                }
                // Receipt commands lock their handover/reservation fact before the
                // formal target. Follow that order, then demand and plans.
                $receiptFacts = $this->lockReceiptFacts((int) $seed->source_requirement_id);
                $target = DB::table('erp_production_target_material_requirements')
                    ->where('id', $seed->source_requirement_id)->lockForUpdate()->first();
                if (! $target) {
                    $c->fail('target_invalid', '正式目标物料需求不存在。', 409);
                }
                $demand = DB::table('erp_cutting_demands')->where('id', $id)->lockForUpdate()->first();
                if (! $demand) {
                    $c->fail('cutting_demand_missing', '正式下料需求不存在。', 404);
                }
                $consumer = $c->workOrder((int) $target->work_order_id, $user, $permissions, $super, 'production.cutting.plan');
                $this->assertFormalTarget($target, $consumer);
                $c->version($demand, $payload);
                if ((int) $target->component_item_id !== (int) $demand->item_id) {
                    $c->fail('demand_identity_conflict', '正式需求物料身份已变化，不能覆盖原下料需求。', 409);
                }
                if ((int) $target->business_version <= (int) $demand->source_business_version) {
                    $c->fail('demand_revision_not_required', '正式来源没有晚于当前下料需求的有效版本。', 409);
                }

                $nextStatus = $this->demandStatus((string) $target->status);
                if (! $this->relevantSourceChanged($demand, $target, $nextStatus)) {
                    $c->fail('demand_revision_not_required', '来源版本仅更新了接收等执行事实，无需变更下料需求。', 409);
                }

                $commitment = $this->currentCommitment($demand, $target, $receiptFacts);
                if (bccomp((string) $target->required_base_qty, $commitment['committed_qty'], 8) < 0) {
                    $c->fail('demand_revision_below_commitment', '变更后的需求量不能小于已有安排及外部到位数量。', 409, [
                        'committed_qty' => $commitment['committed_qty'],
                        'requested_qty' => $this->decimal((string) $target->required_base_qty),
                    ]);
                }

                $beforeSnapshot = $this->jsonObject($demand->source_snapshot);
                $afterSnapshot = (array) $target;
                $sourceChangeId = 'production_target_material_requirement:'.$target->id.':v'.$target->business_version;
                $revisionId = DB::table('erp_cutting_demand_revisions')->insertGetId([
                    'demand_id' => $id,
                    'source_change_id' => $sourceChangeId,
                    'source_business_version_before' => $demand->source_business_version,
                    'source_business_version_after' => $target->business_version,
                    'required_base_qty_before' => $demand->required_base_qty_snapshot,
                    'required_base_qty_after' => $target->required_base_qty,
                    'quantity_delta' => bcsub((string) $target->required_base_qty, (string) $demand->required_base_qty_snapshot, 8),
                    'cut_length_mm_before' => $demand->cut_length_mm_snapshot,
                    'cut_length_mm_after' => $target->cut_length_mm_snapshot,
                    'required_piece_qty_before' => $demand->required_piece_qty_snapshot,
                    'required_piece_qty_after' => $target->required_piece_qty_snapshot,
                    'demand_status_before' => $demand->status,
                    'demand_status_after' => $nextStatus,
                    'reason' => $payload['reason'],
                    'source_snapshot_before' => json_encode($beforeSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'source_snapshot_after' => json_encode($afterSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_by_legacy_id' => $c->actor($user),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('erp_cutting_demands')->where('id', $id)->update([
                    'required_base_qty_snapshot' => $target->required_base_qty,
                    'cut_length_mm_snapshot' => $target->cut_length_mm_snapshot,
                    'required_piece_qty_snapshot' => $target->required_piece_qty_snapshot,
                    'source_business_version' => $target->business_version,
                    'source_snapshot' => json_encode($afterSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'status' => $nextStatus,
                    'business_version' => (int) $demand->business_version + 1,
                    'updated_at' => now(),
                ]);
                $response = $this->projections(collect([$this->baseQuery()->where('d.id', $id)->first()]))[0];
                $response['revision'] = $this->revisionProjection(
                    DB::table('erp_cutting_demand_revisions')->where('id', $revisionId)->first()
                );
                $c->event('demand', $id, 'revise', $user, (array) $demand, $response);

                return $response;
            });
    }

    public function bind(array $plan, WorkOrder $producer, object $node, object $user, array $permissions, bool $super): object
    {
        $c = $this->commands;
        $targetId = filter_var($plan['target_material_requirement_id'] ?? null, FILTER_VALIDATE_INT);
        $inputId = filter_var($plan['input_material_requirement_id'] ?? null, FILTER_VALIDATE_INT);
        if (! $targetId || $targetId < 1) {
            $c->fail('formal_requirement_required', '下料计划必须引用唯一的正式目标物料需求行。');
        }
        if (! $inputId || $inputId < 1) {
            $c->fail('input_requirement_required', '必须明确当前下料工序所需的具体原料需求行。');
        }
        $target = DB::table('erp_production_target_material_requirements')->where('id', $targetId)->first();
        if (! $target) {
            $c->fail('target_invalid', '正式目标物料需求不存在。');
        }
        $consumer = $c->workOrder((int) $target->work_order_id, $user, $permissions, $super, 'production.cutting.plan');
        if (! in_array($consumer->status, ['RELEASED', 'IN_PROGRESS'], true)
            || in_array($target->status, ['CANCELLED', 'CLOSED'], true)) {
            $c->fail('target_not_active', '正式需求尚未发布或已终止。');
        }
        $receiptFacts = $this->lockReceiptFacts((int) $targetId);
        $target = DB::table('erp_production_target_material_requirements')->where('id', $targetId)->lockForUpdate()->first();
        $this->assertFormalTarget($target, $consumer);

        $outputId = (int) ($node->output_item_id_snapshot ?: ($producer->effective_output_item_id_snapshot ?: $producer->output_item_id));
        if ((int) $target->component_item_id !== $outputId) {
            $c->fail('target_invalid', '正式需求物料与该下料工序产出不一致。');
        }
        $item = Item::find($outputId);
        if (! $item || $item->status !== 'enabled') {
            $c->fail('output_item_invalid', '正式产出物料未启用。');
        }

        $input = DB::table('erp_work_order_material_requirements')->where('id', $inputId)
            ->where('work_order_id', $producer->id)->lockForUpdate()->first();
        $raw = $input ? Item::find($input->component_item_id) : null;
        if (! $input || ! $raw || $raw->status !== 'enabled' || $raw->item_type !== 'raw_material'
            || ! in_array($raw->cuttingMode(), ['sheet', 'length'], true)) {
            $c->fail('input_requirement_invalid', '原料需求必须属于生产来源工单，并指向启用的下料原料。');
        }
        $inputSupply = DB::table('erp_work_order_material_supply_rules')->where('work_order_id', $producer->id)
            ->where('material_requirement_id', $inputId)->where('component_item_id', $raw->id)
            ->where('target_routing_operation_id_snapshot', $node->routing_operation_id_snapshot)->first();
        if (! $inputSupply) {
            $c->fail('input_requirement_wrong_stage', '该原料需求不属于当前发布工序的冻结供料规则。');
        }
        $targetType = $node->production_unit_id ?? null ? 'unit_operation' : 'quantity_operation';
        $boundTarget = DB::table('erp_production_target_material_requirements')->where('work_order_id', $producer->id)
            ->where('material_requirement_id', $inputId)->where('material_supply_rule_snapshot_id', $inputSupply->id)
            ->where('component_item_id', $raw->id)->where('target_type', $targetType)->where('target_id', $node->id)->exists();
        if (! $boundTarget) {
            $c->fail('input_requirement_wrong_stage', '当前工序没有该原料的正式目标需求，不能推测关联。');
        }

        $configurationId = isset($plan['configuration_id']) ? (int) $plan['configuration_id'] : null;
        $this->assertConfiguration($configurationId, $item, [(int) $producer->id, (int) $consumer->id]);
        [$demand] = $this->ensureDemand(
            $target, $outputId, $configurationId, (int) $node->routing_operation_id_snapshot, $user
        );
        if ($demand->status !== 'ACTIVE') {
            $c->fail('demand_not_active', '正式下料需求已关闭或取消，不能继续安排。', 409);
        }

        $qty = CuttingDecimal::value($plan['planned_qty'] ?? null);
        $commitment = $this->currentCommitment($demand, $target, $receiptFacts);
        if (bccomp(bcadd($commitment['committed_qty'], $qty, 8), (string) $demand->required_base_qty_snapshot, 8) > 0) {
            $c->fail('plan_exceeds_source', '下料安排超过这条正式物料需求尚未安排的数量。');
        }

        return $demand;
    }

    private function generationPayload(array $payload): array
    {
        $source = filter_var($payload['source_requirement_id'] ?? null, FILTER_VALIDATE_INT);
        $producer = filter_var($payload['producer_work_order_id'] ?? null, FILTER_VALIDATE_INT);
        $stage = filter_var($payload['producer_stage_id'] ?? null, FILTER_VALIDATE_INT);
        $version = filter_var($payload['expected_version'] ?? null, FILTER_VALIDATE_INT);
        if (! $source || $source < 1 || ! $producer || $producer < 1 || ! $stage || $stage < 1 || $version === false) {
            $this->commands->fail('demand_source_invalid', '正式需求、生产来源工单、生产阶段或版本不合法。');
        }
        $configurationId = $payload['configuration_id'] ?? null;
        if ($configurationId !== null) {
            $configurationId = filter_var($configurationId, FILTER_VALIDATE_INT);
            if (! $configurationId || $configurationId < 1) {
                $this->commands->fail('configuration_invalid', '配置版本标识不合法。');
            }
        }

        return [
            'client_command_id' => $payload['client_command_id'] ?? null,
            'expected_version' => (int) $version,
            'source_requirement_id' => (int) $source,
            'producer_work_order_id' => (int) $producer,
            'producer_stage_id' => (int) $stage,
            'configuration_id' => $configurationId === null ? null : (int) $configurationId,
        ];
    }

    private function generationContext(array $payload, object $user, array $permissions, bool $super, bool $lock): array
    {
        $c = $this->commands;
        // Existing plan publication locks its producer work order first. Independent
        // generation follows that order, then serializes by the formal target row.
        $producer = $c->workOrder(
            $payload['producer_work_order_id'], $user, $permissions, $super, 'production.cutting.plan', $lock
        );
        if (! in_array($producer->status, ['RELEASED', 'IN_PROGRESS'], true)) {
            $c->fail('source_not_released', '只有已发布的正式生产工单可以生成下料需求。');
        }
        $targetQuery = DB::table('erp_production_target_material_requirements')->where('id', $payload['source_requirement_id']);
        if ($lock) {
            $targetQuery->lockForUpdate();
        }
        $target = $targetQuery->first();
        if (! $target) {
            $c->fail('target_invalid', '正式目标物料需求不存在。', 404);
        }
        $consumer = $c->workOrder((int) $target->work_order_id, $user, $permissions, $super, 'production.cutting.plan');
        if (! in_array($consumer->status, ['RELEASED', 'IN_PROGRESS'], true)
            || in_array($target->status, ['CANCELLED', 'CLOSED'], true)) {
            $c->fail('target_not_active', '正式需求尚未发布或已终止。');
        }
        $this->assertFormalTarget($target, $consumer);

        $nodes = collect();
        foreach (['erp_production_quantity_operations', 'erp_production_unit_operations'] as $table) {
            $nodes = $nodes->concat(DB::table($table)->where('work_order_id', $producer->id)
                ->where('routing_operation_id_snapshot', $payload['producer_stage_id'])->get());
        }
        if ($nodes->isEmpty()) {
            $c->fail('stage_invalid', '生产阶段不属于来源工单已冻结的正式路线。');
        }
        $outputs = $nodes->map(fn (object $node): int => (int) (
            $node->output_item_id_snapshot ?: ($producer->effective_output_item_id_snapshot ?: $producer->output_item_id)
        ))->unique()->values();
        if ($outputs->count() !== 1) {
            $c->fail('stage_output_ambiguous', '同一生产阶段存在不一致的产出物料，不能生成下料需求。', 409);
        }
        $item = Item::find((int) $outputs->first());
        if (! $item || $item->status !== 'enabled' || (int) $target->component_item_id !== (int) $item->id) {
            $c->fail('target_invalid', '正式需求物料与该生产阶段产出不一致。');
        }
        $this->assertConfiguration(
            $payload['configuration_id'], $item, [(int) $producer->id, (int) $consumer->id]
        );

        return compact('producer', 'consumer', 'target', 'item');
    }

    private function assertFormalTarget(object $target, WorkOrder $consumer): void
    {
        $table = match ($target->target_type) {
            'unit_operation' => 'erp_production_unit_operations',
            'quantity_operation' => 'erp_production_quantity_operations',
            default => null,
        };
        $operation = $table ? DB::table($table)->where('id', $target->target_id)
            ->where('work_order_id', $consumer->id)->first() : null;
        $source = DB::table('erp_work_order_material_requirements')->where('id', $target->material_requirement_id)
            ->where('work_order_id', $consumer->id)->where('component_item_id', $target->component_item_id)->first();
        $supply = DB::table('erp_work_order_material_supply_rules')->where('id', $target->material_supply_rule_snapshot_id)
            ->where('work_order_id', $consumer->id)->where('material_requirement_id', $target->material_requirement_id)
            ->where('component_item_id', $target->component_item_id)->first();
        if (! $operation || ! $source || ! $supply
            || (int) $supply->target_routing_operation_id_snapshot !== (int) $operation->routing_operation_id_snapshot) {
            $this->commands->fail('formal_requirement_inconsistent', '正式需求、物料行和冻结目标工序的关联不一致。');
        }
    }

    private function assertConfiguration(?int $id, Item $item, array $workOrderIds): void
    {
        if ($id === null) {
            if ($item->is_custom_item) {
                $this->commands->fail('configuration_required', '定制物料必须选择正式发布的配置版本。');
            }

            return;
        }
        $configuration = DB::table('erp_custom_configurations')->where('id', $id)
            ->where('item_id', $item->id)->where('status', 'PUBLISHED')->first();
        if (! $configuration) {
            $this->commands->fail('configuration_invalid', '配置版本未发布或不属于该物料。');
        }
        if ($configuration->scope_mode === 'PUBLIC') {
            return;
        }
        if ($configuration->scope_mode !== 'RESTRICTED') {
            $this->commands->fail('configuration_invalid', '配置版本适用范围不合法。', 409);
        }
        foreach (array_unique($workOrderIds) as $workOrderId) {
            if (! DB::table('erp_custom_configuration_scopes')->where('configuration_id', $id)
                ->where('source_type', 'work_order')->where('source_id', $workOrderId)->exists()) {
                $this->commands->fail('configuration_scope_denied', '配置版本不允许用于相关正式工单。', 403);
            }
        }
    }

    private function ensureDemand(
        object $target,
        int $itemId,
        ?int $configurationId,
        int $stageId,
        object $user,
    ): array {
        $c = $this->commands;
        $demand = DB::table('erp_cutting_demands')->where('source_type', 'production_target_material_requirement')
            ->where('source_requirement_id', $target->id)->lockForUpdate()->first();
        $created = false;
        if (! $demand) {
            $id = DB::table('erp_cutting_demands')->insertGetId([
                'demand_no' => $this->numbers->next('cutting_demand', 'CD'),
                'source_type' => 'production_target_material_requirement',
                'source_requirement_id' => $target->id,
                'item_id' => $itemId,
                'configuration_id' => $configurationId,
                'stage_id' => $stageId,
                'required_base_qty_snapshot' => $target->required_base_qty,
                'cut_length_mm_snapshot' => $target->cut_length_mm_snapshot,
                'required_piece_qty_snapshot' => $target->required_piece_qty_snapshot,
                'source_business_version' => $target->business_version,
                'source_snapshot' => json_encode((array) $target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => $this->demandStatus((string) $target->status),
                'business_version' => 1,
                'created_by_legacy_id' => $c->actor($user),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $demand = DB::table('erp_cutting_demands')->where('id', $id)->first();
            $created = true;
        }
        if ((int) $demand->item_id !== $itemId
            || $this->nullableInt($demand->configuration_id) !== $configurationId
            || (int) $demand->stage_id !== $stageId) {
            $c->fail('demand_identity_conflict', '同一正式需求不能生成另一套物料、配置或阶段身份。', 409);
        }
        if ($this->relevantSourceChanged($demand, $target, $this->demandStatus((string) $target->status))) {
            $c->fail('demand_revision_required', '正式需求规格、数量或状态已变更，须先走独立需求变更命令。', 409);
        }

        return [$demand, $created];
    }

    private function relevantSourceChanged(object $demand, object $target, string $nextStatus): bool
    {
        return bccomp((string) $demand->required_base_qty_snapshot, (string) $target->required_base_qty, 8) !== 0
            || ! $this->nullableDecimalEqual($demand->cut_length_mm_snapshot, $target->cut_length_mm_snapshot, 2)
            || ! $this->nullableDecimalEqual($demand->required_piece_qty_snapshot, $target->required_piece_qty_snapshot, 8)
            || (string) $demand->status !== $nextStatus;
    }

    private function demandStatus(string $sourceStatus): string
    {
        return match ($sourceStatus) {
            'CANCELLED' => 'CANCELLED',
            'CLOSED' => 'CLOSED',
            default => 'ACTIVE',
        };
    }

    private function baseQuery(): Builder
    {
        return DB::table('erp_cutting_demands as d')
            ->join('erp_production_target_material_requirements as source', 'source.id', '=', 'd.source_requirement_id')
            ->join('erp_work_orders as consumer', 'consumer.id', '=', 'source.work_order_id')
            ->join('erp_items as item', 'item.id', '=', 'd.item_id')
            ->join('erp_production_routing_operations as stage', 'stage.id', '=', 'd.stage_id')
            ->join('erp_production_operations as operation', 'operation.id', '=', 'stage.operation_id')
            ->leftJoin('erp_custom_configurations as configuration', 'configuration.id', '=', 'd.configuration_id')
            ->select(
                'd.*',
                'source.work_order_id as consumer_work_order_id',
                'source.target_type', 'source.target_id',
                'source.required_base_qty as current_required_base_qty',
                'source.cut_length_mm_snapshot as current_cut_length_mm',
                'source.required_piece_qty_snapshot as current_required_piece_qty',
                'source.satisfied_base_qty', 'source.returned_base_qty',
                'source.status as source_status', 'source.business_version as current_source_business_version',
                'consumer.work_order_no as consumer_work_order_no',
                'consumer.status as consumer_work_order_status',
                'consumer.responsible_user_legacy_id as consumer_responsible_user_legacy_id',
                'item.item_code', 'item.item_name', 'item.spec',
                'operation.operation_no as stage_code', 'operation.operation_name as stage_name',
                'configuration.configuration_no', 'configuration.version_no as configuration_version_no',
                'configuration.drawing_reference'
            );
    }

    private function applyVisibility(Builder $query, object $user, array $permissions, bool $super): void
    {
        $resolved = $this->scope->resolve($user, 'production.cutting.view', $permissions, $super);
        if (($resolved['mode'] ?? 'deny') === 'all') {
            return;
        }
        if (($resolved['mode'] ?? 'deny') === 'deny') {
            $query->whereRaw('1 = 0');

            return;
        }
        $ids = (array) ($resolved['user_ids'] ?? []);
        $pool = (bool) ($resolved['can_manage_pool'] ?? false);
        $query->where(function (Builder $workOrder) use ($ids, $pool): void {
            $workOrder->whereIn('consumer.responsible_user_legacy_id', $ids);
            if ($pool) {
                $workOrder->orWhereNull('consumer.responsible_user_legacy_id');
            }
        });
    }

    private function visibleRow(int $id, object $user, array $permissions, bool $super): object
    {
        if (! DB::table('erp_cutting_demands')->where('id', $id)->exists()) {
            $this->commands->fail('cutting_demand_missing', '正式下料需求不存在。', 404);
        }
        $query = $this->baseQuery()->where('d.id', $id);
        $this->applyVisibility($query, $user, $permissions, $super);
        $row = $query->first();
        if (! $row) {
            $this->commands->fail('data_scope_denied', '该正式下料需求不在当前生产数据范围内。', 403);
        }

        return $row;
    }

    private function assertDemandManageable(int $id, object $user, array $permissions, bool $super): object
    {
        $demand = DB::table('erp_cutting_demands')->where('id', $id)->first();
        if (! $demand) {
            $this->commands->fail('cutting_demand_missing', '正式下料需求不存在。', 404);
        }
        $workOrderId = DB::table('erp_production_target_material_requirements')
            ->where('id', $demand->source_requirement_id)->value('work_order_id');
        if (! $workOrderId) {
            $this->commands->fail('target_invalid', '正式目标物料需求不存在。', 409);
        }
        $this->commands->workOrder((int) $workOrderId, $user, $permissions, $super, 'production.cutting.plan');

        return $demand;
    }

    private function projections(Collection $rows): array
    {
        $summaries = $this->summaryFacts($rows);

        return $rows->map(function (object $row) use ($summaries): array {
            $summary = $summaries[(int) $row->id];

            return [
                'id' => (int) $row->id,
                'demand_no' => $row->demand_no,
                'status' => $row->status,
                'source_type' => $row->source_type,
                'source_requirement_id' => (int) $row->source_requirement_id,
                'source_business_version' => (int) $row->source_business_version,
                'current_source_business_version' => (int) $row->current_source_business_version,
                'revision_pending' => $this->relevantSourceChanged(
                    $row,
                    (object) [
                        'required_base_qty' => $row->current_required_base_qty,
                        'cut_length_mm_snapshot' => $row->current_cut_length_mm,
                        'required_piece_qty_snapshot' => $row->current_required_piece_qty,
                    ],
                    $this->demandStatus((string) $row->source_status),
                ),
                'consumer_work_order' => [
                    'id' => (int) $row->consumer_work_order_id,
                    'work_order_no' => $row->consumer_work_order_no,
                    'status' => $row->consumer_work_order_status,
                    'responsible_user_legacy_id' => $row->consumer_responsible_user_legacy_id === null
                        ? null : (int) $row->consumer_responsible_user_legacy_id,
                ],
                'target_type' => $row->target_type,
                'target_id' => (int) $row->target_id,
                'item' => [
                    'id' => (int) $row->item_id,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                    'spec' => $row->spec,
                ],
                'configuration' => $row->configuration_id === null ? null : [
                    'id' => (int) $row->configuration_id,
                    'configuration_no' => $row->configuration_no,
                    'version_no' => (int) $row->configuration_version_no,
                    'drawing_reference' => $row->drawing_reference,
                ],
                'stage' => [
                    'id' => (int) $row->stage_id,
                    'operation_code' => $row->stage_code,
                    'operation_name' => $row->stage_name,
                ],
                'required_base_qty' => $this->decimal((string) $row->required_base_qty_snapshot),
                'cut_length_mm' => $row->cut_length_mm_snapshot === null ? null : $this->decimal((string) $row->cut_length_mm_snapshot, 2),
                'required_piece_qty' => $row->required_piece_qty_snapshot === null ? null : $this->decimal((string) $row->required_piece_qty_snapshot),
                'reported_qty' => $summary['reported_qty'],
                'waiting_quality_qty' => $summary['waiting_quality_qty'],
                'qualified_produced_qty' => $summary['qualified_produced_qty'],
                'planned_qty' => $summary['planned_qty'],
                'allocated_qty' => $summary['allocated_qty'],
                'received_qty' => $summary['received_qty'],
                'remaining_demand_qty' => $summary['remaining_demand_qty'],
                'trace' => [
                    'draft_reported_qty' => $summary['draft_reported_qty'],
                    'pending_settlement_qty' => $summary['pending_settlement_qty'],
                    'quality_failed_qty' => $summary['quality_failed_qty'],
                    'direct_cutting_received_qty' => $summary['direct_cutting_received_qty'],
                    'warehouse_cutting_issued_qty' => $summary['warehouse_cutting_issued_qty'],
                    'external_received_qty' => $summary['external_received_qty'],
                    'committed_qty' => $summary['committed_qty'],
                ],
                'business_version' => (int) $row->business_version,
                'created_by_legacy_id' => (int) $row->created_by_legacy_id,
                'created_at' => $this->date($row->created_at),
                'updated_at' => $this->date($row->updated_at),
            ];
        })->all();
    }

    /**
     * Demand commitment is planned cutting plus non-cutting receipts. Cutting
     * receipts are already supplied by a plan and must not consume the demand twice.
     */
    private function summaryFacts(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $demandIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $targetIds = $rows->pluck('source_requirement_id')->map(fn ($id): int => (int) $id)->all();

        $planned = DB::table('erp_cutting_plan_allocations as plan')
            ->join('erp_cutting_orders as cutting_order', 'cutting_order.id', '=', 'plan.cutting_order_id')
            ->whereIn('plan.demand_id', $demandIds)->where('cutting_order.status', '!=', 'CANCELLED')
            ->groupBy('plan.demand_id')->selectRaw('plan.demand_id AS aggregate_id, COALESCE(SUM(plan.planned_qty),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $routeBase = fn (): Builder => DB::table('erp_cutting_result_routes as route')
            ->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
            ->whereIn('route.target_material_requirement_id', $targetIds)
            ->where('route.status', 'PLANNED')->whereNotIn('result.status', ['VOIDED', 'SUPERSEDED']);
        $pendingRoutes = $routeBase()->where('result.status', 'SUBMITTED')->groupBy('route.target_material_requirement_id')
            ->selectRaw('route.target_material_requirement_id AS aggregate_id, COALESCE(SUM(route.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $draftRoutes = $routeBase()->where('result.status', 'DRAFT')->groupBy('route.target_material_requirement_id')
            ->selectRaw('route.target_material_requirement_id AS aggregate_id, COALESCE(SUM(route.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $waitingQuality = $routeBase()->where('result.status', 'SUBMITTED')->where('result.quality_status', 'WAIT_QUALITY')
            ->groupBy('route.target_material_requirement_id')
            ->selectRaw('route.target_material_requirement_id AS aggregate_id, COALESCE(SUM(route.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $qualityFailed = $routeBase()->where('result.status', 'SUBMITTED')->where('result.quality_status', 'FAILED')
            ->groupBy('route.target_material_requirement_id')
            ->selectRaw('route.target_material_requirement_id AS aggregate_id, COALESCE(SUM(route.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $pendingSettlement = $routeBase()->where('result.status', 'SUBMITTED')
            ->whereIn('result.quality_status', ['PASSED', 'NOT_REQUIRED'])
            ->groupBy('route.target_material_requirement_id')
            ->selectRaw('route.target_material_requirement_id AS aggregate_id, COALESCE(SUM(route.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $allocated = DB::table('erp_cutting_output_allocations as allocation')
            ->join('erp_cutting_plan_allocations as plan', 'plan.id', '=', 'allocation.plan_id')
            ->whereIn('plan.demand_id', $demandIds)->where('allocation.status', 'EFFECTIVE')
            ->groupBy('plan.demand_id')->selectRaw('plan.demand_id AS aggregate_id, COALESCE(SUM(allocation.quantity),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $directReceived = DB::table('erp_cutting_handovers')->whereIn('target_material_requirement_id', $targetIds)
            ->groupBy('target_material_requirement_id')
            ->selectRaw('target_material_requirement_id AS aggregate_id, COALESCE(SUM(accepted_qty),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');
        $warehouseIssued = DB::table('erp_cutting_inventory_reservations')->whereIn('target_material_requirement_id', $targetIds)
            ->groupBy('target_material_requirement_id')
            ->selectRaw('target_material_requirement_id AS aggregate_id, COALESCE(SUM(issued_qty),0) AS quantity')
            ->pluck('quantity', 'aggregate_id');

        $facts = [];
        foreach ($rows as $row) {
            $demandId = (int) $row->id;
            $targetId = (int) $row->source_requirement_id;
            $plannedQty = $this->decimal((string) ($planned[$demandId] ?? '0'));
            $pendingQty = $this->decimal((string) ($pendingRoutes[$targetId] ?? '0'));
            $allocatedQty = $this->decimal((string) ($allocated[$demandId] ?? '0'));
            $receivedQty = $this->maxZero(bcsub((string) $row->satisfied_base_qty, (string) $row->returned_base_qty, 8));
            $directQty = $this->decimal((string) ($directReceived[$targetId] ?? '0'));
            $warehouseQty = $this->decimal((string) ($warehouseIssued[$targetId] ?? '0'));
            $externalQty = $this->maxZero(bcsub(bcsub($receivedQty, $directQty, 8), $warehouseQty, 8));
            $committed = bcadd($plannedQty, $externalQty, 8);
            $facts[$demandId] = [
                'reported_qty' => bcadd($pendingQty, $allocatedQty, 8),
                'waiting_quality_qty' => $this->decimal((string) ($waitingQuality[$targetId] ?? '0')),
                'qualified_produced_qty' => $allocatedQty,
                'planned_qty' => $plannedQty,
                'allocated_qty' => $allocatedQty,
                'received_qty' => $receivedQty,
                'remaining_demand_qty' => $this->maxZero(bcsub((string) $row->required_base_qty_snapshot, $committed, 8)),
                'draft_reported_qty' => $this->decimal((string) ($draftRoutes[$targetId] ?? '0')),
                'pending_settlement_qty' => $this->decimal((string) ($pendingSettlement[$targetId] ?? '0')),
                'quality_failed_qty' => $this->decimal((string) ($qualityFailed[$targetId] ?? '0')),
                'direct_cutting_received_qty' => $directQty,
                'warehouse_cutting_issued_qty' => $warehouseQty,
                'external_received_qty' => $externalQty,
                'committed_qty' => $committed,
            ];
        }

        return $facts;
    }

    /** Current locking reads are required after waiting on the formal target under MySQL REPEATABLE READ. */
    private function currentCommitment(object $demand, object $target, array $receiptFacts): array
    {
        $planned = '0.00000000';
        $plans = DB::table('erp_cutting_plan_allocations as plan')
            ->join('erp_cutting_orders as cutting_order', 'cutting_order.id', '=', 'plan.cutting_order_id')
            ->where('plan.demand_id', $demand->id)->where('cutting_order.status', '!=', 'CANCELLED')
            ->orderBy('plan.id')->lockForUpdate()->get(['plan.planned_qty']);
        foreach ($plans as $plan) {
            $planned = bcadd($planned, (string) $plan->planned_qty, 8);
        }

        $received = $this->maxZero(bcsub((string) $target->satisfied_base_qty, (string) $target->returned_base_qty, 8));
        $external = $this->maxZero(bcsub(
            bcsub($received, $receiptFacts['direct_received_qty'], 8),
            $receiptFacts['warehouse_issued_qty'],
            8
        ));

        return [
            'planned_qty' => $planned,
            'external_received_qty' => $external,
            'committed_qty' => bcadd($planned, $external, 8),
        ];
    }

    private function lockReceiptFacts(int $targetId): array
    {
        $direct = '0.00000000';
        $handovers = DB::table('erp_cutting_handovers')->where('target_material_requirement_id', $targetId)
            ->orderBy('id')->lockForUpdate()->get(['accepted_qty']);
        foreach ($handovers as $handover) {
            $direct = bcadd($direct, (string) $handover->accepted_qty, 8);
        }

        $warehouse = '0.00000000';
        $reservations = DB::table('erp_cutting_inventory_reservations')->where('target_material_requirement_id', $targetId)
            ->orderBy('id')->lockForUpdate()->get(['issued_qty']);
        foreach ($reservations as $reservation) {
            $warehouse = bcadd($warehouse, (string) $reservation->issued_qty, 8);
        }

        return ['direct_received_qty' => $direct, 'warehouse_issued_qty' => $warehouse];
    }

    private function revisionProjection(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'source_change_id' => $row->source_change_id,
            'source_business_version_before' => (int) $row->source_business_version_before,
            'source_business_version_after' => (int) $row->source_business_version_after,
            'required_base_qty_before' => $this->decimal((string) $row->required_base_qty_before),
            'required_base_qty_after' => $this->decimal((string) $row->required_base_qty_after),
            'quantity_delta' => $this->signedDecimal((string) $row->quantity_delta),
            'cut_length_mm_before' => $row->cut_length_mm_before === null ? null : $this->decimal((string) $row->cut_length_mm_before, 2),
            'cut_length_mm_after' => $row->cut_length_mm_after === null ? null : $this->decimal((string) $row->cut_length_mm_after, 2),
            'required_piece_qty_before' => $row->required_piece_qty_before === null ? null : $this->decimal((string) $row->required_piece_qty_before),
            'required_piece_qty_after' => $row->required_piece_qty_after === null ? null : $this->decimal((string) $row->required_piece_qty_after),
            'demand_status_before' => $row->demand_status_before,
            'demand_status_after' => $row->demand_status_after,
            'reason' => $row->reason,
            'source_snapshot_before' => $this->jsonObject($row->source_snapshot_before),
            'source_snapshot_after' => $this->jsonObject($row->source_snapshot_after),
            'created_by_legacy_id' => (int) $row->created_by_legacy_id,
            'created_at' => $this->date($row->created_at),
        ];
    }

    private function pagination(array $filters): array
    {
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($filters['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) {
            $this->commands->fail('pagination_invalid', '分页参数不合法，每页最多100条。');
        }

        return [(int) $page, (int) $size];
    }

    private function revisionPagination(array $filters): array
    {
        $page = filter_var($filters['revision_page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($filters['revision_per_page'] ?? 20, FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) {
            $this->commands->fail('pagination_invalid', '变更记录分页参数不合法，每页最多100条。');
        }

        return [(int) $page, (int) $size];
    }

    private function assertFields(array $payload, array $allowed): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            $this->commands->fail('demand_fields_invalid', '正式下料需求操作包含不允许的字段。', 422, [
                'fields' => array_values($unknown),
            ]);
        }
    }

    private function nullableDecimalEqual(mixed $left, mixed $right, int $scale): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return bccomp((string) $left, (string) $right, $scale) === 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function decimal(string $value, int $scale = 8): string
    {
        return bcadd($value, '0', $scale);
    }

    private function signedDecimal(string $value): string
    {
        return bcadd($value, '0', 8);
    }

    private function maxZero(string $value): string
    {
        return bccomp($value, '0', 8) < 0 ? '0.00000000' : bcadd($value, '0', 8);
    }

    private function jsonObject(mixed $value): array
    {
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : (array) $value;
    }

    private function date(mixed $value): ?string
    {
        return $value ? date(DATE_ATOM, strtotime((string) $value)) : null;
    }
}
