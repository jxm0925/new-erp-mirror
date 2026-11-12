<?php

namespace App\Services\Erp\Crm;

use Illuminate\Database\Eloquent\Builder;

final class CrmContactPlanQueryService
{
    /** 今日包含已过计划时间的联系；逾期沿正式线索列表按当前时刻判断，两者允许交叉。 */
    public static function applyDue(Builder $query, string $column, string $type): void
    {
        $query->whereNotNull($column);
        if ($type === 'today') $query->where($column, '>=', today()->toDateTimeString())->where($column, '<', today()->addDay()->toDateTimeString());
        elseif ($type === 'overdue') $query->where($column, '<', now());
        elseif ($type === 'all') $query->where($column, '<', today()->addDay()->toDateTimeString());
        elseif ($type !== 'pending') $query->whereRaw('1 = 0');
    }
}
