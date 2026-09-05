<?php

namespace App\Models\Erp;

class ProductionExecutionCommand extends MasterModel
{
    protected $table = 'erp_production_execution_commands';
    protected $casts = ['response_snapshot' => 'array', 'processing_started_at' => 'datetime', 'processing_finished_at' => 'datetime'];
}
