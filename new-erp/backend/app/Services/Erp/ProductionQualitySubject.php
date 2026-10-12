<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;

/** Validate the actual write payload, not just the route that selected its subject. */
final class ProductionQualitySubject
{
    public static function attributes(array $attributes): array
    {
        $hasOutput = ($attributes['output_record_id'] ?? null) !== null;
        $hasCuttingResult = ($attributes['cutting_result_id'] ?? null) !== null;
        if ($hasOutput === $hasCuttingResult) {
            throw new WorkOrderDomainException('production_quality_subject_invalid',
                '生产质检必须且只能关联一个生产产出或下料结果。');
        }

        return $attributes;
    }
}
