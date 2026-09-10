<?php

namespace App\Models\Erp;

class MaterialDeliveryTask extends MasterModel
{
    protected $table = 'erp_material_delivery_tasks';
    protected $casts = ['planned_start_at' => 'datetime', 'required_finish_at' => 'datetime', 'business_version' => 'integer'];

    public function wave() { return $this->belongsTo(MaterialDeliveryWave::class, 'delivery_wave_id'); }
    public function lines() { return $this->hasMany(MaterialDeliveryTaskLine::class, 'delivery_task_id'); }
    public function assignments() { return $this->hasMany(MaterialDeliveryTaskAssignment::class, 'delivery_task_id'); }
    public function events() { return $this->hasMany(MaterialDeliveryTaskEvent::class, 'delivery_task_id'); }
}
