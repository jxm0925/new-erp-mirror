<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\PurchasePlan;
use App\Services\Erp\ApprovalTriggerEngine;

class PurchasePlanApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(PurchasePlan $plan, object $initiator): ?ApprovalTask
    {
        return $this->triggers->submitIfRegistered('PURCHASE_PLAN', $plan->id, $initiator, '采购计划 '.$plan->plan_no);
    }
}
