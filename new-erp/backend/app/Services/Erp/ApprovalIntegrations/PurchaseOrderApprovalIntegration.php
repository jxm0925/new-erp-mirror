<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\PurchaseOrder;
use App\Services\Erp\ApprovalTriggerEngine;
use Illuminate\Validation\ValidationException;

class PurchaseOrderApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(PurchaseOrder $order, object $initiator): ApprovalTask
    {
        $result = $this->triggers->dispatch('PURCHASE_ORDER', $order->id, 'submit_approval', $initiator, [
            'integration_source' => 'purchase_order_submit',
            'subject' => '采购订单 '.$order->purchase_order_no,
        ]);
        // 采购订单必须经正式审核才能到货；无匹配任务时回滚外层提交事务，
        // 避免留下“待审核”但审核工作台无任务、也无法再次提交的悬空单据。
        $task = $result['task'] ?? null;
        if (!$task instanceof ApprovalTask) {
            throw ValidationException::withMessages([
                'approval_flow' => '未匹配到采购订单审核流程，请先发布适用流程并检查启动条件，订单仍保留原状态。',
            ]);
        }
        return $task;
    }
}
