<?php

namespace App\Models\Erp;

/** 工单设计图（有版本）。 */
class MfgDrawing extends MasterModel
{
    protected $table = 'erp_mfg_drawings';
    protected $casts = ['version_no' => 'integer', 'file_size' => 'integer', 'uploaded_at' => 'datetime'];
}
