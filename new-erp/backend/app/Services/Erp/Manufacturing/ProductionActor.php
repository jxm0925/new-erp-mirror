<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;

/** 生产服务共用：当前操作人编号/姓名、业务失败。 */
trait ProductionActor
{
    private function actorId(object $actor): ?int { $id = (int) ($actor->legacy_id ?? $actor->id ?? 0); return $id ?: null; }
    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? null) ?: '系统'); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
