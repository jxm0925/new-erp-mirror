<?php

namespace App\Models\Erp;

/** 整机 BOM 上按半成品位置 + 子 BOM 路线节点配置的整套提成；随整机使用时按此计，半成品单独销售时用它自己的 BOM 提成。 */
class BomPositionPerformance extends MasterModel
{
    public const MODES = ['inherit', 'override', 'zero'];

    protected $table = 'erp_bom_position_performance';
    protected $casts = ['bom_id' => 'integer', 'operation_id' => 'integer', 'occurrence' => 'integer'];

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id')->withTrashed(); }
    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
}
