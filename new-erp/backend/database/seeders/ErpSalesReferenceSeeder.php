<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpSalesReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('erp_sales_funding_policies')) {
            $this->upsert('erp_sales_funding_policies', ['policy_code' => 'FULL_PREPAY'], [
                'policy_name' => '全额预付',
                'policy_type' => 'full_prepay',
                'production_threshold_type' => 'ratio',
                'production_threshold_value' => 1,
                'shipment_requires_full_payment' => true,
                'status' => 'enabled',
            ]);

            $this->upsert('erp_sales_funding_policies', ['policy_code' => 'CONTRACT_INSTALLMENT'], [
                'policy_name' => '合同分次收款',
                'policy_type' => 'installment_contract',
                'production_threshold_type' => 'ratio',
                'production_threshold_value' => 0.3,
                'shipment_requires_full_payment' => true,
                'status' => 'enabled',
            ]);
        }

        if (Schema::hasTable('erp_sales_channels')) {
            $this->upsert('erp_sales_channels', ['channel_code' => 'OFFLINE_DIRECT'], [
                'channel_name' => '线下直营',
                'channel_type' => 'offline_direct',
                'transaction_mode' => 'cash_sale',
                'default_funding_policy_code' => 'FULL_PREPAY',
                'requires_external_order_no' => false,
                'is_default' => true,
                'status' => 'enabled',
                'sort' => 10,
            ]);

            $this->upsert('erp_sales_channels', ['channel_code' => 'OFFLINE_CONTRACT'], [
                'channel_name' => '线下合同',
                'channel_type' => 'offline_direct',
                'transaction_mode' => 'contract',
                'default_funding_policy_code' => 'CONTRACT_INSTALLMENT',
                'requires_external_order_no' => false,
                'is_default' => false,
                'status' => 'enabled',
                'sort' => 20,
            ]);

            $this->upsert('erp_sales_channels', ['channel_code' => 'ONLINE_PLATFORM'], [
                'channel_name' => '线上平台',
                'channel_type' => 'online_platform',
                'transaction_mode' => 'online_prepay',
                'default_funding_policy_code' => 'FULL_PREPAY',
                'requires_external_order_no' => true,
                'is_default' => false,
                'status' => 'enabled',
                'sort' => 30,
            ]);
        }
    }

    private function upsert(string $table, array $key, array $values): void
    {
        $exists = DB::table($table)->where($key)->exists();
        $payload = $values + ['updated_at' => now()];
        if (!$exists) {
            $payload['created_at'] = now();
        }
        DB::table($table)->updateOrInsert($key, $payload);
    }
}

