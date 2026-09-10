<?php

namespace App\Models\Erp;

class MaterialDeliveryTaskAssignment extends MasterModel
{
    protected $table = 'erp_material_delivery_task_assignments';
    protected $casts = ['claimed_at' => 'datetime', 'released_at' => 'datetime'];
}
