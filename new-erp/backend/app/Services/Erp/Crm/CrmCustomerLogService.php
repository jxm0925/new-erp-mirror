<?php

namespace App\Services\Erp\Crm;

use Illuminate\Support\Facades\DB;

/** 客户日志只追加；负责销售变化、身份与协作变动都在同一事务内写入，不另开连接。 */
final class CrmCustomerLogService
{
    public function write(int $customerId, string $action, string $summary, ?array $before, ?array $after, ?string $reason, ?object $actor): void
    {
        DB::table('erp_crm_customer_logs')->insert([
            'customer_id' => $customerId,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 500),
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'operator_legacy_id' => $actor?->legacy_id,
            'operator_name' => $actor ? (($actor->nickname ?? null) ?: ($actor->username ?? null)) : null,
            'created_at' => now(),
        ]);
    }
}
