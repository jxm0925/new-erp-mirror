<?php

namespace App\Services\Erp;

use App\Models\Erp\{ApprovalTask, ApprovalTaskNode, Bom, BomItem, BomItemOperation, BomLog, Item, Product, Sku};
use App\Services\Erp\ApprovalIntegrations\BomApprovalIntegration;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** BOM 主表、用料、定额与提交生效共用事务；审批结果也走相同的启用和默认版本校验。 */
class BomApplicationService
{
    public function create(array $payload, object $actor, bool $canEditRate): Bom
    {
        $this->authorize($actor, 'bom.manage.create');
        $this->authorizeSubmission($payload, $actor);
        return $this->command('bom.create', null, $payload, $actor, function () use ($payload, $actor, $canEditRate): Bom {
            $this->assertProductionTarget($payload);
            $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items']);
            $this->standards()->assertRouting($payload['routing_id'] ?? null, (int) $payload['output_item_id']);
            $numbers = app(DocumentNumberService::class);
            $bomNo = $numbers->reservedNumber($payload['reservation_token'], 'bom', (int) $actor->legacy_id, $payload['creation_session_id']);
            $bom = Bom::create($this->mainPayload($payload) + [
                'bom_no' => $bomNo, 'status' => 'draft', 'audit_status' => 'pending', 'submitted_at' => null, 'is_default' => false,
                'created_by' => $actor->legacy_id, 'updated_by' => $actor->legacy_id,
            ]);
            $this->saveItems($bom, $payload['items']);
            $this->standards()->save($bom, $payload['operation_standards'] ?? [], $canEditRate, $actor);
            if (isset($payload['position_performance'])) $this->positionPerformance()->save($bom->fresh('items'), $payload['position_performance'], $canEditRate, $actor);
            $this->assertNoCycle($bom->fresh('items.componentItem'));
            $this->log($bom, 'create', '新增 BOM 草稿', (int) $actor->legacy_id);
            if (! empty($payload['submit_after_save'])) $bom = $this->submitSaved($bom, $payload, $actor);
            // 提交或启用任一环节失败时，资料、审批任务和编号消费一并回滚。
            $numbers->consume($payload['reservation_token'], 'bom', $bomNo, (int) $actor->legacy_id, 'bom', $bom->id);
            return $bom;
        });
    }

