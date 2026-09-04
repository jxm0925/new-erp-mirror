<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\PurchaseOrder;
use App\Services\Erp\ApprovalTriggerEngine;

class PurchaseOrderApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(PurchaseOrder $order, object $initiator): ?ApprovalTask
    {
        $result = $this->triggers->dispatch('PURCHASE_ORDER', $order->id, 'submit_approval', $initiator, [
            'integration_source' => 'purchase_order_submit',
            'subject' => '采购订单 '.$order->purchase_order_no,
        ]);
        return $result['task'] ?? null;
    }
}
