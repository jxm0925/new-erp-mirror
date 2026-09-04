<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\ProductionRoutingOperation;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderCommandLedger;
use App\Models\Erp\WorkOrderStatusLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkOrderApplicationService
{
    public const DRAFT = 'DRAFT';
    public const WAIT_RELEASE = 'WAIT_RELEASE';
    public const RELEASED = 'RELEASED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const CLOSED = 'CLOSED';
    public const CANCELLED = 'CANCELLED';

    /**
     * 当前 WO-02 只负责草稿和待发布聚合；后续执行事实不能在这里伪造成功。
     */
    private const ALLOCATING_STATUSES = [self::DRAFT, self::WAIT_RELEASE, self::RELEASED, self::IN_PROGRESS, self::COMPLETED];

    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly ProductionDataScopeResolver $scopeResolver,
        private readonly ErpUserProjectionService $userProjections,
        private readonly ReleaseGateApplicationService $releaseGate,
        private readonly ProductionMasterDataService $productionMasterData,
    ) {
    }

    public function paginateDemands(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->assertPermission($permissions, 'production.demand.view', $superAdmin);
        $query = ProductionDemand::query()->with(['order', 'line', 'workOrders'])->orderByDesc('id');
        $this->scopeResolver->applyDemandScope(
            $query,
            $this->scopeResolver->resolve($user, 'production.demand.view', $permissions, $superAdmin),
        );
        if (! empty($filters['status'])) $query->where('requirement_status', $filters['status']);
        if (! empty($filters['sales_order_id'])) $query->where('sales_order_id', (int) $filters['sales_order_id']);
        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function showDemand(int $id, object $user, array $permissions, bool $superAdmin = false): ProductionDemand
    {
        $this->assertPermission($permissions, 'production.demand.view', $superAdmin);
        $demand = ProductionDemand::query()->with(['order', 'line', 'workOrders.statusLogs'])->find($id);
        if (! $demand) $this->fail('not_found', '生产需求不存在。', 404);
        $this->assertDemandVisible($demand, $user, $permissions, $superAdmin);
        return $demand;
    }

    public function paginateWorkOrders(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->assertPermission($permissions, 'production.work_order.view', $superAdmin);
        $query = WorkOrder::query()->with(['demand.order', 'demand.line'])->orderByDesc('id');
        $this->scopeResolver->applyWorkOrderScope(
            $query,
            $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin),
        );
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['production_demand_id'])) $query->where('production_demand_id', (int) $filters['production_demand_id']);
        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function showWorkOrder(int $id, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.view', $superAdmin);
        $workOrder = WorkOrder::query()->with(['demand.order', 'demand.line', 'statusLogs', 'commands'])->find($id);
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
        return $workOrder;
    }

    public function createDraft(array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.create', $superAdmin);
        if (($payload['source_type'] ?? 'sales_order') !== 'sales_order') {
            return $this->createIndependentDraft($payload, $user, $permissions, $superAdmin);
        }
        return $this->withCommand('create_draft', null, $payload, $user, function () use ($payload, $user, $permissions, $superAdmin): WorkOrder {
            $demand = $this->lockDemand((int) $payload['production_demand_id']);
            $this->assertDemandReady($demand);
            $this->assertDemandVisible($demand, $user, $permissions, $superAdmin);
            $this->assertExpectedDemandVersion($demand, $payload);
            $this->assertUnitContract($demand, $payload);
            $this->assertQuantityPrecision($payload['target_qty'] ?? null);
            $quantity = $this->decimal($payload['target_qty'] ?? null);
            $this->assertPositiveQuantity($quantity);
            $this->assertAvailableQuantity($demand, $quantity);
            $this->assertResponsibleUser($payload['responsible_user_legacy_id'] ?? null, $user, $permissions, $superAdmin);

            $workOrder = WorkOrder::create($this->workOrderAttributes($demand, $payload, $quantity, $user));
            $this->recordStatus($workOrder, null, self::DRAFT, '创建草稿', 0, 1, $user);
            $this->refreshDemandProjection($demand);
            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs']);
        });
    }

    public function updateDraft(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.edit', $superAdmin);
        return $this->withCommand('edit_draft', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin): WorkOrder {
            $unlocked = WorkOrder::find($id);
            if (! $unlocked) $this->fail('not_found', '工单不存在。', 404);
            if (! $unlocked->production_demand_id) return $this->updateIndependentDraft($id, $payload, $user, $permissions, $superAdmin);
            if (array_key_exists('production_routing_id', $payload) || array_key_exists('target_routing_operation_id', $payload)) {
                $this->fail('immutable_field', '销售订单来源工单的工艺路线由系统冻结，编辑时不可修改。', 422);
            }
            $demand = $this->lockDemand((int) $unlocked->production_demand_id); // update-demand-lock
            $workOrder = $this->lockWorkOrder($id);
            $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
            $this->assertExpectedVersion($workOrder, $payload);
            $this->assertState($workOrder, [self::DRAFT]);
            $this->assertDemandVisible($demand, $user, $permissions, $superAdmin);
            $this->assertUnitContract($demand, $payload);
            if (array_key_exists('target_qty', $payload)) $this->assertQuantityPrecision($payload['target_qty']);
            $quantity = array_key_exists('target_qty', $payload)
                ? $this->decimal($payload['target_qty'])
                : $this->decimal($workOrder->target_qty);
            $this->assertPositiveQuantity($quantity);
            $this->assertAvailableQuantity($demand, $quantity, $workOrder->id);
            $this->assertResponsibleUser($payload['responsible_user_legacy_id'] ?? $workOrder->responsible_user_legacy_id, $user, $permissions, $superAdmin);

            $beforeSnapshot = $this->editableSnapshot($workOrder);
            $editPayload = array_merge([
                'planned_date' => $workOrder->planned_date?->format('Y-m-d'),
                'production_batch' => $workOrder->production_batch,
                'responsible_user_legacy_id' => $workOrder->responsible_user_legacy_id,
                'production_location_name' => $workOrder->production_location_name,
            ], $payload);
            $workOrder->fill($this->editableAttributes($editPayload, $quantity, $demand));
            $workOrder->business_version = (int) $workOrder->business_version + 1;
            $workOrder->updated_by_legacy_id = $this->userId($user);
            $workOrder->save();
            $this->recordOperationAudit($workOrder, $beforeSnapshot, $this->editableSnapshot($workOrder), $payload['reason'] ?? null, $user);
            $this->recordStatus($workOrder, self::DRAFT, self::DRAFT, '编辑草稿', (int) $workOrder->business_version - 1, (int) $workOrder->business_version, $user);
            $this->refreshDemandProjection($demand);
            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs']);
        });
    }

    public function submit(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.submit', $superAdmin);
        return $this->transition($id, $payload, $user, $permissions, $superAdmin, [self::DRAFT], self::WAIT_RELEASE, '提交待发布', 'submit');
    }

    public function returnToDraft(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.edit', $superAdmin);
        return $this->transition($id, $payload, $user, $permissions, $superAdmin, [self::WAIT_RELEASE], self::DRAFT, (string) ($payload['reason'] ?? '退回草稿'), 'return_draft');
    }

    public function rematchRouting(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.edit', $superAdmin);

        return $this->withCommand('rematch_routing', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin): WorkOrder {
            $workOrder = $this->lockWorkOrder($id);
            $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
            $this->assertExpectedVersion($workOrder, $payload);
            if ($workOrder->source_type !== 'sales_order' || ! in_array($workOrder->status, [self::DRAFT, self::WAIT_RELEASE], true)) {
                $this->fail('routing_rematch_not_allowed', '只有草稿或待发布的销售来源工单可以重新匹配工艺路线。', 422);
            }
            if ($workOrder->production_routing_id || $workOrder->routing_snapshot) {
                $this->fail('routing_already_frozen', '工单已经冻结工艺路线，禁止重新覆盖历史快照。', 409);
            }
            if (! $workOrder->output_item_id) {
                $this->fail('output_item_missing', '工单缺少产出物料，不能重新匹配工艺路线。', 422);
            }

            $matches = ProductionRouting::with(['outputItem', 'product', 'sku', 'operations.operation'])
                ->where('output_item_id', $workOrder->output_item_id)
                ->where('status', 'active')->where('is_default', true)
                ->lockForUpdate()->get();
            if ($matches->isEmpty()) $this->fail('routing_not_found', '未找到该产出物料的默认生效工艺路线。', 422);
            if ($matches->count() !== 1) $this->fail('routing_ambiguous', '该产出物料存在多条默认生效工艺路线，禁止自动选择。', 409);

            $routing = $matches->first();
            $version = (int) $workOrder->business_version;
            $workOrder->production_routing_id = $routing->id;
            $workOrder->routing_version_snapshot = $routing->version;
            $workOrder->routing_snapshot = $this->productionMasterData->snapshot($routing);
            $workOrder->business_version = $version + 1;
            $workOrder->updated_by_legacy_id = $this->userId($user);
            $workOrder->save();
            $this->recordStatus(
                $workOrder,
                (string) $workOrder->status,
                (string) $workOrder->status,
                trim((string) $payload['reason']),
                $version,
                $version + 1,
                $user,
            );

            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs', 'routing.operations.operation']);
        });
    }

    public function publish(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.publish', $superAdmin);

        return $this->withCommand('publish', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin): WorkOrder {
            $workOrder = $this->lockWorkOrder($id);
            $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
            $this->assertExpectedVersion($workOrder, $payload);
            $this->assertState($workOrder, [self::WAIT_RELEASE]);

            $gate = $this->releaseGate->evaluateLocked($workOrder, $user, true);
            if (! $gate['allowed']) {
                $this->fail('release_gate_blocked', '工单发布 Gate 未通过。', 422, [
                    'blockers' => $gate['blockers'],
                ]);
            }

            $bom = $this->releaseGate->loadMatchedBom($gate);
            if ($workOrder->materialRequirements()->exists()) {
                $this->fail('material_requirements_exist', '该工单已存在正式物料需求，禁止重复展开。', 409);
            }
            $materialRows = $this->releaseGate->buildMaterialRows($workOrder, $bom);
            if ($materialRows === []) {
                $this->fail('bom_incomplete', 'BOM 没有可发布的物料需求行。', 422);
            }
            DB::table('erp_work_order_material_requirements')->insert($materialRows);

            $version = (int) $workOrder->business_version;
            $workOrder->bom_id = $bom->id;
            $workOrder->bom_version_id = $bom->id;
            $workOrder->bom_version = $bom->version;
            $workOrder->bom_snapshot = [
                'bom_id' => (int) $bom->id,
                'bom_no' => $bom->bom_no,
                'bom_name' => $bom->bom_name,
                'version' => $bom->version,
                'output_item_id' => (int) $bom->output_item_id,
                'material_line_count' => count($materialRows),
            ];
            $workOrder->status = self::RELEASED;
            $workOrder->business_version = $version + 1;
            $workOrder->released_by_legacy_id = $this->userId($user);
            $workOrder->released_at = now();
            $workOrder->release_reason = trim((string) ($payload['reason'] ?? ''));
            $workOrder->updated_by_legacy_id = $this->userId($user);
            $workOrder->save();

            $this->recordStatus($workOrder, self::WAIT_RELEASE, self::RELEASED, $workOrder->release_reason, $version, $version + 1, $user);

            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs', 'releaseGateChecks', 'materialRequirements']);
        });
    }
    public function cancel(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrder
    {
        $this->assertPermission($permissions, 'production.work_order.cancel', $superAdmin);
        return $this->withCommand('cancel', $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin): WorkOrder {
            $unlocked = WorkOrder::find($id);
            if (! $unlocked) $this->fail('not_found', '工单不存在。', 404);
            $demand = $unlocked->production_demand_id ? $this->lockDemand((int) $unlocked->production_demand_id) : null;
            $workOrder = $this->lockWorkOrder($id);
            $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
            $this->assertExpectedVersion($workOrder, $payload);
            $this->assertState($workOrder, [self::DRAFT, self::WAIT_RELEASE]);
            if ($demand) $this->assertDemandVisible($demand, $user, $permissions, $superAdmin);
            $before = (string) $workOrder->status;
            $version = (int) $workOrder->business_version;
            $workOrder->status = self::CANCELLED;
            $workOrder->business_version = $version + 1;
            $workOrder->cancelled_by_legacy_id = (string) $this->userId($user);
            $workOrder->cancel_reason = (string) ($payload['reason'] ?? '业务取消');
            $workOrder->cancelled_at = now();
            $workOrder->updated_by_legacy_id = $this->userId($user);
            $workOrder->save();
            $this->recordStatus($workOrder, $before, self::CANCELLED, $workOrder->cancel_reason, $version, $version + 1, $user);
            if ($demand) $this->refreshDemandProjection($demand);
            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs']);
        });
    }

    private function transition(int $id, array $payload, object $user, array $permissions, bool $superAdmin, array $from, string $to, string $reason, string $command): WorkOrder
    {
        return $this->withCommand($command, $id, $payload, $user, function () use ($id, $payload, $user, $permissions, $superAdmin, $from, $to, $reason): WorkOrder {
            $workOrder = $this->lockWorkOrder($id);
            $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
            $this->assertExpectedVersion($workOrder, $payload);
            $this->assertState($workOrder, $from);
            $before = (string) $workOrder->status;
            $version = (int) $workOrder->business_version;
            if ($to === self::WAIT_RELEASE && ! $workOrder->planned_date && ! $workOrder->production_batch) {
                $this->fail('validation_error', '提交工单前必须填写计划日期或生产批次。', 422, ['planned_date', 'production_batch']);
            }
            $workOrder->status = $to;
            $workOrder->business_version = $version + 1;
            $workOrder->updated_by_legacy_id = $this->userId($user);
            $workOrder->submitted_at = $to === self::WAIT_RELEASE ? now() : $workOrder->submitted_at;
            $workOrder->save();
            $this->recordStatus($workOrder, $before, $to, $reason, $version, $version + 1, $user);
            return $workOrder->fresh(['demand.order', 'demand.line', 'statusLogs']);
        });
    }

    /**
     * 命令认领、业务写入、账本终结故意拆成多个短事务：先提交 processing 认领，
     * 再提交带 last_command_id 恢复点的业务事实，最后固化小型结果快照。这样进程在
     * 两次提交之间退出时，重复命令可按精确 command marker 恢复原结果；删除认领、
     * recovery_required 或 last_command_id 逻辑会重新引入重复建单风险。WO-05 高频
     * 命令不得沿用完整聚合快照，只允许保存业务事实 ID、编号、状态、版本和小结果。
     */
    private function withCommand(string $command, ?int $aggregateId, array $payload, object $user, \Closure $operation): WorkOrder
    {
        $clientCommandId = (string) ($payload['client_command_id'] ?? '');
        if ($clientCommandId === '') $this->fail('validation_error', 'client_command_id 不能为空。');
        $hashPayload = $payload;
        unset($hashPayload['client_command_id']);
        $identity = [
            'aggregate_type' => 'work_order',
            'aggregate_id' => $aggregateId,
            'command_type' => $command,
            'payload' => $this->sortPayload($hashPayload),
        ];
        $requestHash = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            // Commit the processing claim before the business transaction. A
            // crash after business commit can then be reconciled by the exact
            // command marker instead of leaving an unprovable terminal state.
            $ledger = DB::transaction(
                fn (): WorkOrder|WorkOrderCommandLedger => $this->claimCommand($clientCommandId, $command, $requestHash, $aggregateId, $user),
                5
            );
            if ($ledger instanceof WorkOrder) return $ledger->fresh(['demand.order', 'demand.line', 'statusLogs']);
            $testDelayMs = (int) env('WO02_TEST_DELAY_AFTER_CLAIM_MS', 0);
            if ($testDelayMs > 0) usleep($testDelayMs * 1000);

            try {
                $workOrder = DB::transaction(function () use ($operation, $clientCommandId): WorkOrder {
                    $result = $operation();
                    // The command marker is part of the same commit as the
                    // business facts. Without it, a process crash after this
                    // commit leaves publish/transition commands impossible to
                    // prove and safely recover.
                    $result->last_command_id = $clientCommandId;
                    $result->save();
                    return $result;
                }, 5);
            } catch (Throwable $exception) {
                DB::table('erp_work_order_command_ledgers')->where('id', $ledger->id)->update([
                    'status' => 'failed',
                    'error_code' => $exception instanceof WorkOrderDomainException ? $exception->errorCode : 'operation_failed',
                    'error_message' => $exception->getMessage(),
                    'processing_finished_at' => now(),
                ]);
                throw $exception;
            }

            // Test-only fault injection models a worker crash in the real
            // window between committed business data and ledger finalization.
            if ((int) env('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT', 0) === 1) {
                throw new \RuntimeException('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT');
            }

            $ledgerUpdated = DB::transaction(function () use ($ledger, $workOrder): int {
                return DB::table('erp_work_order_command_ledgers')->where('id', $ledger->id)->update([
                    'aggregate_id' => $workOrder->id,
                    'status' => 'succeeded',
                    'result_type' => 'work_order',
                    'result_id' => $workOrder->id,
                    'response_snapshot' => $workOrder->toArray(),
                    'processing_finished_at' => now(),
                ]);
            }, 5);
            if ($ledgerUpdated !== 1) $this->fail('ledger_write_failed', '命令账本未能完成结果固化。', 500, ['ledger_id' => $ledger->id]);
            $result = $workOrder;
        } catch (WorkOrderDomainException $exception) {
            if ($exception->errorCode === 'command_recovery_required') {
                DB::table('erp_work_order_command_ledgers')->where('client_command_id', $clientCommandId)
                    ->whereIn('status', ['processing', 'recovery_required'])
                    ->update([
                        'status' => 'recovery_required',
                        'error_code' => 'command_recovery_required',
                        'error_message' => $exception->getMessage(),
                        'processing_finished_at' => null,
                    ]);
            }
            throw $exception;
        } catch (QueryException $exception) {
            throw $this->persistenceFailure($exception);
        }
        return $result->fresh(['demand.order', 'demand.line', 'statusLogs']);
    }

    /**
     * INSERT 先于读取是并发身份的原子抢占点；缺失行不能靠 lockForUpdate 保护。
     * 唯一键竞争后重新锁定同一行，才能把并发请求折叠为同 hash 原结果或稳定冲突。
     */
    private function claimCommand(string $clientCommandId, string $command, string $requestHash, ?int $aggregateId, object $user): WorkOrder|WorkOrderCommandLedger
    {
        $claimed = false;
        try {
            $ledger = WorkOrderCommandLedger::create([
                'client_command_id' => $clientCommandId,
                'command_type' => $command,
                'aggregate_type' => 'work_order',
                'aggregate_id' => $aggregateId,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user),
                'processing_started_at' => now(),
            ]);
            $claimed = true;
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '1062'], true)) throw $exception;
            $ledger = WorkOrderCommandLedger::query()->where('client_command_id', $clientCommandId)->lockForUpdate()->first();
            if (! $ledger) throw $exception;
        }

        $aggregateMismatch = $aggregateId !== null
            && (int) ($ledger->aggregate_id ?? 0) !== (int) $aggregateId;
        if (! hash_equals((string) $ledger->request_hash, $requestHash)
            || $ledger->command_type !== $command
            || $ledger->aggregate_type !== 'work_order'
            || $aggregateMismatch) {
            $this->fail('idempotency_hash_conflict', '同一 client_command_id 不得跨命令或工单聚合复用。', 409, [
                'client_command_id' => $clientCommandId,
                'existing_command_type' => $ledger->command_type,
                'existing_aggregate_id' => $ledger->aggregate_id,
            ]);
        }
        if ($claimed) return $ledger;

        if ($ledger->status === 'succeeded' && $ledger->result_id) {
            $result = WorkOrder::find((int) $ledger->result_id);
            if ($result) return $result;
            $this->fail('command_recovery_required', '幂等结果已丢失，需执行账本恢复。', 409, ['client_command_id' => $clientCommandId]);
        }
        if ($ledger->status === 'failed') {
            $this->fail((string) ($ledger->error_code ?: 'command_failed'), (string) ($ledger->error_message ?: '命令此前执行失败。'), 422);
        }
        if ($ledger->status === 'recovery_required') {
            $recovered = $this->recoverProcessingCommand($ledger);
            if ($recovered) return $recovered;
            $this->fail('command_recovery_required', '命令结果未知，需要人工确认或执行账本恢复。', 409, ['client_command_id' => $clientCommandId]);
        }
        if ($ledger->status === 'processing') {
            $startedAt = $ledger->processing_started_at ?: $ledger->created_at;
            $processingTimeoutSeconds = max(0, (int) env('WO02_TEST_PROCESSING_TIMEOUT_SECONDS', 300));
            if ($startedAt && $startedAt->lte(now()->subSeconds($processingTimeoutSeconds))) {
                $recovered = $this->recoverProcessingCommand($ledger);
                if ($recovered) return $recovered;
                $ledger->update(['status' => 'recovery_required', 'error_code' => 'command_recovery_required', 'error_message' => 'Command outcome is unknown; operator confirmation is required.', 'processing_finished_at' => null]);
                $this->fail('command_recovery_required', '命令结果未知，需要人工确认或执行账本恢复。', 409, ['client_command_id' => $clientCommandId]);
            }
            $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        }
        return $ledger;
    }

    /**
     * A timeout can leave a durable processing ledger while the business outcome
     * is unknown. Only an exact command marker proves that a WorkOrder is the
     * submitted result; otherwise recovery stays explicit and retry is blocked.
     */
    private function recoverProcessingCommand(WorkOrderCommandLedger $ledger): ?WorkOrder
    {
        $workOrder = $ledger->result_id
            ? WorkOrder::query()->whereKey((int) $ledger->result_id)->first()
            : null;
        if (! $workOrder && $ledger->command_type === 'create_draft') {
            $workOrder = WorkOrder::query()->where('origin_command_id', $ledger->client_command_id)->first();
        }
        if (! $workOrder && $ledger->command_type !== 'create_draft' && $ledger->aggregate_id) {
            $workOrder = WorkOrder::query()->whereKey((int) $ledger->aggregate_id)
                ->where('last_command_id', $ledger->client_command_id)->first();
        }
        if (! $workOrder) return null;

        $updated = DB::table('erp_work_order_command_ledgers')
            ->where('id', $ledger->id)
            ->whereIn('status', ['processing', 'recovery_required'])
            ->update([
                'aggregate_id' => $workOrder->id,
                'status' => 'succeeded',
                'result_type' => 'work_order',
                'result_id' => $workOrder->id,
                'response_snapshot' => json_encode($workOrder->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_code' => null,
                'error_message' => null,
                'processing_finished_at' => now(),
            ]);
        if ($updated !== 1) $this->fail('concurrency_conflict', '命令恢复已被另一个请求接管。', 409, ['client_command_id' => $ledger->client_command_id]);
        return $workOrder;
    }

    private function createIndependentDraft(array $payload, object $user, array $permissions, bool $superAdmin): WorkOrder
    {
        return $this->withCommand('create_draft', null, $payload, $user, function () use ($payload, $user, $permissions, $superAdmin): WorkOrder {
            $sourceType = (string) $payload['source_type'];
            if (! in_array($sourceType, ['production_plan', 'trial', 'stock_prebuild'], true)) {
                $this->fail('validation_error', '不支持的工单来源类型。', 422);
            }
            if (in_array($sourceType, ['production_plan', 'trial'], true)) {
                $this->fail('source_module_unavailable', $sourceType === 'production_plan' ? '生产计划来源模块尚未正式开放，不能创建生产计划工单。' : '试制来源模块尚未正式开放，不能创建试制工单。', 422);
            }
            $this->assertQuantityPrecision($payload['target_qty'] ?? null);
            $quantity = $this->decimal($payload['target_qty'] ?? null);
            $this->assertPositiveQuantity($quantity);
            $this->assertResponsibleUser($payload['responsible_user_legacy_id'] ?? null, $user, $permissions, $superAdmin);

            $item = Item::with('unit')->whereKey((int) $payload['output_item_id'])->lockForUpdate()->first();
            if (! $item || ! $item->is_production_item || $item->status !== 'enabled') {
                $this->fail('validation_error', '产出物料不存在、已停用或不是生产物料。', 422);
            }
            $routing = ProductionRouting::with(['outputItem', 'product', 'sku', 'operations.operation'])
                ->whereKey((int) $payload['production_routing_id'])->lockForUpdate()->first();
            if (! $routing || $routing->status !== 'active' || (int) $routing->output_item_id !== (int) $item->id) {
                $this->fail('routing_invalid', '所选工艺路线未生效或不属于当前产出物料。', 422);
            }
            $targetNodeId = isset($payload['target_routing_operation_id']) ? (int) $payload['target_routing_operation_id'] : null;
            $targetNode = $targetNodeId ? $routing->operations->firstWhere('id', $targetNodeId) : null;
            if (! $targetNode) $this->fail($targetNodeId ? 'target_routing_operation_invalid' : 'target_routing_operation_required', $targetNodeId ? '目标路线工序不属于当前工艺路线。' : '备货工单必须选择当前路线中的目标工序节点。', 422);

            $number = isset($payload['reservation_token'])
                ? $this->documentNumbers->reservedNumber($payload['reservation_token'], 'work_order', $this->userId($user), $payload['creation_session_id'] ?? null)
                : $this->documentNumbers->next('work_order');
            $snapshot = $this->productionMasterData->snapshot($routing);
            $targetSnapshot = collect($snapshot['operations'])->firstWhere('routing_operation_id', $targetNodeId);
            $workOrder = WorkOrder::create([
                'work_order_no' => $number,
                'source_type' => $sourceType,
                'source_id' => null,
                'source_no_snapshot' => $this->documentNumbers->next('stock_prebuild'),
                'source_title_snapshot' => '系统备货',
                'production_demand_id' => null,
                'output_item_id' => $item->id,
                'production_routing_id' => $routing->id,
                'routing_version_snapshot' => $routing->version,
                'routing_snapshot' => $snapshot,
                'target_operation_id' => $targetNode->operation_id,
                'target_routing_operation_id' => $targetNodeId,
                'origin_command_id' => $payload['client_command_id'],
                'target_unit_id' => $item->unit_id,
                'target_unit_name_snapshot' => $item->unit?->unit_name,
                'target_qty' => $quantity,
                'target_base_qty' => $quantity,
                'base_unit_id' => $item->unit_id,
                'base_unit_name_snapshot' => $item->unit?->unit_name,
                'planned_date' => $payload['planned_date'] ?? null,
                'production_batch' => $payload['production_batch'] ?? null,
                'responsible_user_legacy_id' => $payload['responsible_user_legacy_id'] ?? null,
                'production_location_name' => $payload['production_location_name'] ?? null,
                'status' => self::DRAFT,
                'business_version' => 1,
                'organization_code' => $user->organization_code ?? null,
                'created_by_legacy_id' => $this->userId($user),
                'updated_by_legacy_id' => $this->userId($user),
            ]);
            if (isset($payload['reservation_token'])) {
                $this->documentNumbers->consume($payload['reservation_token'], 'work_order', $number, $this->userId($user), 'work_order', $workOrder->id);
            }
            $this->recordStatus($workOrder, null, self::DRAFT, '创建草稿', 0, 1, $user);
            $workOrder->setAttribute('target_routing_operation_snapshot', $targetSnapshot);
            return $workOrder->fresh(['statusLogs', 'outputItem', 'routing.operations.operation', 'targetOperation', 'targetRoutingOperation.operation']);
        });
    }

    private function updateIndependentDraft(int $id, array $payload, object $user, array $permissions, bool $superAdmin): WorkOrder
    {
        $workOrder = $this->lockWorkOrder($id);
        $this->assertWorkOrderVisible($workOrder, $user, $permissions, $superAdmin);
        $this->assertExpectedVersion($workOrder, $payload);
        $this->assertState($workOrder, [self::DRAFT]);
        if (isset($payload['target_qty'])) $this->assertQuantityPrecision($payload['target_qty']);
        $quantity = isset($payload['target_qty']) ? $this->decimal($payload['target_qty']) : (float) $workOrder->target_qty;
        $this->assertPositiveQuantity($quantity);
        $this->assertResponsibleUser($payload['responsible_user_legacy_id'] ?? $workOrder->responsible_user_legacy_id, $user, $permissions, $superAdmin);
        $routingId = (int) ($payload['production_routing_id'] ?? $workOrder->production_routing_id);
        $routing = ProductionRouting::with(['outputItem', 'product', 'sku', 'operations.operation'])->whereKey($routingId)->lockForUpdate()->first();
        if (! $routing || $routing->status !== 'active' || (int) $routing->output_item_id !== (int) $workOrder->output_item_id) {
            $this->fail('routing_invalid', '所选工艺路线未生效或不属于当前产出物料。', 422);
        }
        $targetNodeId = array_key_exists('target_routing_operation_id', $payload) ? (int) $payload['target_routing_operation_id'] : (int) $workOrder->target_routing_operation_id;
        $targetNode = $routing->operations->firstWhere('id', $targetNodeId);
        if (! $targetNode) $this->fail('target_routing_operation_invalid', '目标路线工序不属于当前工艺路线。', 422);
        $before = $this->editableSnapshot($workOrder);
        $workOrder->fill(collect($payload)->only(['planned_date', 'production_batch', 'responsible_user_legacy_id', 'production_location_name'])->all());
        $workOrder->target_qty = $quantity;
        $workOrder->target_base_qty = $quantity;
        $workOrder->production_routing_id = $routing->id;
        $workOrder->routing_version_snapshot = $routing->version;
        $workOrder->routing_snapshot = $this->productionMasterData->snapshot($routing);
        $workOrder->target_operation_id = $targetNode->operation_id;
        $workOrder->target_routing_operation_id = $targetNodeId;
        $workOrder->business_version++;
        $workOrder->updated_by_legacy_id = $this->userId($user);
        $workOrder->save();
        $this->recordOperationAudit($workOrder, $before, $this->editableSnapshot($workOrder), $payload['reason'] ?? null, $user);
        $this->recordStatus($workOrder, self::DRAFT, self::DRAFT, '编辑草稿', $workOrder->business_version - 1, $workOrder->business_version, $user);
        return $workOrder->fresh(['statusLogs', 'outputItem', 'routing.operations.operation', 'targetOperation', 'targetRoutingOperation.operation']);
    }

    private function lockDemand(int $id): ProductionDemand
    {
        $demand = ProductionDemand::query()->whereKey($id)->lockForUpdate()->first();
        if (! $demand) $this->fail('not_found', '生产需求不存在。', 404);
        return $demand;
    }

    private function lockWorkOrder(int $id): WorkOrder
    {
        $workOrder = WorkOrder::query()->whereKey($id)->lockForUpdate()->first();
        if (! $workOrder) $this->fail('not_found', '工单不存在。', 404);
        return $workOrder;
    }

    private function assertDemandReady(ProductionDemand $demand): void
    {
        // is_ready_for_work_order 是“已有有效工单分配”的投影，不是来源门禁；门禁由
        // active + ready/confirmed + BOM matched 组成，避免销售确认阶段的 false 被误当成阻断。
        if (! $demand->is_active || ! in_array((string) $demand->requirement_status, ['ready', 'confirmed'], true)) {
            $this->fail('invalid_state', '当前生产需求不是可拆分状态。', 422, ['requirement_status' => $demand->requirement_status]);
        }
        if ($demand->bom_match_status !== null && $demand->bom_match_status !== 'matched') {
            $this->fail('invalid_state', '当前生产需求的 BOM 尚未满足工单门禁。', 422, ['bom_match_status' => $demand->bom_match_status]);
        }
        if ((float) ($demand->remaining_qty ?? 0) <= 0) {
            $this->fail('quantity_conflict', '当前生产需求没有剩余可拆数量。', 409, ['remaining_qty' => (float) ($demand->remaining_qty ?? 0)]);
        }
    }

    private function assertAvailableQuantity(ProductionDemand $demand, float $quantity, ?int $exceptWorkOrderId = null): void
    {
        $workOrderAllocated = (float) WorkOrder::query()->where('production_demand_id', $demand->id)
            ->whereIn('status', self::ALLOCATING_STATUSES)
            ->when($exceptWorkOrderId, fn (Builder $query) => $query->where('id', '<>', $exceptWorkOrderId))
            ->sum('target_qty');
        $persistedAllocated = (float) ($demand->allocated_qty ?? 0);
        if ($exceptWorkOrderId) {
            $persistedAllocated -= (float) (WorkOrder::query()->whereKey($exceptWorkOrderId)->value('target_qty') ?? 0);
        }
        $allocated = max(0, $persistedAllocated, $workOrderAllocated);
        $available = max(0, (float) $demand->production_qty - (float) $demand->consumed_qty - (float) $demand->closed_qty - $allocated);
        if ($quantity > $available + 0.00000001) {
            $this->fail('quantity_conflict', '工单目标数量超过当前可拆数量。', 409, [
                'demand_qty' => (float) $demand->production_qty,
                'consumed_qty' => (float) $demand->consumed_qty,
                'closed_qty' => (float) $demand->closed_qty,
                'allocated_qty' => $allocated,
                'available_qty' => $available,
            ]);
        }
    }

    private function refreshDemandProjection(ProductionDemand $demand): void
    {
        $allocated = (float) WorkOrder::query()->where('production_demand_id', $demand->id)
            ->whereIn('status', self::ALLOCATING_STATUSES)->sum('target_qty');
        $remaining = max(0, (float) $demand->production_qty - (float) $demand->consumed_qty - (float) $demand->closed_qty - $allocated);
        // consumed_qty 是真实执行消耗，只能由后续执行事实写入；草稿/待发布工单只能占用 allocated_qty。
        $demand->allocated_qty = $allocated;
        $demand->remaining_qty = $remaining;
        $demand->is_ready_for_work_order = $allocated > 0;
        $demand->business_version = (int) ($demand->business_version ?: 1) + 1;
        $demand->save();
    }

    private function workOrderAttributes(ProductionDemand $demand, array $payload, float $quantity, object $user): array
    {
        $line = $demand->line ?: $demand->load('line')->line;
        $targetUnitId = $line?->unit_id;
        $baseUnitId = $demand->base_unit_id;
        $baseFactor = (float) ($line?->item_base_required_qty ?: 0);
        $productionQty = (float) ($demand->production_qty ?: 0);
        $targetBaseQty = $productionQty > 0 && $baseFactor > 0 ? $quantity * $baseFactor / $productionQty : $quantity;
        $outputItemId = (int) (($demand->item_id ?: $line?->item_id) ?? 0);
        $routing = $outputItemId > 0 ? ProductionRouting::with(['outputItem', 'product', 'sku', 'operations.operation'])
            ->where('output_item_id', $outputItemId)->where('status', 'active')->where('is_default', true)->lockForUpdate()->first() : null;
        return array_merge([
            'work_order_no' => $this->documentNumbers->next('work_order'),
            'source_type' => 'sales_order',
            'source_id' => $demand->sales_order_id,
            'source_no_snapshot' => $demand->order?->sales_order_no,
            'source_title_snapshot' => $demand->requirement_no,
            'production_demand_id' => $demand->id,
            'output_item_id' => $outputItemId ?: null,
            'production_routing_id' => $routing?->id,
            'routing_version_snapshot' => $routing?->version,
            'routing_snapshot' => $routing ? $this->productionMasterData->snapshot($routing) : null,
            'origin_command_id' => $payload['client_command_id'] ?? null,
            'target_unit_id' => $targetUnitId,
            'target_unit_name_snapshot' => $line?->unit_name_snapshot,
            'target_qty' => $quantity,
            'target_base_qty' => $targetBaseQty,
            'base_unit_id' => $baseUnitId,
            'base_unit_name_snapshot' => $demand->base_unit_name_snapshot,
            'bom_id' => $demand->bom_id,
            'bom_version_id' => $demand->bom_version_id,
            'bom_version' => $demand->bom_version,
            'status' => self::DRAFT,
            'business_version' => 1,
            'organization_code' => $this->trustedOrganization($user, $demand),
            'created_by_legacy_id' => $this->userId($user),
            'updated_by_legacy_id' => $this->userId($user),
        ], $this->editableAttributes($payload, $quantity, $demand));
    }

    private function editableAttributes(array $payload, float $quantity, ProductionDemand $demand): array
    {
        return [
            'target_qty' => $quantity,
            'target_base_qty' => $this->targetBaseQuantity($demand, $quantity),
            'planned_date' => $payload['planned_date'] ?? null,
            'production_batch' => $payload['production_batch'] ?? null,
            'responsible_user_legacy_id' => $payload['responsible_user_legacy_id'] ?? null,
            'production_location_name' => $payload['production_location_name'] ?? null,
        ];
    }

    private function targetBaseQuantity(ProductionDemand $demand, float $quantity): float
    {
        $line = $demand->line ?: $demand->load('line')->line;
        $factor = (float) ($line?->item_base_required_qty ?: 0);
        return $factor > 0 && (float) $demand->production_qty > 0
            ? $quantity * $factor / (float) $demand->production_qty
            : $quantity;
    }

    private function recordStatus(WorkOrder $workOrder, ?string $before, string $after, string $reason, int $beforeVersion, int $afterVersion, object $user): void
    {
        WorkOrderStatusLog::create([
            'work_order_id' => $workOrder->id,
            'before_status' => $before,
            'after_status' => $after,
            'reason' => $reason,
            'operator_legacy_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'organization_code' => $workOrder->organization_code,
            'before_version' => $beforeVersion,
            'after_version' => $afterVersion,
            'occurred_at' => now(),
        ]);
    }

    private function assertExpectedVersion(WorkOrder $workOrder, array $payload): void
    {
        if (! array_key_exists('expected_version', $payload)) $this->fail('validation_error', 'expected_version 不能为空。');
        if ((int) $payload['expected_version'] !== (int) $workOrder->business_version) {
            $this->fail('version_conflict', '工单版本已变化，请刷新后重试。', 409, [
                'expected_version' => (int) $payload['expected_version'],
                'current_version' => (int) $workOrder->business_version,
            ]);
        }
    }

    private function assertExpectedDemandVersion(ProductionDemand $demand, array $payload): void
    {
        if (! array_key_exists('expected_demand_version', $payload)) {
            $this->fail('validation_error', 'expected_demand_version 不能为空。');
        }
        if ((int) $payload['expected_demand_version'] !== (int) ($demand->business_version ?: 1)) {
            $this->fail('version_conflict', '生产需求版本已变化，请刷新后重试。', 409, [
                'expected_version' => (int) $payload['expected_demand_version'],
                'current_version' => (int) ($demand->business_version ?: 1),
            ]);
        }
    }

    private function editableSnapshot(WorkOrder $workOrder): array
    {
        return [
            'target_qty' => (float) $workOrder->target_qty,
            'planned_date' => optional($workOrder->planned_date)->format('Y-m-d'),
            'production_batch' => $workOrder->production_batch,
            'responsible_user_legacy_id' => $workOrder->responsible_user_legacy_id ? (int) $workOrder->responsible_user_legacy_id : null,
            'production_location_name' => $workOrder->production_location_name,
            'business_version' => (int) $workOrder->business_version,
        ];
    }

    private function recordOperationAudit(WorkOrder $workOrder, array $before, array $after, ?string $reason, object $user): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'work_order',
            'action' => 'edit_draft',
            'target_type' => 'work_order',
            'target_id' => $workOrder->id,
            'old_snapshot' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_snapshot' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reason' => $reason,
            'operator_id' => $this->userId($user),
            'operator_name' => $user->nickname ?? $user->username ?? null,
            'created_at' => now(),
        ]);
    }

    private function assertState(WorkOrder $workOrder, array $states): void
    {
        if (! in_array((string) $workOrder->status, $states, true)) {
            $this->fail('invalid_state', '当前工单状态不允许执行该操作。', 422, [
                'current_status' => $workOrder->status,
                'allowed_statuses' => $states,
            ]);
        }
    }

    private function assertResponsibleUser(mixed $id, object $user, array $permissions, bool $superAdmin): void
    {
        if ($id === null || $id === '') return;
        $responsibleUserId = (int) $id;
        if (! DB::table('erp_legacy_admin_users')->where('legacy_id', $responsibleUserId)->where('status', 'normal')->exists()) {
            $this->fail('validation_error', '负责人不存在或已停用。', 422, ['responsible_user_legacy_id' => $id]);
        }
        if (! $this->userProjections->isProductionUser($responsibleUserId)) {
            $this->fail('validation_error', '负责人不是当前有效的生产用户。', 422, ['responsible_user_legacy_id' => $id]);
        }
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (($scope['mode'] ?? 'deny') === 'deny'
            || (($scope['mode'] ?? 'deny') !== 'all' && ! in_array($responsibleUserId, (array) ($scope['user_ids'] ?? []), true))) {
            $this->fail('data_scope_denied', '负责人不在当前用户授权的数据范围内。', 403, ['responsible_user_legacy_id' => $id]);
        }
    }

    private function assertUnitContract(ProductionDemand $demand, array $payload): void
    {
        $line = $demand->line ?: $demand->load('line')->line;
        if (array_key_exists('target_unit_id', $payload)
            && (int) $payload['target_unit_id'] !== (int) ($line?->unit_id ?? 0)) {
            $this->fail('unit_mismatch', '工单计量单位必须继承销售订单行计量单位。', 422, [
                'expected_unit_id' => $line?->unit_id,
                'target_unit_id' => $payload['target_unit_id'],
            ]);
        }
        if (array_key_exists('base_unit_id', $payload)
            && (int) $payload['base_unit_id'] !== (int) ($demand->base_unit_id ?? 0)) {
            $this->fail('unit_mismatch', '工单基准单位必须继承生产需求基准单位。', 422, [
                'expected_unit_id' => $demand->base_unit_id,
                'base_unit_id' => $payload['base_unit_id'],
            ]);
        }
    }

    private function assertQuantityPrecision(mixed $value, int $scale = 4): void
    {
        $text = trim((string) $value);
        if ($text === '' || ! preg_match('/^\d+(?:\.(\d+))?$/', $text, $matches)
            || strlen($matches[1] ?? '') > $scale) {
            $this->fail('quantity_precision', "工单目标数量最多支持 {$scale} 位小数。", 422, ['target_qty' => $value]);
        }
    }

    private function trustedOrganization(object $user, ProductionDemand $demand): ?string
    {
        // 当前 legacy user 表没有可写组织字段；只有可信上下文/需求主数据已有值时才保存，
        // 不接受客户端 organization_code，避免借工单组织字段扩大可见范围。
        foreach ([($user->organization_code ?? null), ($demand->organization_code ?? null)] as $value) {
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }

    private function assertPermission(array $permissions, string $required, bool $superAdmin): void
    {
        if (! in_array($required, $permissions, true)) {
            $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $required]);
        }
    }

    private function assertDemandVisible(ProductionDemand $demand, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.demand.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->demandVisible($demand, $scope)) {
            $this->fail('data_scope_denied', '当前用户不在该生产需求的数据范围内。', 403);
        }
    }

    private function assertWorkOrderVisible(WorkOrder $workOrder, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.work_order.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($workOrder, $scope)) {
            $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403);
        }
    }

    private function userId(object $user): int
    {
        return (int) ($user->legacy_id ?? $user->id ?? 0);
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0;
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) $this->fail('validation_error', '工单目标数量必须大于 0。', 422, ['target_qty']);
    }

    private function sortPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) $payload[$key] = $this->sortPayload($value);
        }
        ksort($payload);
        return $payload;
    }

    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }

    private function persistenceFailure(QueryException $exception): WorkOrderDomainException
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        if ($sqlState === '40001' || in_array($driverCode, ['1205', '1213'], true)) {
            return new WorkOrderDomainException('concurrency_conflict', '工单并发写入被数据库回滚或等待超时，请重试。', 409, [
                'sql_state' => $sqlState,
                'driver_code' => $driverCode, // concurrency-branch
            ]);
        }
        if ($sqlState === '23000' || $driverCode === '1062') {
            return new WorkOrderDomainException('persistence_conflict', '工单保存发生数据冲突，请刷新后重试。', 409, [
                'sql_state' => $sqlState,
                'driver_code' => $driverCode,
            ]);
        }
        return new WorkOrderDomainException('persistence_error', '工单数据保存失败，请联系管理员。', 500, [
            'sql_state' => $sqlState,
            'driver_code' => $driverCode,
        ]);
    }
}
