<?php

namespace App\Models\Erp;

/** 工序任务的接单人（owner）和协同人（collaborator）。 */
class MfgTaskParticipant extends MasterModel
{
    protected $table = 'erp_mfg_task_participants';
    protected $casts = ['is_active' => 'boolean', 'share_percent' => 'decimal:4', 'joined_at' => 'datetime'];
}
