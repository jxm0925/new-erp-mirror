<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePartyResolver
{
    public function resolve(string $type, int $id): array
    {
        // 多态财务引用没有party外键，必须与删除端锁同一主体并持有至写入提交。
        // 禁止事务外调用，否则FOR UPDATE会立即释放，无法保护后续创建的身份关系。
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('财务交易对手写入解析必须在业务事务内执行。');
        }
        $party = match ($type) {
            FinanceConstants::PARTY_CUSTOMER => SalesCustomer::query()->lockForUpdate()->find($id),
            FinanceConstants::PARTY_SUPPLIER => Supplier::query()->lockForUpdate()->find($id),
            default => null,
        };
        if (!$party) {
            throw ValidationException::withMessages(['party_id' => '交易对手不存在或当前类型不支持。']);
        }
        $name = $type === FinanceConstants::PARTY_CUSTOMER ? $party->customer_name : $party->supplier_name;
        return ['party_type' => $type, 'party_id' => $id, 'party_name_snapshot' => (string) $name];
    }
}
