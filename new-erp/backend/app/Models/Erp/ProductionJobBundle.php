<?php

namespace App\Models\Erp;

class ProductionJobBundle extends MasterModel
{
    protected $table = 'erp_production_job_bundles';
    protected $casts = ['compatibility_snapshot' => 'array', 'business_version' => 'integer',
        'actual_labor_minutes' => 'decimal:2', 'claimed_at' => 'datetime', 'started_at' => 'datetime',
        'paused_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    public function lines() { return $this->hasMany(ProductionJobBundleLine::class, 'job_bundle_id'); }
    public function laborSessions() { return $this->hasMany(ProductionLaborSession::class, 'job_bundle_id'); }
}
