<?php

namespace App\Services\Erp\ApprovalIntegrations;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\InventoryAdjustment;
use App\Services\Erp\ApprovalTriggerEngine;

class InventoryAdjustmentApprovalIntegration
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function submitted(InventoryAdjustment $adjustment, object $initiator): ?ApprovalTask
    {
        return $this->triggers->submitIfRegistered('INVENTORY_ADJUSTMENT', $adjustment->id, $initiator, '库存调整 '.$adjustment->adjustment_no);
    }
}
