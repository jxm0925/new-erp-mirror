<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\PurchaseOrder;
use App\Services\Erp\ApprovalTriggerEngine;
use Illuminate\Validation\ValidationException;

class PurchaseOrderApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(PurchaseOrder $order, object $initiator): ?ApprovalTask
    {
        $result = $this->triggers->dispatch('PURCHASE_ORDER', $order->id, 'submit_approval', $initiator, [
            'integration_source' => 'purchase_order_submit',
            'subject' => '采购订单 '.$order->purchase_order_no,
        ]);
        // 仅引擎明确 BYPASS 时无需审核；任务创建异常仍须回滚提交。
        if (($result['result'] ?? null) === 'BYPASS') return null;
        $task = $result['task'] ?? null;
        if (!$task instanceof ApprovalTask) {
            throw ValidationException::withMessages([
                'approval_flow' => '采购订单审核任务未成功创建，订单仍保留原状态。',
            ]);
        }
        return $task;
    }
}
