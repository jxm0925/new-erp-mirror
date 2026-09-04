<?php

namespace App\Services\Erp\Contracts;

use App\Models\Erp\ApprovalBusinessObject;
use Illuminate\Pagination\LengthAwarePaginator;

interface ApprovalBusinessObjectProvider
{
    public function find(ApprovalBusinessObject $object, int|string $id): ?array;
    public function context(ApprovalBusinessObject $object, int|string $id, object $initiator): array;
    public function snapshot(ApprovalBusinessObject $object, int|string $id, object $initiator): array;
    public function paginate(ApprovalBusinessObject $object, array $filters): LengthAwarePaginator;
}
