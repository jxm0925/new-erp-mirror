<?php

namespace App\Models\Erp;

class PurchaseLog extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_logs';
    protected $casts = ['evidence' => 'array'];

    /** 普通到货详情和分页历史只显示采购记录，逐行审计含其他工单、发货单的来源快照。 */
    public function scopeWithoutCostDetails($query)
    {
        return $query->select(['id', 'target_type', 'target_id', 'action', 'operator', 'content', 'created_at', 'updated_at'])
            ->selectRaw("JSON_REMOVE(evidence, '$.cost_recalculation') AS evidence");
    }
}
