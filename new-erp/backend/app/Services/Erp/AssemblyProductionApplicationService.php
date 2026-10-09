<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\AssemblyComponentDemand;
use App\Models\Erp\AssemblyProductionPlan;
use App\Models\Erp\Bom;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Net direct components before descending into their own BOM; never explode stocked assemblies. */
final class AssemblyProductionApplicationService
{
    public function __construct(
        private readonly BomMatcher $boms,
        private readonly ProductionMasterDataService $routes,
        private readonly InventoryAvailabilityService $availability,
        private readonly ProductionPickingStockService $picking,
        private readonly AssemblyProductionInventoryService $inventory,
        private readonly ProductionDataScopeResolver $scope,
    ) {}

    public function preview(int $id, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.work_order.view');
        $wo = WorkOrder::find($id);
        if (! $wo) $this->fail('not_found', '工单不存在。', 404);
        $this->visible($wo, $user, $permissions, $superAdmin);
        $stored = $this->projection($wo);
        if ($stored) return $this->withActions($stored, $wo, $permissions);
        if ($wo->released_at || ! in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true)) return $this->empty($wo, 'legacy_snapshot', true);
        return $this->withActions(DB::transaction(fn () => $this->build($wo, false)), $wo, $permissions);
    }

    public function prepare(int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions, 'production.work_order.view');
        $this->permission($permissions, 'production.work_order.edit');
        $result = $this->runPreparation($id, $payload, $user, $permissions, $superAdmin);
        if ($result['failure'] ?? null) {
            $failure = $result['failure'];
            $this->fail($failure['code'], $failure['message'], $failure['status'], ['issues' => $result['plan']['issues']]);
        }
        return $this->withActions($result['plan'], WorkOrder::findOrFail($id), $permissions);
    }

    /**
     * The durable pending row belongs to the accepted sales transaction. The
     * callback runs only after its outermost commit; a stopped worker therefore
     * leaves an observable, retryable root rather than an unrecorded intention.
     */
    public function scheduleAutomaticLocked(WorkOrder $wo, object $user): void
    {
        if (DB::transactionLevel() < 1) $this->fail('transaction_required', '自动准备必须由销售确认事务登记。', 409);
        if (! Schema::hasTable('erp_assembly_production_plans') || $wo->assembly_component_demand_id) return;
        AssemblyProductionPlan::firstOrCreate(['root_work_order_id' => $wo->id], [
            'status' => 'PENDING', 'plan_version' => 1, 'work_order_version' => $wo->business_version,
            'organization_code' => $wo->organization_code, 'plan_snapshot' => $this->empty($wo, 'pending', false),
        ]);
        DB::afterCommit(fn () => $this->prepareAutomatic($wo, $user));
    }

    /** A best-effort follow-up must never turn an already committed sale into an HTTP failure. */
    public function prepareAutomatic(WorkOrder $wo, object $user): void
    {
        try {
            $current = WorkOrder::find($wo->id);
            if (! $current || $current->assembly_component_demand_id || $current->source_type !== 'sales_order'
                || ! in_array($current->status, ['DRAFT', 'WAIT_RELEASE'], true) || $current->released_at) return;
            if (! Schema::hasTable('erp_assembly_production_plans')) return;
            if (AssemblyProductionPlan::where('root_work_order_id', $wo->id)->whereIn('status', ['PREPARED', 'NOT_REQUIRED'])->exists()) return;
            $this->runPreparation((int) $current->id, [
                'client_command_id' => 'sales-assembly:'.$current->id.':v'.$current->business_version,
                'expected_version' => (int) $current->business_version,
            ], $user, [], false, true);
        } catch (\Throwable $exception) {
            // A deadlock may invalidate an entire MySQL transaction, so never
            // try to repair it inside its savepoint. Record only after rollback;
            // if the database is unavailable the committed PENDING row remains.
            try {
                DB::transaction(function () use ($wo, $user, $exception): void {
                    $current = WorkOrder::whereKey($wo->id)->lockForUpdate()->first();
                    if (! $current || ! in_array($current->status, ['DRAFT', 'WAIT_RELEASE'], true) || $current->released_at) return;
                    $record = AssemblyProductionPlan::where('root_work_order_id', $wo->id)->lockForUpdate()->first();
                    if ($record && in_array($record->status, ['PREPARED', 'NOT_REQUIRED', 'CANCELLED'], true)) return;
                    $this->recordFailure($current, $user, $this->safeFailure($exception), $record);
                }, 3);
            } catch (\Throwable) {
                // Do not expose database errors or throw through the sales commit.
            }
        }
    }

    private function runPreparation(int $id, array $payload, object $user, array $permissions, bool $superAdmin, bool $automatic = false): array
    {
        $command = trim((string) ($payload['client_command_id'] ?? ''));
        if ($command === '' || strlen($command) > 120) $this->fail('validation_error', '必须提供有效的操作命令号。');
        $hash = $this->hash(['work_order_id' => $id, 'expected_version' => $payload['expected_version'] ?? null,
            'actor' => $this->actor($user), 'automatic' => $automatic]);
        try {
            return DB::transaction(function () use ($id, $payload, $user, $permissions, $superAdmin, $automatic, $command, $hash): array {
                $wo = WorkOrder::whereKey($id)->lockForUpdate()->first();
                if (! $wo) $this->fail('not_found', '工单不存在。', 404);
                if (! $automatic) $this->visible($wo, $user, $permissions, $superAdmin);
                $this->editable($wo);
                if ($wo->assembly_component_demand_id) $this->fail('assembly_child_prepare_forbidden', '关联子工单由根工单统一准备，不能重复拆分。', 409);
                if ($automatic && ($wo->source_type !== 'sales_order' || ! $wo->production_demand_id)) {
                    $this->fail('automatic_preparation_source_invalid', '自动准备只能处理销售确认形成的根工单。', 409);
                }
                $existing = ProductionExecutionCommand::where('client_command_id', $command)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->command_type !== 'prepare_assembly' || $existing->aggregate_type !== 'work_order'
                        || (int) $existing->aggregate_id !== $id || (int) $existing->initiated_by_legacy_id !== $this->actor($user)
                        || ! hash_equals((string) $existing->request_hash, $hash)) {
                        $this->fail('idempotency_conflict', '同一操作命令号不能用于不同的准备内容。', 409);
                    }
                    // An exact replay may carry the version consumed by its own
                    // success, but never bypass a later edit or lifecycle change.
                    $replayVersion = (int) data_get($existing->response_snapshot, 'plan.work_order_version', 0);
                    if ($replayVersion !== (int) $wo->business_version) $this->fail('version_conflict', '工单已变化，请刷新后重试。', 409);
                    if (! in_array($existing->status, ['succeeded', 'failed'], true)) $this->fail('command_processing', '准备操作尚未完成，请稍后重试。', 409);
                    return $existing->response_snapshot;
                }
                $record = AssemblyProductionPlan::where('root_work_order_id', $id)->lockForUpdate()->first();
                $legacyReplay = false;
                // Legacy success predates the separate command ledger. Its
                // exact actor/hash may replay the version it consumed, provided
                // the prepared result is still the current work-order version.
                if ($record?->command_id === $command) {
                    $legacyHash = $this->hash(['work_order_id' => $id, 'expected_version' => $payload['expected_version'] ?? null, 'actor' => $this->actor($user)]);
                    if ($record->request_hash !== $hash && $record->request_hash !== $legacyHash) {
                        $this->fail('idempotency_conflict', '同一操作命令号不能用于不同的准备内容。', 409);
                    }
                    $legacyReplay = $record->status === 'PREPARED'
                        && (int) $record->work_order_version === (int) $wo->business_version;
                }
                if (! $legacyReplay && (int) ($payload['expected_version'] ?? 0) !== (int) $wo->business_version) {
                    $this->fail('version_conflict', '工单已变化，请刷新后重试。', 409);
                }
                if (AssemblyProductionPlan::where('command_id', $command)->where('root_work_order_id', '<>', $id)->exists()) {
                    $this->fail('idempotency_conflict', '操作命令号已用于另一张工单。', 409);
                }
                $ledger = ProductionExecutionCommand::create([
                    'client_command_id' => $command, 'command_type' => 'prepare_assembly', 'aggregate_type' => 'work_order',
                    'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'processing',
                    'initiated_by_legacy_id' => $this->actor($user), 'processing_started_at' => now(),
                ]);
                if ($legacyReplay) {
                    $result = ['plan' => $this->projection($wo), 'failure' => null];
                } else {
                try {
                    $result = DB::transaction(function () use ($wo, $user, $command, $hash, $record): array {
                        // Existing prepared assemblies retain their exact child
                        // and reservation facts; only missing material preparation
                        // is reconciled for an explicit new command.
                        $plan = $record?->status === 'PREPARED'
                            ? $this->projection($wo) : $this->prepareLocked($wo, $user, $command, $hash);
                        $materials = app(WorkOrderPreparationMaterialService::class);
                        $plan['material_preparation'] = $materials->synchronizeLocked($wo, $user);
                        foreach (WorkOrder::where('assembly_root_work_order_id', $wo->id)->whereIn('status', ['DRAFT', 'WAIT_RELEASE'])->orderBy('id')->lockForUpdate()->get() as $child) {
                            $materials->synchronizeLocked($child, $user);
                        }
                        app(ReleaseGateApplicationService::class)->evaluateLocked($wo, $user, true);
                        AssemblyProductionPlan::where('root_work_order_id', $wo->id)->firstOrFail()->update(['plan_snapshot' => $plan]);
                        return ['plan' => $plan, 'failure' => null];
                    });
                } catch (\PDOException $exception) {
                    // Laravel retries the whole root transaction, not an
                    // invalidated savepoint. No partial tree can survive.
                    throw $exception;
                } catch (\Throwable $exception) {
                    $wo->refresh();
                    $failure = $this->safeFailure($exception);
                    if ($record?->status === 'PREPARED') {
                        $record->refresh();
                        $plan = $this->projection($wo);
                        $plan['material_preparation'] = ['status' => 'blocked', 'ready' => false, 'version' => null, 'rows' => [],
                            'issues' => $failure['issues'] ?: [['code' => $failure['code'], 'message' => $failure['message']]]];
                        $record->update(['plan_snapshot' => $plan]);
                        $this->log($wo, '物料准备未完成：'.$failure['message'], (int) $wo->business_version, (int) $wo->business_version, $user);
                        $result = ['plan' => $plan, 'failure' => $failure];
                    } else {
                        $result = ['plan' => $this->recordFailure($wo, $user, $failure, $record), 'failure' => $failure];
                    }
                }
                }
                $ledger->fill(['status' => $result['failure'] ? 'failed' : 'succeeded', 'result_type' => 'work_order',
                    'result_id' => $id, 'response_snapshot' => $result, 'processing_finished_at' => now(),
                    'error_code' => $result['failure']['code'] ?? null, 'error_message' => $result['failure']['message'] ?? null])->save();
                return $result;
            }, 5);
        } catch (\PDOException $exception) {
            // A duplicate global command key from another root is a stable
            // command conflict. Other persistence failures remain retryable.
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) $this->fail('idempotency_conflict', '操作命令号已被使用，请刷新后重试。', 409);
            $this->fail('assembly_preparation_unavailable', '生产准备暂时无法完成，根工单已保留，请刷新后重试。', 503);
        }
    }

    private function recordFailure(WorkOrder $wo, object $user, array $failure, ?AssemblyProductionPlan $existing): array
    {
        $plan = $this->empty($wo, 'blocked', false);
        $plan['issues'] = $failure['issues'] ?: [['code' => $failure['code'], 'message' => $failure['message']]];
        $plan['plan_version'] = (int) ($existing?->plan_version ?? 0) + 1;
        $plan['attempted_at'] = now()->toISOString();
        $plan['attempted_by_legacy_id'] = $this->actor($user);
        $record = AssemblyProductionPlan::updateOrCreate(['root_work_order_id' => $wo->id], [
            'status' => 'BLOCKED', 'plan_version' => $plan['plan_version'], 'work_order_version' => $wo->business_version,
            'organization_code' => $wo->organization_code, 'plan_snapshot' => $plan,
        ]);
        $plan['plan_id'] = (int) $record->id;
        $record->update(['plan_snapshot' => $plan]);
        $this->log($wo, '生产准备未完成：'.$failure['message'], (int) $wo->business_version, (int) $wo->business_version, $user);
        return $plan;
    }

    private function safeFailure(\Throwable $exception): array
    {
        $business = $exception instanceof WorkOrderDomainException;
        $code = $business ? $exception->errorCode : 'assembly_preparation_failed';
        $message = $business ? $exception->getMessage() : '生产准备未完成，根工单已保留，请核对生产资料后重试。';
        // Only business messages reach the UI. SQL, stack traces and driver
        // messages are never stored in a user-visible snapshot or audit reason.
        $sanitize = static fn (string $text): string => preg_match('/SQLSTATE|password|credential|stack trace|PDOException|[A-Z]:\\\\/i', $text)
            ? '生产准备未完成，请联系管理员核对后重试。' : mb_substr($text, 0, 500);
        $issues = [];
        if ($business) foreach ((array) ($exception->details['issues'] ?? []) as $issue) {
            if (! is_array($issue)) continue;
            $issues[] = ['code' => preg_replace('/[^a-z0-9_]/i', '', (string) ($issue['code'] ?? $code)),
                'message' => $sanitize((string) ($issue['message'] ?? $message))];
            if (count($issues) >= 50) break;
        }
        return ['code' => preg_replace('/[^a-z0-9_]/i', '', $code), 'message' => $sanitize($message),
            'status' => $business ? $exception->status : 422, 'issues' => $issues];
    }

    private function prepareLocked(WorkOrder $wo, object $user, string $command, string $hash): array
    {
        $plan = $this->build($wo, true);
        if ($plan['issues'] !== []) $this->fail('assembly_plan_blocked', $plan['issues'][0]['message'], 422, ['issues' => $plan['issues']]);
        $existing = AssemblyProductionPlan::where('root_work_order_id', $wo->id)->first();
        $planVersion = (int) ($existing?->plan_version ?? 0) + 1;
        if (! $plan['required']) {
            // A no-child result is still a recorded preparation attempt. It is
            // mutable; subsequent technical edits must rebuild its material rows.
            $plan = array_replace($plan, ['status' => 'not_required', 'plan_version' => $planVersion]);
            $record = AssemblyProductionPlan::updateOrCreate(['root_work_order_id' => $wo->id], [
                'status' => 'NOT_REQUIRED', 'plan_version' => $planVersion, 'work_order_version' => $wo->business_version,
                'command_id' => $command, 'request_hash' => $hash, 'plan_snapshot' => $plan, 'organization_code' => $wo->organization_code,
            ]);
            $plan['plan_id'] = (int) $record->id;
            $record->update(['plan_snapshot' => $plan]);
            return $plan;
        }
        // Every child, reservation and preparation material commits together.
        $record = AssemblyProductionPlan::updateOrCreate(['root_work_order_id' => $wo->id], [
            'status' => 'PREPARED', 'plan_version' => $planVersion, 'work_order_version' => (int) $wo->business_version + 1,
            'input_hash' => $plan['input_hash'], 'command_id' => $command, 'request_hash' => $hash,
            'prepared_by_legacy_id' => $this->actor($user), 'prepared_at' => now(),
            'organization_code' => $wo->organization_code, 'plan_snapshot' => $plan,
        ]);
        $parents = ['root' => $wo];
        foreach ($plan['components'] as &$row) {
            $parent = $parents[$row['parent_path']];
            $row['parent_work_order_id'] = (int) $parent->id;
            if ($row['manufacturing_strategy'] !== 'make') continue;
            $demand = AssemblyComponentDemand::create([
                'assembly_plan_id' => $record->id, 'root_work_order_id' => $wo->id,
                'parent_work_order_id' => $parent->id, 'bom_item_id' => $row['bom_item_id'],
                'item_id' => $row['item_id'], 'base_unit_id' => $row['base_unit_id'], 'path_key' => $row['path_key'],
                'required_base_qty' => $row['required_base_qty'], 'inventory_reserved_base_qty' => $row['inventory_reserved_base_qty'],
                'production_base_qty' => $row['production_base_qty'], 'demand_snapshot' => $row,
                'created_by_legacy_id' => $this->actor($user),
            ]);
            $row['component_demand_id'] = (int) $demand->id;
            foreach ($row['stock_allocations'] as &$allocation) $allocation['reservation_id'] = $this->inventory->reserve($demand, $allocation, $user);
            unset($allocation);
            if (bccomp($row['production_base_qty'], '0', 8) > 0) {
                $child = app(WorkOrderApplicationService::class)->createAssemblyChildLocked($wo, $parent, $demand, $row, $user);
                $parents[$row['path_key']] = $child;
                $demand->update(['child_work_order_id' => $child->id]);
                $row['child_work_order_id'] = (int) $child->id;
                $row['child_work_order_no'] = $child->work_order_no;
            }
        }
        unset($row);
        $version = (int) $wo->business_version;
        $wo->business_version = $version + 1;
        $wo->updated_by_legacy_id = $this->actor($user);
        $wo->save();
        $this->log($wo, '准备自产部件：库存净算及建立关联子工单', $version, $wo->business_version, $user);
        $plan = array_replace($plan, ['status' => 'prepared', 'immutable' => true, 'retryable' => false, 'plan_id' => (int) $record->id,
            'plan_version' => $planVersion, 'work_order_version' => (int) $wo->business_version, 'prepared_at' => now()->toISOString(),
            'prepared_by_legacy_id' => $this->actor($user)]);
        $record->update(['plan_snapshot' => $plan]);
        return $plan;
    }

    public function projection(WorkOrder $wo): ?array
    {
        if (! Schema::hasTable('erp_assembly_production_plans')) return null;
        $rootId = (int) ($wo->assembly_root_work_order_id ?: $wo->id);
        $record = AssemblyProductionPlan::where('root_work_order_id', $rootId)->first();
        if (! $record) return null;
        $result = $record->plan_snapshot;
        $result['material_preparation'] ??= ['status' => 'not_prepared', 'ready' => false, 'issues' => [], 'version' => null, 'rows' => []];
        $result['status'] = strtolower((string) $record->status);
        $result['plan_id'] = (int) $record->id;
        $result['plan_version'] = (int) $record->plan_version;
        $result['attempt_work_order_version'] = (int) $record->work_order_version;
        $result['work_order_version'] = (int) $wo->business_version;
        $result['retryable'] = in_array($record->status, ['PENDING', 'BLOCKED'], true)
            && in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true) && ! $wo->released_at && ! $wo->assembly_component_demand_id;
        $result['view_work_order_id'] = (int) $wo->id;
        $result['immutable'] = in_array($record->status, ['PREPARED', 'CANCELLED'], true);
        if ($wo->assembly_component_demand_id) {
            // A child's visibility does not grant access to its root or sibling branches.
            // Keep the persisted plan intact and project only this child's downstream BOM.
            $components = collect($result['components'] ?? []);
            $ownDemand = $components->firstWhere('component_demand_id', (int) $wo->assembly_component_demand_id);
            $paths = $ownDemand ? [$ownDemand['path_key'] => true] : [];
            $downstream = [];
            foreach ($components as $row) {
                if (! isset($paths[$row['parent_path']])) continue;
                $paths[$row['path_key']] = true;
                $downstream[] = $row;
            }
            $result['work_order_id'] = (int) $wo->id;
            $result['components'] = $downstream;
        }
        return $result;
    }

    public function releaseCheck(WorkOrder $wo, ?Bom $bom): ?array
    {
        if (! Schema::hasTable('erp_assembly_production_plans') || ! $bom) return null;
        $hasMake = $bom->items->contains(fn ($line) => $line->componentItem?->manufacturing_strategy === 'make');
        $rootId = (int) ($wo->assembly_root_work_order_id ?: $wo->id);
        $record = AssemblyProductionPlan::where('root_work_order_id', $rootId)->lockForUpdate()->first();
        if (! $hasMake && (! $record || $record->status === 'NOT_REQUIRED')) return null;
        if ($record?->status !== 'PREPARED') return ['valid' => false, 'code' => 'assembly_preparation_required',
            'message' => data_get($record?->plan_snapshot, 'issues.0.message') ?: ($hasMake ? '自产部件必须先完成库存净算和关联子工单准备。' : '生产准备尚未完成，请核对准备原因后重试。')];
        $snapshot = $record->plan_snapshot;
        $expected = $wo->assembly_component_demand_id
            ? collect($snapshot['components'])->firstWhere('component_demand_id', (int) $wo->assembly_component_demand_id)
            : null;
        $expectedBomHash = $expected['child_bom_hash'] ?? ($snapshot['root_bom_hash'] ?? null);
        $expectedQty = $expected['production_base_qty'] ?? ($snapshot['root_base_qty'] ?? null);
        $valid = $expectedBomHash === $this->bomHash($bom) && $expectedQty !== null
            && bccomp((string) $expectedQty, (string) $wo->target_base_qty, 8) === 0;
        if (! $wo->assembly_component_demand_id) $valid = $valid && $record->input_hash === $this->inputHash($wo, $bom);
        foreach (collect($snapshot['components'])->where('parent_work_order_id', (int) $wo->id)->where('manufacturing_strategy', 'make') as $row) {
            $current = Item::with('unit')->whereKey($row['item_id'])->lockForUpdate()->first();
            $valid = $valid && $current && $current->managementScope() === 'factory' && $current->status === 'enabled' && $current->is_production_item && $current->is_stock_item
                && (int) $current->unit_id === (int) $row['base_unit_id'] && $current->unit?->status === 'enabled'
                && ! $current->unit?->is_legacy
                && bccomp($row['required_base_qty'], bcadd($row['required_base_qty'], '0', (int) $current->unit?->decimal_places), 8) === 0;
        }
        return ['valid' => $valid, 'code' => $valid ? '' : 'assembly_plan_changed',
            'message' => $valid ? '' : '工单数量、BOM 或技术资料与已准备的自产部件计划不一致，禁止发布。',
            'plan_id' => (int) $record->id];
    }

    public function assertMutable(WorkOrder $wo): void
    {
        if (! Schema::hasTable('erp_assembly_production_plans')) return;
        if ($wo->assembly_component_demand_id || AssemblyProductionPlan::where('root_work_order_id', $wo->id)->where('status', 'PREPARED')->exists()) {
            $this->fail('assembly_plan_immutable', '自产部件计划已准备，数量与生产资料不能直接修改；请在子工单发布前取消根工单后重新建立。', 409);
        }
    }

    public function cancelLocked(WorkOrder $wo, object $user, string $reason): void
    {
        if (! Schema::hasTable('erp_assembly_production_plans')) return;
        if ($wo->assembly_component_demand_id) $this->fail('assembly_child_cancel_forbidden', '关联子工单必须随根工单统一取消，不能单独取消后保留父工单需求。', 409);
        $plan = AssemblyProductionPlan::where('root_work_order_id', $wo->id)->lockForUpdate()->first();
        if (! $plan || $plan->status !== 'PREPARED') return;
        $children = WorkOrder::where('assembly_root_work_order_id', $wo->id)->orderBy('id')->lockForUpdate()->get();
        if ($children->contains(fn ($child) => ! in_array($child->status, ['DRAFT', 'WAIT_RELEASE', 'CANCELLED'], true))) {
            $this->fail('assembly_children_started', '关联子工单已发布或执行，根工单不能直接取消。', 409);
        }
        $this->inventory->releasePlan((int) $plan->id);
        foreach ($children->where('status', '<>', 'CANCELLED') as $child) {
            $before = $child->status; $version = (int) $child->business_version;
            $child->fill(['status' => 'CANCELLED', 'business_version' => $version + 1, 'cancelled_at' => now(),
                'cancelled_by_legacy_id' => (string) $this->actor($user), 'cancel_reason' => $reason,
                'updated_by_legacy_id' => $this->actor($user)])->save();
            $this->log($child, '根工单取消：'.$reason, $version, $version + 1, $user, $before);
        }
        AssemblyComponentDemand::where('assembly_plan_id', $plan->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        $plan->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
    }

    private function build(WorkOrder $wo, bool $lock): array
    {
        $wo->loadMissing('demand.line');
        $match = $this->boms->match($wo->demand?->product_id ?: $wo->demand?->line?->product_id,
            $wo->demand?->sku_id ?: $wo->demand?->line?->sku_id, (int) $wo->output_item_id,
            app(WorkOrderTechnicalService::class)->effectiveConfiguration($wo), $wo->bom_id ? (int) $wo->bom_id : null);
        $bom = ! empty($match['bom_id']) ? Bom::with('items.componentItem.unit')->whereKey($match['bom_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        $plan = $this->empty($wo, 'preview', false);
        if (! $bom) {
            $plan['issues'][] = ['code' => 'assembly_root_bom_missing', 'message' => $match['block_reason'] ?? '整机未匹配有效 BOM。'];
            return $plan;
        }
        $plan['required'] = $bom->items->contains(fn ($line) => $line->componentItem?->manufacturing_strategy === 'make');
        if (! $plan['required']) { $plan['status'] = 'not_required'; return $plan; }
        if (! data_get($wo->routing_snapshot, 'operations')) $plan['issues'][] = ['code' => 'assembly_root_routing_missing', 'message' => '请先确认整机工艺路线，再准备自产部件。'];
        if ($wo->inventory_continuation_plan) $plan['issues'][] = ['code' => 'assembly_continuation_incompatible', 'message' => '含自产部件的工单暂不允许同时配置整机库存续接，请先清空续接方案。'];
        $plan['root_bom_hash'] = $this->bomHash($bom);
        $plan['input_hash'] = $this->inputHash($wo, $bom);
        $plan['root_base_qty'] = (string) $wo->target_base_qty;
        $stockUsed = [];
        $this->walk($wo, $bom, (string) $wo->target_base_qty, 'root', [(int) $wo->output_item_id], 0, $lock, $stockUsed, $plan);
        $plan['status'] = $plan['issues'] ? 'blocked' : 'preview';
        return $plan;
    }

    private function walk(WorkOrder $root, Bom $bom, string $parentQty, string $parentPath, array $ancestors, int $depth, bool $lock, array &$stockUsed, array &$plan): void
    {
        if ($depth >= 16 || count($plan['components']) >= 500) { $plan['issues'][] = ['code' => 'assembly_tree_limit', 'message' => 'BOM 层级或部件数量超过安全范围，请先拆分核对。']; return; }
        foreach ($bom->items as $line) {
            if (count($plan['components']) >= 500) {
                if (! in_array('assembly_tree_limit', array_column($plan['issues'], 'code'), true)) {
                    $plan['issues'][] = ['code' => 'assembly_tree_limit', 'message' => 'BOM 部件数量超过 500 行，请先拆分核对。'];
                }
                return;
            }
            $item = Item::with('unit')->whereKey($line->component_item_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
            $path = hash('sha256', $parentPath.':'.$line->id);
            $required = bcadd(bcmul(bcmul((string) $line->qty, $parentQty, 16), bcadd('1', bcdiv((string) $line->loss_rate, '100', 16), 16), 16), (string) $line->fixed_qty, 16);
            $strategy = $item?->manufacturing_strategy ?: 'unspecified';
            $row = ['parent_path' => $parentPath, 'path_key' => $path, 'depth' => $depth,
                'parent_work_order_id' => $parentPath === 'root' ? (int) $root->id : null,
                'bom_id' => (int) $bom->id, 'bom_item_id' => (int) $line->id, 'item_id' => (int) $line->component_item_id,
                'item' => ['id' => $item?->id, 'code' => $item?->item_code, 'name' => $item?->item_name,
                    'spec' => $item?->spec, 'base_unit_name' => $item?->unit?->unit_name],
                'base_unit_id' => $item?->unit_id, 'manufacturing_strategy' => $strategy,
                'required_base_qty' => bcadd($required, '0', 8), 'inventory_reserved_base_qty' => '0.00000000',
                'production_base_qty' => '0.00000000', 'stock_allocations' => [], 'child_work_order_id' => null,
                'child_work_order_no' => null, 'child_bom_id' => null, 'child_routing_id' => null,
                'cut_length_mm' => $line->cut_length_mm, 'piece_qty' => $line->piece_qty];
            if (! $item || $item->status !== 'enabled' || bccomp($required, '0', 16) < 0) $plan['issues'][] = ['code' => 'assembly_component_invalid', 'message' => 'BOM 含无效或停用的部件。', 'path_key' => $path];
            if ($item && $item->managementScope() !== 'factory') {
                $plan['issues'][] = ['code' => 'office_item_not_allowed_in_production', 'message' => 'BOM 部件只能使用工厂物料，不能使用办公用品。', 'path_key' => $path];
                $plan['components'][] = $row;
                continue;
            }
            if ($strategy !== 'make') { $plan['components'][] = $row; continue; }
            // Formal material requirements store decimal(18,8). Sufficient stock
            // does not make a larger gross requirement publishable.
            if (bccomp($required, '9999999999.99999999', 16) > 0) {
                $plan['issues'][] = ['code' => 'assembly_component_quantity_capacity',
                    'message' => '自产部件 '.$item->item_name.' 的总需求超过单张工单可记录范围，请拆分需求后重新准备。',
                    'path_key' => $path, 'required_base_qty' => $row['required_base_qty'],
                    'maximum_material_requirement_qty' => '9999999999.99999999'];
                $plan['components'][] = $row;
                continue;
            }
            if (! $item?->is_stock_item || ! $item?->is_production_item || ! $item?->unit || $item->unit->status !== 'enabled'
                || $item->unit->is_legacy || (int) $line->unit_id !== (int) $item->unit_id
                || bccomp($required, bcadd($required, '0', 8), 16) !== 0
                // The established inventory ledger stores four decimal places. Do not
                // reserve a finer component quantity and silently lose its ownership tail.
                || bccomp($required, bcadd($required, '0', 4), 16) !== 0
                || bccomp($required, bcadd($required, '0', (int) ($item->unit?->decimal_places ?? 0)), 16) !== 0) {
                $plan['issues'][] = ['code' => 'assembly_component_unit_invalid', 'message' => '自产部件必须启用库存和生产使用，BOM 用量必须符合其真实库存单位及精度。', 'path_key' => $path];
                $plan['components'][] = $row; continue;
            }
            $remaining = $row['required_base_qty'];
            // Configuration-specific and physical material cannot be widened to ordinary Item stock.
            $materialConfiguration = collect(data_get($root->technical_snapshot, 'materials', []))->firstWhere('bom_item_id', (int) $line->id);
            $configured = data_get($materialConfiguration, 'configuration.id');
            if (! $item->is_custom_item && ! $configured) {
                foreach ($this->availability->eligibleBalances((int) $item->id, $lock) as $balance) {
                    if (bccomp($remaining, '0', 8) <= 0) break;
                    if ((int) $balance->unit_id !== (int) $item->unit_id) {
                        $plan['issues'][] = ['code' => 'assembly_stock_unit_mismatch', 'message' => '自产部件库存余额单位与当前部件库存单位不一致，请先核对。', 'path_key' => $path];
                        continue;
                    }
                    $outbound = $this->availability->availableForOutboundDecimal($balance);
                    $picking = $this->picking->availableDecimal($balance);
                    $available = bccomp($outbound, $picking, 8) < 0 ? $outbound : $picking;
                    $available = bcsub($available, $stockUsed[$balance->id] ?? '0', 8);
                    if (bccomp($available, '0', 8) <= 0) continue;
                    $take = bccomp($available, $remaining, 8) < 0 ? $available : $remaining;
                    $row['stock_allocations'][] = ['inventory_balance_id' => (int) $balance->id, 'warehouse_id' => (int) $balance->warehouse_id,
                        'location_id' => (int) $balance->location_id, 'batch_no' => $balance->batch_no, 'base_qty' => $take];
                    $stockUsed[$balance->id] = bcadd($stockUsed[$balance->id] ?? '0', $take, 8);
                    $remaining = bcsub($remaining, $take, 8);
                }
            }
            $row['inventory_reserved_base_qty'] = bcsub($row['required_base_qty'], $remaining, 8);
            $row['production_base_qty'] = $remaining;
            $childBom = null;
            if (bccomp($remaining, '0', 8) > 0) {
                if (in_array((int) $item->id, $ancestors, true)) $plan['issues'][] = ['code' => 'assembly_bom_cycle', 'message' => '自产部件 BOM 存在循环引用，禁止生成子工单。', 'path_key' => $path];
                elseif (bccomp($remaining, '9999999999.9999', 8) > 0) $plan['issues'][] = [
                    'code' => 'assembly_child_quantity_capacity',
                    'message' => '自产部件 '.$item->item_name.' 的待生产数量超过单张工单可记录范围，请拆分需求后重新准备。',
                    'path_key' => $path, 'production_base_qty' => $remaining, 'maximum_work_order_qty' => '9999999999.9999',
                ];
                elseif ($item->is_custom_item || $configured) $plan['issues'][] = ['code' => 'assembly_custom_configuration_required', 'message' => '定制自产部件需要独立确认生产配置，当前不能按通用 BOM 自动拆分。', 'path_key' => $path];
                else {
                    $childMatch = $this->boms->match(null, null, (int) $item->id);
                    $childBom = ! empty($childMatch['bom_id']) ? Bom::with('items.componentItem.unit')->whereKey($childMatch['bom_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
                    $routings = $this->routes->defaultRoutingMatches((int) $item->id, null, null);
                    $routing = $routings->count() === 1 ? $routings->first() : null;
                    $nodes = $routing ? $routing->operations->filter(fn ($node) => ($node->execution_context ?: 'production') === 'production')->sortBy('sequence') : collect();
                    $terminal = $nodes->last();
                    if (! $childBom || $childBom->items->isEmpty()) $plan['issues'][] = ['code' => 'assembly_child_bom_missing', 'message' => '自产部件 '.$item->item_name.' 未匹配唯一有效且完整的 BOM。', 'path_key' => $path];
                    if (! $routing || ! $terminal || (int) $terminal->output_item_id !== (int) $item->id) $plan['issues'][] = ['code' => 'assembly_child_routing_missing', 'message' => '自产部件 '.$item->item_name.' 未配置唯一默认生效路线或最终产出。', 'path_key' => $path];
                    if ($item->production_execution_mode === 'unit' && bccomp($remaining, bcadd($remaining, '0', 0), 8) !== 0) $plan['issues'][] = ['code' => 'assembly_child_quantity_precision', 'message' => '逐件自产部件缺口必须为整数，禁止自动向上取整。', 'path_key' => $path];
                    if ($item->materialManagementMode() === 'physical' || $item->cuttingMode() !== 'none') $plan['issues'][] = ['code' => 'assembly_child_output_ineligible', 'message' => '板材和长料不能作为自动备货子工单产出，请按实际部件档案配置。', 'path_key' => $path];
                    $row['child_bom_id'] = $childBom?->id;
                    $row['child_bom_hash'] = $childBom ? $this->bomHash($childBom) : null;
                    $row['child_routing_id'] = $routing?->id;
                    $row['child_target_routing_operation_id'] = $terminal?->id;
                }
            }
            $plan['components'][] = $row;
            if ($childBom && ! in_array((int) $item->id, $ancestors, true)) $this->walk($root, $childBom, $remaining, $path, [...$ancestors, (int) $item->id], $depth + 1, $lock, $stockUsed, $plan);
        }
    }

    private function withActions(array $plan, WorkOrder $wo, array $permissions): array
    {
        $editable = in_array('production.work_order.view', $permissions, true)
            && in_array('production.work_order.edit', $permissions, true)
            && in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true) && ! $wo->released_at && ! $wo->assembly_component_demand_id;
        $materialReady = (bool) data_get($plan, 'material_preparation.ready', false);
        $sameVersion = (int) ($plan['attempt_work_order_version'] ?? $plan['work_order_version'] ?? 0) === (int) $wo->business_version;
        $complete = in_array($plan['status'] ?? '', ['prepared', 'not_required'], true) && $materialReady && $sameVersion;
        $retry = in_array($plan['status'] ?? '', ['pending', 'blocked'], true)
            || data_get($plan, 'material_preparation.status') === 'blocked';
        $plan['actions'] = ['can_prepare' => $editable && ! $complete, 'can_retry' => $editable && ! $complete && $retry];
        $plan['retryable'] = $plan['actions']['can_retry'];
        $plan['work_order_version'] = (int) $wo->business_version;
        return $plan;
    }

    private function empty(WorkOrder $wo, string $status, bool $immutable): array
    {
        return ['schema_version' => 1, 'root_work_order_id' => (int) ($wo->assembly_root_work_order_id ?: $wo->id),
            'work_order_id' => (int) $wo->id, 'work_order_version' => (int) $wo->business_version,
            'plan_version' => null, 'status' => $status, 'immutable' => $immutable, 'retryable' => in_array($status, ['pending', 'blocked'], true), 'required' => false, 'issues' => [], 'components' => []];
    }

    private function bomHash(Bom $bom): string
    {
        return $this->hash(['id' => $bom->id, 'version' => $bom->version, 'output_item_id' => $bom->output_item_id,
            'status' => $bom->status, 'audit_status' => $bom->audit_status,
            'effective_date' => $bom->effective_date?->format('Y-m-d'), 'expire_date' => $bom->expire_date?->format('Y-m-d'),
            'items' => $bom->items->map(fn ($line) => ['id' => $line->id, 'item_id' => $line->component_item_id,
                'unit_id' => $line->unit_id, 'qty' => (string) $line->qty, 'loss_rate' => (string) $line->loss_rate,
                'fixed_qty' => (string) $line->fixed_qty, 'cut_length_mm' => $line->cut_length_mm,
                'cut_width_mm' => $line->cut_width_mm, 'cut_thickness_mm' => $line->cut_thickness_mm,
                'piece_qty' => $line->piece_qty, 'allow_cut_rotation' => $line->allow_cut_rotation])->all()]);
    }
    private function inputHash(WorkOrder $wo, Bom $bom): string
    {
        $routing = $wo->routing_snapshot; unset($routing['output_plan']);
        return $this->hash(['item_id' => $wo->output_item_id, 'quantity' => (string) $wo->target_base_qty,
            'base_unit_id' => $wo->base_unit_id, 'bom_hash' => $this->bomHash($bom),
            'technical_version' => (int) $wo->technical_version, 'technical_snapshot' => $wo->technical_snapshot, 'routing_snapshot' => $routing]);
    }
    private function hash(array $data): string
    {
        $sort = function ($value) use (&$sort) { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value); return array_map($sort, $value); };
        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    private function log(WorkOrder $wo, string $reason, int $before, int $after, object $user, ?string $beforeStatus = null): void
    {
        WorkOrderStatusLog::create(['work_order_id' => $wo->id, 'before_status' => $beforeStatus ?: $wo->status,
            'after_status' => $wo->status, 'reason' => $reason, 'operator_legacy_id' => $this->actor($user),
            'operator_name' => $user->nickname ?? $user->username ?? null, 'organization_code' => $wo->organization_code,
            'before_version' => $before, 'after_version' => $after, 'occurred_at' => now()]);
    }
    private function visible(WorkOrder $wo, object $user, array $permissions, bool $superAdmin): void
    {
        if (! $this->scope->workOrderVisible($wo, $this->scope->resolve($user, 'production.work_order.view', $permissions, $superAdmin))) $this->fail('data_scope_denied', '当前用户不在该工单的数据范围内。', 403);
    }
    private function editable(WorkOrder $wo): void { if ($wo->released_at || ! in_array($wo->status, ['DRAFT', 'WAIT_RELEASE'], true)) $this->fail('state_conflict', '只有草稿或待发布工单可以准备自产部件。', 409); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '没有该工单操作权限。', 403); }
    private function actor(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}


