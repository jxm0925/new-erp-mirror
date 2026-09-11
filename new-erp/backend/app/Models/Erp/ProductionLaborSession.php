<?php

namespace App\Models\Erp;

class ProductionLaborSession extends MasterModel
{
    protected $table = 'erp_production_labor_sessions';
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime',
        'previous_labor_session_id' => 'integer', 'active_employee_legacy_id' => 'integer',
        'actual_labor_minutes' => 'decimal:2', 'responsibility_weight_snapshot' => 'decimal:4',
        'credited_labor_minutes' => 'decimal:2'];
    public function task() { return $this->belongsTo(ProductionTask::class, 'task_id'); }
    public function previousSession() { return $this->belongsTo(self::class, 'previous_labor_session_id'); }
}
