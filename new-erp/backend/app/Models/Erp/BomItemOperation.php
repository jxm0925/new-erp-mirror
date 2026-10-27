<?php

namespace App\Models\Erp;

/** BOM 行指定的用料工序（工序主档）。 */
class BomItemOperation extends MasterModel
{
    protected $table = 'erp_bom_item_operations';

    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
}
