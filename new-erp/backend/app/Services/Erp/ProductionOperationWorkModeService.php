<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionTask;

class ProductionOperationWorkModeService
{
    public function recalculate(ProductionTask $task, object $target, string $type, $now, bool $incrementVersion = true): void
    {
        if (! in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true)) return;

        $active = ProductionLaborSession::query()
            ->where('target_type', $type)
            ->where('target_id', $target->id)
            ->where('status', 'ACTIVE')
            ->exists();
        $automatic = ($target->work_mode_snapshot ?: 'manual') === 'automatic';
        $status = $automatic || $active ? 'IN_PROGRESS' : 'PAUSED';

        $target->status = $status;
        $target->paused_at = $status === 'PAUSED' ? ($target->paused_at ?: $now) : null;
        if ($incrementVersion) $target->business_version = (int) $target->business_version + 1;
        $target->save();

        $task->targets()->where('target_type', $type)->where('target_id', $target->id)
            ->update(['status_snapshot' => $status, 'updated_at' => $now]);
        if ($task->status !== $status) {
            $task->update(['status' => $status, 'business_version' => (int) $task->business_version + 1]);
        }
    }
}
