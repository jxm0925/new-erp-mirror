<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\Supplier;
use Illuminate\Validation\ValidationException;

class FinancePartyResolver
{
    public function resolve(string $type, int $id): array
    {
        $party = match ($type) {
            FinanceConstants::PARTY_CUSTOMER => SalesCustomer::query()->find($id),
            FinanceConstants::PARTY_SUPPLIER => Supplier::query()->find($id),
            default => null,
        };
        if (!$party) {
            throw ValidationException::withMessages(['party_id' => '交易对手不存在或当前类型不支持。']);
        }
        $name = $type === FinanceConstants::PARTY_CUSTOMER ? $party->customer_name : $party->supplier_name;
        return ['party_type' => $type, 'party_id' => $id, 'party_name_snapshot' => (string) $name];
    }
}
