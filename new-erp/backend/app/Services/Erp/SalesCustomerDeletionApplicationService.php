<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesCustomerDeletionApplicationService
{
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($customer->status, ['potential', 'disabled'], true)) {
                throw ValidationException::withMessages(['status' => '只有潜在客户或已停用客户才可能删除；正常和黑名单客户必须保留。']);
            }
            if ($customer->legacy_customer_id) {
                throw ValidationException::withMessages(['customer' => '该客户来自历史系统同步，必须保留身份映射，不能删除。']);
            }

            $references = [
                ['erp_sales_orders', ['customer_id' => $customer->id]],
                ['erp_sales_returns', ['customer_id' => $customer->id]],
                ['erp_skus', ['customer_id' => $customer->id]],
                ['erp_items', ['customer_id' => $customer->id]],
                ['erp_customization_records', ['customer_id' => $customer->id]],
                ['erp_finance_cash_documents', ['party_type' => 'customer', 'party_id' => $customer->id]],
                ['erp_finance_allocations', ['party_type' => 'customer', 'party_id' => $customer->id]],
                ['erp_finance_invoices', ['party_type' => 'customer', 'party_id' => $customer->id]],
            ];
            foreach ($references as [$table, $where]) {
                if (DB::table($table)->where($where)->exists()) {
                    throw ValidationException::withMessages(['customer' => '该客户已被订单、定制资料或财务事实引用，不能删除；请保持停用。']);
                }
            }

            // 联系人和地址仅从属于客户且有级联外键；业务单据引用在上方全部阻断。
            $customer->delete();
        }, 5);
    }
}
