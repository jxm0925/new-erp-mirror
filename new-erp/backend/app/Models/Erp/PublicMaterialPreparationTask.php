<?php

namespace App\Models\Erp;

class PublicMaterialPreparationTask extends MasterModel
{
    protected $table = 'erp_public_material_preparation_tasks';
    protected $casts = ['business_version' => 'integer'];
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function tasks() { return $this->hasMany(MaterialPickingTask::class, 'public_preparation_task_id'); }
}
