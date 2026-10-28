<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessAction;
use App\Exceptions\ApprovalActionExecutionException;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use Illuminate\Support\Facades\DB;

/**
 * Compatibility facade retained for the existing task service.
 * Concrete business behavior is resolved from the registered action handler,
 * never from a business-type branch in the approval core.
 */
class ApprovalBusinessAdapterRegistry
{
    public function __construct(private readonly BusinessActionRegistry $actions) {}

    public function decide(ApprovalTask $task, ApprovalTaskNode $node, string $decision, string $operator, ?string $comment): array
    {
        // 自动节点会捕获异常继续提交外层事务，整组完成动作必须有独立回滚边界。
        return DB::transaction(fn () => $this->executeDecision($task, $node, $decision, $operator, $comment));
    }

    private function executeDecision(ApprovalTask $task, ApprovalTaskNode $node, string $decision, string $operator, ?string $comment): array
    {
        $definition = (array) data_get($task->flow_snapshot, 'definition', []);
        $configuredNodeAction = collect((array) ($definition['completion_actions'] ?? []))->firstWhere('event', 'node_decision');
        // 已运行的旧版本没有显式节点动作，继续使用原注册处理器；新版本遵从冻结配置。
        $nodeAction = ApprovalBusinessAction::query()->where('enabled', true)->where('result_event', 'node_decision')
            ->when($configuredNodeAction, fn ($query) => $query->where('action_code', $configuredNodeAction['action_key'] ?? ''))
            ->whereHas('businessObject', fn ($q) => $q->where('object_code', $task->business_object_code))->first();
        if ($configuredNodeAction && !$nodeAction) {
            throw new ApprovalActionExecutionException((string) ($configuredNodeAction['action_key'] ?? ''), '节点审核处理动作不存在、已停用或不适用于当前业务对象。');
        }
        $hasNext = ApprovalTaskNode::query()->where('approval_task_id', $task->id)
            ->where('node_order', '>', $node->node_order)->where('node_status', 'WAITING')->exists();
        $result = [
            'candidate_status' => $decision === 'reject' ? 'REJECTED' : ($hasNext ? 'PENDING' : 'APPROVED'),
            'business_type' => $task->business_type, 'business_id' => $task->business_id,
        ];
        if ($nodeAction) {
            $result = $this->actions->execute($nodeAction->action_code, $task, $node, (array) ($configuredNodeAction['config'] ?? []), $operator, $decision, $comment, 'node_decision');
        }
        $event = $decision === 'reject' ? 'rejected' : 'approved';
        $results = [];
        if (($decision === 'reject' || !$hasNext) && in_array($result['candidate_status'] ?? '', ['APPROVED', 'REJECTED'], true)) {
            foreach ((array) ($definition['completion_actions'] ?? []) as $index => $configured) {
                if (($configured['event'] ?? '') !== $event) continue;
                $actionCode = (string) ($configured['action_key'] ?? '');
                try {
                    $actionResult = $this->actions->execute($actionCode, $task, $node, (array) ($configured['config'] ?? []), $operator, $decision, $comment, $event);
                } catch (ApprovalActionExecutionException $exception) {
                    throw new ApprovalActionExecutionException($actionCode, '第'.($index + 1).'条处理动作执行失败：'.$exception->getMessage(), $exception);
                }
                $results[] = [
                    'position' => $index + 1, 'action_key' => $actionCode,
                    'name' => $configured['name'] ?? null,
                    'result' => $actionResult,
                ];
            }
        }
        return $result + ['action_key' => $results[0]['action_key'] ?? null, 'action_result' => $results[0]['result'] ?? null, 'action_results' => $results];
    }
}
