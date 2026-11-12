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
                ['erp_crm_leads', ['customer_id' => $customer->id]],
                ['erp_crm_opportunities', ['customer_id' => $customer->id]],
                ['erp_crm_followups', ['customer_id' => $customer->id]],
                ['erp_crm_duplicate_reviews', ['resolved_customer_id' => $customer->id]],
                ['erp_skus', ['customer_id' => $customer->id]],
                ['erp_items', ['customer_id' => $customer->id]],
                ['erp_customization_records', ['customer_id' => $customer->id]],
                ['erp_finance_cash_documents', ['party_type' => 'customer', 'party_id' => $customer->id]],
                ['erp_finance_allocations', ['party_type' => 'customer', 'party_id' => $customer->id]],
                ['erp_finance_invoices', ['party_type' => 'customer', 'party_id' => $customer->id]],
            ];
            // 已确认的平台身份是客户唯一识别依据，删客户会让后续咨询无法关联，必须先停用身份。
            if (DB::table('erp_crm_customer_identities')->where('customer_id', $customer->id)->where('status', 'confirmed')->exists()) {
                throw ValidationException::withMessages(['customer' => '该客户已绑定平台身份，不能删除；请保持停用或先停用平台身份。']);
            }
            foreach ($references as [$table, $where]) {
                if (DB::table($table)->where($where)->exists()) {
                    throw ValidationException::withMessages(['customer' => '该客户已有线索、商机、跟进、核实结果、订单、定制资料或财务记录，不能删除；请保持停用。']);
                }
            }

            // 联系人和地址仅从属于客户且有级联外键；业务单据引用在上方全部阻断。
            $customer->delete();
        }, 5);
    }
}
