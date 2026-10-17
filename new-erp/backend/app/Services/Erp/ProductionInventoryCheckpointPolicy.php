<?php

namespace App\Services\Erp;

/** Frozen checkpoint rules shared by inventory continuation and shipment checks. */
final class ProductionInventoryCheckpointPolicy
{
    public function isDryAssembly(int $finalItemId, int $outputItemId, string $outputMode, ?string $stage, ?string $nextStage): bool
    {
        return $finalItemId === $outputItemId && $outputMode === 'warehouse_optional'
            && $stage === 'ASSEMBLY' && $nextStage === 'WATER_TEST';
    }

    public function canHoldUnstarted(object $target): bool
    {
        return empty($target->started_at) && in_array($target->status, [
            'WAIT_PREVIOUS', 'WAIT_PREDECESSOR', 'WAIT_CLAIM', 'CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'READY',
        ], true);
    }
}
