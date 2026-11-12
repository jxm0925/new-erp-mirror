<?php

namespace App\Services\Erp;

/** 客户关联和财务原单共用原有读取权限；客户权限不授予财务读取。 */
final class FinanceCashDocumentReadAccessService
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function canView(object $actor): bool
    {
        return $this->auth->isSuperAdmin($actor) || in_array('finance.view', $this->auth->permissionCodes($actor), true);
    }

    public function assertView(object $actor): void
    {
        abort_unless($this->canView($actor), 403, '没有查看财务单据的权限。');
    }
}
