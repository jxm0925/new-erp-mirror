<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\PurchaseReturn;
use App\Services\Erp\ApprovalTriggerEngine;

class PurchaseReturnApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(PurchaseReturn $return, object $initiator): ?ApprovalTask
    {
        return $this->triggers->submitIfRegistered('PURCHASE_RETURN', $return->id, $initiator, '采购退货 '.$return->return_no);
    }
}
