<?php

namespace App\Models\Erp;

class BomLog extends MasterModel
{
    protected $table = 'erp_bom_logs';

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id'); }
}
