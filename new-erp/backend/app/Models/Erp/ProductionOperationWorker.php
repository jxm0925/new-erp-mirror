<?php

namespace App\Models\Erp;

/** 工序能做的人：工人只看到自己能做的工序任务。 */
class ProductionOperationWorker extends MasterModel
{
    protected $table = 'erp_production_operation_workers';
}
