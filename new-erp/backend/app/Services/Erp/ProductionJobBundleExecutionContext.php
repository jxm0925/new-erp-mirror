<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionTask;

/** Only a trusted application command can authorize a bundled task; no request flag can bypass this guard. */
final class ProductionJobBundleExecutionContext
{
    private static ?int $bundleId = null;

    public static function run(int $bundleId, callable $command): mixed
    {
        $previous = self::$bundleId;
        self::$bundleId = $bundleId;
        try { return $command(); } finally { self::$bundleId = $previous; }
    }

    public static function contains(ProductionTask $task): bool
    {
        return $task->active_job_bundle_id && (int) $task->active_job_bundle_id === self::$bundleId;
    }

    public static function assertTask(ProductionTask $task): void
    {
        if ($task->active_job_bundle_id && ! self::contains($task)) {
            throw new WorkOrderDomainException('task_in_job_bundle', '该任务已安排共同加工，请在共同加工作业中执行。', 409,
                ['job_bundle_id' => (int) $task->active_job_bundle_id]);
        }
    }
}
