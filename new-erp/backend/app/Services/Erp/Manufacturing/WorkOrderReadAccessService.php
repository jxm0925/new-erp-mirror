<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\AuthContextService;

/** 原生产模块按工单权限读取，报工人员只保留原详情只读入口。 */
final class WorkOrderReadAccessService
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function canView(object $actor, bool $allowExecution = false): bool
    {
        return $this->auth->isSuperAdmin($actor) || (bool) array_intersect(
            $allowExecution ? ['production.work_order.view', 'production.task.execute'] : ['production.work_order.view'],
            $this->auth->permissionCodes($actor),
        );
    }

    public function assertView(object $actor, bool $allowExecution = false): void
    {
        if (!$this->canView($actor, $allowExecution)) {
            throw new WorkOrderDomainException('permission_denied', '当前账号没有查看工单的权限。', 403);
        }
    }
}
