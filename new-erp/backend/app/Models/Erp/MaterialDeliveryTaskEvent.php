<?php

namespace App\Models\Erp;

class MaterialDeliveryTaskEvent extends MasterModel
{
    protected $table = 'erp_material_delivery_task_events';
    protected $casts = ['snapshot' => 'array'];
}
