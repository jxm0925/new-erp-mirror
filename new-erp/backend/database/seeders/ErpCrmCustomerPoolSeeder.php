<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ErpCrmCustomerPoolSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $rows = [
            ['NEW_FAST_4H', '新线索4小时快转池', 'lead', ['lead'], ['新线索快转池']],
            ['WEEKLY_PUSH', '本周推进池', 'lead', ['lead', 'opportunity'], ['本周跟进池']],
            ['MONTHLY_NURTURE', '本月培育池', 'lead', ['lead', 'opportunity'], ['本月跟进池']],
            ['WATCH_LIST', '观察池', 'lead', ['lead', 'opportunity'], []],
            ['OLD_LEAD_WAKE', '老线索唤醒池', 'lead', ['lead'], ['老线索补位池', '老客户唤醒池']],
            ['CLOSE_TODAY', '今日成交池', 'opportunity', ['opportunity', 'lead'], []],
            ['CLOSE_TOMORROW', '明日成交池', 'opportunity', ['opportunity', 'lead'], []],
            ['PUSH_48H', '48小时推进池', 'opportunity', ['opportunity', 'lead'], []],
            ['BACKUP_POOL', '补位客户池', 'opportunity', ['opportunity', 'lead'], ['补位池']],
            ['DELIVERY_TRACK', '交付跟踪/发货池', 'customer', ['customer', 'opportunity'], ['发货池']],
            ['REPEAT_REFERRAL', '老客户转介绍/复购池', 'customer', ['customer', 'opportunity'], ['老客户转介绍/复购']],
            ['AFTERSALES_UPSELL', '售后转机会池', 'customer', ['customer', 'opportunity'], ['售后转机会']],
        ];
        foreach ($rows as $index => [$code,$name,$group,$scopes,$aliases]) {
            // 人工改名、停用、调整范围均保留；只补未存在的正式基础档案。
            if (DB::table('erp_crm_customer_pools')->where('pool_code', $code)->orWhere('pool_name', $name)->exists()) {
                continue;
            }
            DB::table('erp_crm_customer_pools')->insert(['pool_code' => $code, 'pool_name' => $name, 'group_code' => $group,
                'applicable_scopes' => json_encode($scopes, JSON_UNESCAPED_UNICODE), 'legacy_aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
                'sort_order' => ($index + 1) * 10, 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
