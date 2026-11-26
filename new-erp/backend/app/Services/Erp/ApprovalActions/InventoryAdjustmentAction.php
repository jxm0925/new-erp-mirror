<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Models\Erp\InventoryAdjustment;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use App\Services\Erp\Inventory\WarehouseInventoryPosting;
use App\Services\Erp\InventoryAdjustmentApplicationService;

class InventoryAdjustmentAction implements ApprovalBusinessActionHandler
{
    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $operatorId = $task->latestDecisionForResult($action->result_event)?->approver_id;
        $transactionId = null;
        if ($action->result_event === 'rejected') {
            // 驳回取消未过账单，保留历史，不把已提交事实回写成草稿。
            $row = app(InventoryAdjustmentApplicationService::class)->cancel($task->business_id, $operatorId, $task);
        } else {
            // 引擎在标记任务完成前执行动作，正式过账只豁免当前任务，仍拦截其他活跃审批。
            $transactionId = app(WarehouseInventoryPosting::class)->postAdjustment($task->business_id, $operatorId, $task)->id;
            $row = InventoryAdjustment::query()->findOrFail($task->business_id);
        }

        return $row->only(['id', 'adjustment_no', 'adjustment_status', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at'])
            + ['transaction_id' => $transactionId];
    }
}
