<?php

namespace App\Models\Erp;

/** 工序收料记录：收料时从库存扣减。 */
class MfgMaterialIssue extends MasterModel
{
    protected $table = 'erp_mfg_material_issues';
    protected $casts = ['unit_qty' => 'decimal:4', 'issue_lines' => 'array', 'cost_amount' => 'decimal:4'];
}