    public function update(int $id, array $payload, object $actor, bool $canEditRate): Bom
    {
        $this->authorize($actor, 'bom.manage.edit');
        $this->authorizeSubmission($payload, $actor);
        return $this->command('bom.update', $id, $payload, $actor, function () use ($id, $payload, $actor, $canEditRate): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $reopen = $bom->status === 'inactive';
            abort_unless($reopen || $bom->status === 'draft', 422, '只有草稿或停用 BOM 可以编辑。');
            abort_if($bom->submitted_at && (! $reopen || $bom->audit_status === 'pending'), 422, 'BOM 已提交审核，不能继续编辑；如需修改请先驳回或复制新版本。');
            $this->assertNoPendingApproval($bom);
            $this->assertProductionTarget($payload);
            $this->assertNoCycleForCandidate((int) $payload['output_item_id'], $payload['items'], $bom->id);
            $this->standards()->assertRouting($payload['routing_id'] ?? null, (int) $payload['output_item_id']);
            $state = $reopen ? ['status' => 'draft', 'audit_status' => 'pending', 'submitted_at' => null, 'is_default' => false] : [];
            $previousPositions = $canEditRate && isset($payload['position_performance']) ? $this->positionPerformance()->tree($bom, false)['positions'] : [];
            $bom->update($this->mainPayload($payload) + $state + ['updated_by' => $actor->legacy_id]);
            // 旧明细可能已被下料产出引用，仅软删并以新行保存当前明细。
            BomItem::where('bom_id', $bom->id)->delete();
            $this->saveItems($bom, $payload['items']);
            $this->standards()->save($bom, $payload['operation_standards'] ?? [], $canEditRate, $actor);
            if (isset($payload['position_performance'])) $this->positionPerformance()->save($bom->fresh('items'), $payload['position_performance'], $canEditRate, $actor, $previousPositions);
            $this->assertNoCycle($bom->fresh('items.componentItem'));
            $this->log($bom, 'update', $reopen ? '编辑停用 BOM，已转为草稿，须重新提交生效。' : '编辑 BOM 草稿', (int) $actor->legacy_id);
            return ! empty($payload['submit_after_save']) ? $this->submitSaved($bom, $payload, $actor) : $bom;
        });
    }

    public function submit(int $id, array $options, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.edit');
        $this->authorizeSubmission($options + ['submit_after_save' => true], $actor);
        return $this->command('bom.submit', $id, $options, $actor, fn () => $this->submitSaved(Bom::query()->lockForUpdate()->findOrFail($id), $options, $actor));
    }

    private function submitSaved(Bom $bom, array $options, object $actor): Bom
    {
        abort_if($bom->status !== 'draft', 422, '只有草稿 BOM 可以提交生效。');
        abort_if($bom->submitted_at, 422, 'BOM 已提交审核，请勿重复提交。');
        $this->assertNoPendingApproval($bom);
        $this->assertReady($bom);
        $bom->update(['audit_status' => 'pending', 'submitted_at' => now(), 'updated_by' => $actor->legacy_id]);
        $this->log($bom, 'submit', '提交生效'.(! empty($options['set_default']) ? '，生效后设为默认版本' : ''), (int) $actor->legacy_id);
        if (app(BomApprovalIntegration::class)->submitted($bom, $actor, (bool) ($options['set_default'] ?? false))) return $bom->fresh();
        $this->log($bom, 'approve', '未启用提交审批流程，按提交规则直接生效', (int) $actor->legacy_id);
        $bom->update(['audit_status' => 'approved']);
        $this->activateSaved($bom, $actor);
        if (! empty($options['set_default'])) $this->setDefaultSaved($bom, $actor);
        $this->retryBlocked($bom, $actor);
        return $bom->fresh();
    }

    public function approveLegacy(int $id, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.edit');
        return DB::transaction(function () use ($id, $actor): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $this->assertLegacyReview($bom);
            $this->assertReady($bom);
            $bom->update(['audit_status' => 'approved', 'updated_by' => $actor->legacy_id]);
            $this->log($bom, 'approve', '历史待审 BOM 审核通过', (int) $actor->legacy_id);
            return $bom;
        }, 5);
    }

    public function rejectLegacy(int $id, string $reason, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.edit');
        return DB::transaction(function () use ($id, $reason, $actor): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $this->assertLegacyReview($bom);
            $bom->update(['audit_status' => 'rejected', 'submitted_at' => null, 'updated_by' => $actor->legacy_id]);
            $this->log($bom, 'reject', '历史待审 BOM 审核驳回'.($reason === '' ? '' : '：'.$reason), (int) $actor->legacy_id);
            return $bom;
        }, 5);
    }

    public function applyApprovalResult(ApprovalTask $task, ?ApprovalTaskNode $node, string $result): Bom
    {
        return DB::transaction(function () use ($task, $node, $result): Bom {
            $task = ApprovalTask::query()->lockForUpdate()->findOrFail($task->id);
            abort_unless(in_array($result, ['approved', 'rejected'], true) && $task->business_object_code === 'BOM'
                && $task->event_code === 'submit_approval' && data_get($task->metadata, 'source_table') === 'erp_boms', 422, '审核任务与 BOM 生效业务不匹配。');
            abort_if($node && (int) $node->approval_task_id !== (int) $task->id, 422, '审核节点不属于当前 BOM 审核任务。');
            $bom = Bom::query()->lockForUpdate()->findOrFail($task->business_id);
            $latest = $this->approvalTasks($bom)->latest('id')->first();
            abort_unless($latest && (int) $latest->id === (int) $task->id, 422, '该审核任务已不是当前 BOM 的最新提交，不能回写。');
            if ($task->task_status === strtoupper($result)
                && ($result === 'approved' ? $bom->audit_status === 'approved' && $bom->status === 'active' : $bom->audit_status === 'rejected' && ! $bom->submitted_at)) return $bom;
            abort_unless($task->task_status === 'PENDING' && $bom->status === 'draft' && $bom->audit_status === 'pending' && $bom->submitted_at, 422, 'BOM 或审核任务状态已变化，不能回写。');
            $configured = collect(data_get($task->flow_snapshot, 'definition.completion_actions', []))
                ->contains(fn ($action) => ($action['event'] ?? '') === $result && ($action['action_key'] ?? '') === ($result === 'approved' ? 'bom.approve' : 'bom.reject'));
            abort_unless($configured, 422, '当前流程未配置对应的 BOM 生效处理动作。');
            if ($result === 'approved') {
                $remaining = $task->nodes()->whereIn('node_status', ['WAITING', 'PENDING', 'FAILED'])
                    ->when($node, fn ($query) => $query->whereKeyNot($node->id))->exists();
                abort_if($remaining, 422, 'BOM 仍有未完成的审核节点，不能提前生效。');
            }
            $decision = $task->latestDecisionForResult($result);
            abort_if($result === 'rejected' && ! $decision, 422, '没有对应的真实驳回决定，不能退回 BOM。');
            $actor = $decision ? DB::table('erp_legacy_admin_users')->where('legacy_id', $decision->approver_id)->first() : null;
            $actor ??= (object) ['legacy_id' => $decision?->approver_id, 'nickname' => $decision?->approver_name ?: '系统', 'username' => ''];
            if ($result === 'rejected') {
                $bom->update(['status' => 'draft', 'audit_status' => 'rejected', 'submitted_at' => null, 'is_default' => false, 'updated_by' => $actor->legacy_id]);
                $this->log($bom, 'reject', '审批驳回：'.($decision?->comment ?: '审核不通过'), $actor->legacy_id);
            } else {
                $this->assertReady($bom);
                $bom->update(['audit_status' => 'approved', 'updated_by' => $actor->legacy_id]);
                $this->log($bom, 'approve', '审批通过', $actor->legacy_id);
                $this->activateSaved($bom, $actor);
                if (data_get($task->metadata, 'set_default', false)) $this->setDefaultSaved($bom, $actor);
                $this->retryBlocked($bom, $actor);
            }
            return $bom->fresh();
        }, 5);
    }

    public function activate(int $id, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $actor): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $this->assertNoPendingApproval($bom);
            $this->activateSaved($bom, $actor);
            $this->retryBlocked($bom, $actor, false);
            return $bom;
        }, 5);
    }

    private function activateSaved(Bom $bom, object $actor): void
    {
        abort_if($bom->audit_status !== 'approved', 422, 'BOM 审核通过后才能启用。');
        abort_if($bom->status === 'archived', 422, '已归档 BOM 不能启用。');
        $this->assertReady($bom);
        $bom->update(['status' => 'active', 'updated_by' => $actor->legacy_id]);
        // 新启用版本参与默认匹配后才可能闭环；在同一事务内检查，失败还原状态和审批结果。
        $this->assertNoCycle($bom, true);
        $this->log($bom, 'activate', '启用 BOM', $actor->legacy_id);
    }

    public function deactivate(int $id, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $actor): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $this->assertNoPendingApproval($bom);
            abort_if($bom->status !== 'active', 422, '只有启用 BOM 可以停用。');
            abort_if($bom->is_default, 422, '默认 BOM 不能直接停用；请先为同一生产对象选择新的默认 BOM。');
            $bom->update(['status' => 'inactive', 'updated_by' => $actor->legacy_id]);
            $this->log($bom, 'deactivate', '停用 BOM', (int) $actor->legacy_id);
            return $bom;
        }, 5);
    }

    public function setDefault(int $id, object $actor): Bom
    {
        $this->authorize($actor, 'bom.manage.toggle_status');
        return DB::transaction(function () use ($id, $actor): Bom {
            $bom = Bom::query()->lockForUpdate()->findOrFail($id);
            $this->assertNoPendingApproval($bom);
            $this->setDefaultSaved($bom, $actor);
            $this->retryBlocked($bom, $actor, false);
            return $bom;
        }, 5);
    }

    private function setDefaultSaved(Bom $bom, object $actor): void
    {
        $today = now()->startOfDay();
        abort_unless($bom->status === 'active' && $bom->audit_status === 'approved'
            && (! $bom->effective_date || $bom->effective_date->lte($today)) && (! $bom->expire_date || $bom->expire_date->gte($today)), 422, '只有已审核、已启用且在有效期内的 BOM 才能设为默认。');
        $this->assertReady($bom);
        // 产出物料作为同一生产对象的稳定锁，避免两个版本同时设默认或并发创建时漏锁。
        Item::query()->whereKey($bom->output_item_id)->lockForUpdate()->firstOrFail();
        $scope = Bom::query()->where('product_id', $bom->product_id)->where('sku_id', $bom->sku_id)->where('output_item_id', $bom->output_item_id)->orderBy('id')->lockForUpdate()->get();
        $scope->where('id', '!=', $bom->id)->where('is_default', true)->each->update(['is_default' => false, 'updated_by' => $actor->legacy_id]);
        if (! $bom->is_default) {
            $bom->update(['is_default' => true, 'updated_by' => $actor->legacy_id]);
            $this->log($bom, 'set_default', '设为默认 BOM', $actor->legacy_id);
        }
        $this->assertNoCycle($bom, true);
    }

    private function assertReady(Bom $bom): void
    {
        $bom->load('items.componentItem.unit');
        abort_if($bom->items->isEmpty(), 422, 'BOM 至少需要一行物料明细。');
        $this->assertProductionTarget($bom->only(['product_id', 'sku_id', 'output_item_id']));
        $this->assertFactoryBom($bom);
        foreach ($bom->items as $line) {
            abort_unless($line->componentItem && $line->componentItem->status === 'enabled' && $line->componentItem->unit?->status === 'enabled', 422, 'BOM 包含不存在、已停用或库存基本单位未启用的组成物料。');
        }
        $this->standards()->assertRouting($bom->routing_id, (int) $bom->output_item_id);
        app(BomOperationBindingService::class)->assertBomBindings($bom);
        $this->assertNoCycle($bom, true);
    }

    private function authorizeSubmission(array $payload, object $actor): void
    {
        if (! empty($payload['submit_after_save'])) $this->authorize($actor, 'bom.manage.edit');
        if (! empty($payload['set_default'])) {
            abort_unless(! empty($payload['submit_after_save']), 422, '设为默认版本必须与提交生效一同选择。');
            $this->authorize($actor, 'bom.manage.toggle_status');
        }
    }

    private function authorize(object $actor, string $permission): void
    {
        $auth = app(AuthContextService::class);
        abort_unless($auth->isSuperAdmin($actor) || in_array($permission, $auth->permissionCodes($actor), true), 403, '无按钮权限：'.$permission);
    }

    private function approvalTasks(Bom $bom)
    {
        return ApprovalTask::query()->where('business_object_code', 'BOM')->where('business_id', $bom->id);
    }

    private function assertNoPendingApproval(Bom $bom): void
    {
        abort_if($this->approvalTasks($bom)->where('task_status', 'PENDING')->exists(), 422, '该 BOM 已进入审核中心，请在审核工作台处理。');
    }

    private function assertLegacyReview(Bom $bom): void
    {
        abort_if($this->approvalTasks($bom)->exists(), 422, '该 BOM 使用正式审批任务，请在审核工作台处理，不能直接通过或驳回。');
        abort_unless($bom->status === 'draft' && $bom->audit_status === 'pending' && $bom->submitted_at, 422, '只有已提交的待审核 BOM 可以审核。');
    }

    public function withApprovalState(Bom $bom): Bom
    {
        $bom->loadMissing('approvalTask');
        $bom->setAttribute('approval_task_id', $bom->approvalTask?->id);
        $bom->setAttribute('approval_task_status', $bom->approvalTask?->task_status);
        $bom->setAttribute('approval_action_status', $bom->approvalTask?->action_status);
        return $bom;
    }

    public function resultMessage(Bom $bom, string $fallback): string
    {
        if ($bom->status === 'active') return 'BOM 已生效'.($bom->is_default ? '，已设为默认版本' : '');
        if ($bom->submitted_at && $bom->audit_status === 'pending') return 'BOM 已保存并提交审批，审批通过后生效';
        return $fallback;
    }

    private function retryBlocked(Bom $bom, object $actor, bool $strict = true): void
    {
        if ($strict) app(WorkOrderService::class)->retryBlockedForMasterData((int) $bom->id, null, $actor, true);
        else app(WorkOrderService::class)->retryBlockedForMasterDataAfterCommit((int) $bom->id, null, $actor);
    }

    private function command(string $type, ?int $entityId, array $payload, object $actor, callable $action): Bom
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') return DB::transaction($action, 5);
        $hash = hash('sha256', json_encode([$type, $entityId, (int) $actor->legacy_id, $payload], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        try {
            return DB::transaction(function () use ($type, $commandId, $hash, $actor, $action): Bom {
                $existing = DB::table('erp_production_master_commands')->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->commandResult($existing, $type, $hash);
                DB::table('erp_production_master_commands')->insert([
                    'client_command_id' => $commandId, 'command_type' => $type, 'entity_type' => 'bom', 'request_hash' => $hash,
                    'initiated_by_legacy_id' => $actor->legacy_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $bom = $action();
                DB::table('erp_production_master_commands')->where('client_command_id', $commandId)->update([
                    'entity_id' => $bom->id, 'response_snapshot' => json_encode(['id' => $bom->id]), 'updated_at' => now(),
                ]);
                return $bom;
            }, 5);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) throw $exception;
            $existing = DB::table('erp_production_master_commands')->where('client_command_id', $commandId)->first();
            if (! $existing) throw $exception;
            return $this->commandResult($existing, $type, $hash);
        }
    }

    private function commandResult(object $command, string $type, string $hash): Bom
    {
        if ($command->command_type !== $type || $command->entity_type !== 'bom' || $command->request_hash !== $hash || ! $command->entity_id) {
            throw ValidationException::withMessages(['client_command_id' => '该请求标识已用于不同操作，或请求仍在处理中，请核对后重试。']);
        }
        return Bom::query()->findOrFail($command->entity_id);
    }

    private function standards(): BomOperationStandardService { return app(BomOperationStandardService::class); }
    private function positionPerformance(): BomPositionPerformanceService { return app(BomPositionPerformanceService::class); }
    private function log(Bom $bom, string $action, string $message, ?int $operatorId = null): void
    {
        BomLog::create(['bom_id' => $bom->id, 'action' => $action, 'message' => $message, 'created_by' => $operatorId]);
    }

    private function mainPayload(array $payload): array
    {
        return collect($payload)->only([
            'bom_name', 'product_id', 'sku_id', 'output_item_id', 'routing_id', 'bom_type', 'version',
            'effective_date', 'expire_date', 'source_product_id', 'source_sku_id',
            'source_standard_bom_id', 'custom_description', 'remark',
        ])->all();
    }

    private function saveItems(Bom $bom, array $items): void
    {
        // 用料工序只能选产出物料适用路线里的车间工序，否则工单下达时匹配不到。
        app(\App\Services\Erp\BomOperationBindingService::class)->assertBindings((int) $bom->output_item_id,
            collect($items)->map(fn (array $line) => ['operation_id' => $line['operation_id'] ?? null,
                'label' => $line['component_item_name'] ?? \App\Models\Erp\Item::query()->whereKey($line['component_item_id'] ?? 0)->value('item_name')])->all());
        app(ItemManagementScopeService::class)->assertProductionAllowed(Item::query()->lockForUpdate()->findOrFail($bom->output_item_id), 'output_item_id');
        $duplicates = collect($items)->countBy(function (array $line): string {
            $length = array_key_exists('cut_length_mm', $line) && $line['cut_length_mm'] !== null
                ? number_format((float) $line['cut_length_mm'], 2, '.', '')
                : 'ordinary';
            return ((int) $line['component_item_id']).'|'.$length.'|'.number_format((float) ($line['cut_width_mm'] ?? 0), 2, '.', '').'|'.number_format((float) ($line['cut_thickness_mm'] ?? 0), 2, '.', '');
        })->filter(fn (int $count) => $count > 1);
        abort_if($duplicates->isNotEmpty(), 422, '同一 BOM 中相同物料与相同下料尺寸不能重复；请合并件数。');
        // 位置标识跨编辑沿用（编辑会软删旧行另存新行），重复或缺失时生成新的；行号只用于显示排序。
        $usedUids = [];
        foreach (array_values($items) as $index => $line) {
            abort_if((int) $line['component_item_id'] === (int) $bom->output_item_id, 422, 'BOM 组成物料不能等于产出物料。');
            $item = Item::with('unit')->lockForUpdate()->findOrFail($line['component_item_id']);
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items.'.$index.'.component_item_id');
            abort_if(in_array($item->status, ['disabled', 'inactive'], true), 422, '停用物料不能作为 BOM 组成物料。');
            abort_if(!$item->unit || $item->unit->status !== 'enabled', 422, 'BOM 组成 Item 必须维护启用的库存基本单位。');
            $hasCutLength = array_key_exists('cut_length_mm', $line) && $line['cut_length_mm'] !== null;
            $hasPieceQty = array_key_exists('piece_qty', $line) && $line['piece_qty'] !== null;
            app(\App\Services\Erp\CuttingOutputEligibilityService::class)->validateBomLine($item, $line);
            $cutting = in_array($item->cuttingMode(), ['length', 'sheet'], true);
            if (! $cutting) {
                abort_if($hasCutLength || $hasPieceQty, 422, "普通物料 {$item->item_name} 不能填写下料长度或段数。");
                abort_if(isset($line['total_length_mm']) || ($line['cutting_quantity_basis'] ?? 'dimensions_pieces') !== 'dimensions_pieces', 422, '普通物料不能填写管材总长度。');
                abort_if(! empty($line['stock_dimensions']), 422, '普通物料不能填写下料原料标准尺寸。');
            }
            $domain = app(\App\Services\Erp\UnitConversionDomainService::class);
            $baseUnit = $domain->canonicalUnit($item->unit);
            $quantitySnapshot = $cutting ? app(\App\Services\Erp\CuttingQuantityCalculator::class)->calculate($item, $baseUnit, $line,
                ! empty($line['stock_dimensions']) ? ['dimensions' => $line['stock_dimensions'], 'source' => ['type' => 'bom_explicit']] : null,
                'items.'.$index) : null;
            // The form prefills this exact master standard; normalize provenance only after validating all submitted dimensions.
            if ($item->cuttingMode() === 'length' && ($quantitySnapshot['stock_source']['type'] ?? null) === 'bom_explicit'
                && array_keys($quantitySnapshot['stock_dimensions'] ?? []) === ['length_mm']
                && bccomp((string) ($item->standard_stock_length_mm ?? '0'), '0', 2) > 0
                && bccomp($quantitySnapshot['stock_dimensions']['length_mm'], (string) $item->standard_stock_length_mm, 2) === 0) {
                $quantitySnapshot['stock_source'] = ['type' => 'item_standard_length', 'item_id' => (int) $item->id];
            }
            if (! $cutting) {
                $domain->assertUnitPrecision($line['qty'], $baseUnit, 'items.'.$index.'.qty');
                $domain->assertUnitPrecision($line['fixed_qty'] ?? 0, $baseUnit, 'items.'.$index.'.fixed_qty');
            } else {
                // 根/张基本单位用于整件库存；BOM 理论份额允许小数，固定耗用
                // 仍受已有 DECIMAL(14,4) 存储边界约束，不能静默截断。
                $fixed = (float) ($line['fixed_qty'] ?? 0);
                abort_if(abs($fixed - round($fixed, 4)) > 0.00000001 || $fixed > 9999999999.9999, 422, '下料固定用量最多保留四位小数。');
            }
            $childBomId = (int) ($line['child_bom_id'] ?? 0) ?: null;
            app(BomExplosionEngine::class)->assertDraftChild((int) $item->id, $childBomId, true);
            $operationId = (int) ($line['operation_id'] ?? 0);
            $uid = strtolower((string) ($line['position_uid'] ?? ''));
            if ($uid === '' || isset($usedUids[$uid])) $uid = (string) Str::uuid();
            $usedUids[$uid] = true;
            $created = BomItem::create([
                'bom_id' => $bom->id,
                'line_no' => $line['line_no'] ?? (($index + 1) * 10),
                'position_uid' => $uid,
                'child_bom_id' => $childBomId,
                'component_item_id' => $item->id,
                'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name,
                'cut_length_mm' => $hasCutLength ? $line['cut_length_mm'] : null,
                'cut_width_mm' => $line['cut_width_mm'] ?? null,
                'cut_thickness_mm' => $line['cut_thickness_mm'] ?? null,
                'allow_cut_rotation' => $line['allow_cut_rotation'] ?? false,
                'piece_qty' => $hasPieceQty ? $line['piece_qty'] : null,
                'qty' => $quantitySnapshot['stored_qty'] ?? $line['qty'],
                'cutting_quantity_snapshot' => $quantitySnapshot,
                'unit_id' => $baseUnit->id,
                'loss_rate' => $line['loss_rate'] ?? 0,
                'fixed_qty' => $line['fixed_qty'] ?? 0,
                'replaceable' => $line['replaceable'] ?? false,
                'remark' => $line['remark'] ?? null,
            ]);
            if ($operationId) BomItemOperation::create(['bom_item_id' => $created->id, 'operation_id' => $operationId]);
        }
        app(\App\Services\Erp\TechnicalDrawingSourceService::class)->retainBomPositions($bom, array_keys($usedUids));
    }

    public function assertProductionTarget(array $payload): void
    {
        $productId = $payload['product_id'] ?? null;
        $skuId = $payload['sku_id'] ?? null;
        $outputItem = Item::whereKey($payload['output_item_id'])->where('status', 'enabled')->first();
        if ($outputItem) app(ItemManagementScopeService::class)->assertProductionAllowed($outputItem, 'output_item_id');

        abort_if(
            ($productId === null) !== ($skuId === null),
            422,
            'Product 与 SKU 必须同时关联，或同时留空。'
        );

        if ($productId === null) {
            abort_unless(
                $outputItem && $outputItem->is_production_item,
                422,
                'Item-only BOM 的产出物料必须已启用且可用于生产。'
            );

            return;
        }

        $product = Product::whereKey($productId)->where('status', 'enabled')->first();
        $sku = Sku::whereKey($skuId)->where('status', 'enabled')->first();
        abort_unless($product && $sku && $outputItem, 422, '归属 Product、关联 SKU 和产出物料必须全部启用。');
        abort_if((int) $sku->product_id !== (int) $product->id, 422, '关联 SKU 不属于所选 Product。');
        $validOutput = DB::table('erp_sku_item_relations')
            ->where('sku_id', $sku->id)
            ->where('item_id', $outputItem->id)
            ->where('status', 'active')
            ->where('is_primary', true)
            ->exists();
        abort_unless($validOutput, 422, '产出物料必须是该 SKU 当前有效的默认生产/履约物料。');
    }

    public function assertFactoryBom(Bom $bom): void
    {
        $ids = array_merge([(int) $bom->output_item_id], $bom->items->pluck('component_item_id')->all());
        foreach (Item::query()->whereIn('id', $ids)->get() as $item) {
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items');
        }
    }

    private function assertNoCycle(Bom $bom, bool $strict = false): void
    {
        app(BomExplosionEngine::class)->assertGraph((int) $bom->output_item_id,
            $bom->loadMissing('items')->items->map(fn ($line) => $line->only(['component_item_id', 'child_bom_id']))->all(),
            $strict, true);
    }

    public function assertNoCycleForCandidate(int $outputItemId, array $items, ?int $ignoreBomId = null): void
    {
        app(BomExplosionEngine::class)->assertGraph($outputItemId, $items, lock: true);
    }

}
