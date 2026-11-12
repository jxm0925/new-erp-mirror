<?php

namespace App\Services\Erp\Crm;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** 展示配置只调整已授权查询的顺序；分组必须先在数据库排序，再分页。 */
final class CrmListViewQueryService
{
    public static function rules(string $resource): array
    {
        return [
            'sort_field' => ['nullable', Rule::in(CrmSavedViewApplicationService::SORT_FIELDS[$resource])],
            'sort_direction' => 'nullable|in:asc,desc',
            'group_by' => ['nullable', Rule::in(CrmSavedViewApplicationService::GROUP_FIELDS[$resource])],
        ];
    }

    public static function apply(Builder $query, array $filters): void
    {
        $group = $filters['group_by'] ?? '';
        $sort = $filters['sort_field'] ?? '';
        if (!$group && !$sort) return;
        $query->reorder();
        if ($group) self::order($query, $group, 'asc');
        if ($sort && $sort !== $group) self::order($query, $sort, $filters['sort_direction'] ?? 'asc');
        if ($sort !== 'id') $query->orderByDesc($query->qualifyColumn('id'));
    }

    private static function order(Builder $query, string $field, string $direction): void
    {
        if ($field === 'department_name') {
            $query->orderBy(DB::table('erp_departments')->select('name')
                ->whereColumn('legacy_id', $query->qualifyColumn('department_legacy_id'))->limit(1), $direction);
            return;
        }
        $query->orderBy($query->qualifyColumn($field), $direction);
    }
}
