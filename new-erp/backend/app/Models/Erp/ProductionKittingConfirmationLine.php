<?php

namespace App\Models\Erp;

class ProductionKittingConfirmationLine extends MasterModel
{
    protected $table = 'erp_production_kitting_confirmation_lines';
    protected $casts = ['source_facts_snapshot' => 'array'];
}
