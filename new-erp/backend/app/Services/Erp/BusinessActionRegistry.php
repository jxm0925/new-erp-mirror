<?php

namespace App\Services\Erp;

use App\Exceptions\ApprovalActionExecutionException;
use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalActionExecution;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use Illuminate\Validation\ValidationException;

class BusinessActionRegistry
{
    public function actions(?string $objectCode = null, ?string $event = null): array
    {
        return ApprovalBusinessAction::query()->where('enabled', true)
            ->when($event, fn ($q) => $q->where('result_event', $event))
            ->when($objectCode, fn ($query) => $query->where(function ($scope) use ($objectCode) {
                $scope->whereNull('business_object_id')
                    ->orWhereHas('businessObject', fn ($q) => $q->where('object_code', $objectCode)->where('enabled', true));
            }))->with('businessObject')->orderBy('id')->get()->map(fn ($action) => [
                'key' => $action->action_code, 'label' => $action->action_name,
                'event' => $action->result_event, 'config_schema' => $action->config_schema ?: [],
                'object_code' => $action->businessObject?->object_code,
                'requires_updates' => in_array($action->action_code, ['generic.approve.update_fields', 'generic.reject.update_fields'], true),
            ])->all();
    }

    public function execute(string $actionCode, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $action = ApprovalBusinessAction::query()->where('action_code', $actionCode)->where('enabled', true)->with('businessObject')->first();
        if (!$action) throw ValidationException::withMessages(['completion_action' => '业务动作未注册或已停用。']);
        if ($action->businessObject && $action->businessObject->object_code !== $task->business_object_code) {
            throw ValidationException::withMessages(['completion_action' => '业务动作不适用于当前业务对象。']);
        }
        $handler = app($action->handler_class);
        if (!$handler instanceof ApprovalBusinessActionHandler) throw ValidationException::withMessages(['completion_action' => '业务动作处理器类型无效。']);
        $attempt = ApprovalActionExecution::query()->where('approval_task_id', $task->id)
            ->where('action_code', $actionCode)->count() + 1;
        $execution = ApprovalActionExecution::create([
            'approval_task_id' => $task->id, 'approval_task_node_id' => $node?->id,
            'action_code' => $actionCode, 'attempt_no' => $attempt, 'execution_status' => 'RUNNING',
            'config_snapshot' => $config, 'operator_name' => $operator, 'started_at' => now(),
        ]);
        try {
            $result = $handler->execute($action, $task, $node, $config, $operator, $decision, $comment);
            $execution->update(['execution_status' => 'SUCCEEDED', 'result_snapshot' => $result, 'finished_at' => now()]);
            return $result;
        } catch (\Throwable $exception) {
            throw new ApprovalActionExecutionException($actionCode, $exception->getMessage(), $exception);
        }
    }
}
