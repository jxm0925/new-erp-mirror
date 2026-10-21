<?php

namespace App\Models\Erp;

/** 逐台报工：合格、不合格退回、报废。 */
class MfgUnitReport extends MasterModel
{
    protected $table = 'erp_mfg_unit_reports';
    protected $casts = ['is_rework' => 'boolean', 'reason_tags' => 'array', 'photos' => 'array', 'participants_snapshot' => 'array', 'reviewed_at' => 'datetime'];
}
