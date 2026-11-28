<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\BusinessLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CrmBusinessLineOptions
{
    /** 调用者先限定可见范围；这里只读取必要列，不扩大客户、线索或商机权限。 */
    public static function forVisibleRecords(?Builder $customers = null, ?Builder $leads = null, ?Builder $opportunities = null): array
    {
        $history = [];
        foreach ([[$customers, 'business_lines'], [$leads, 'customer_business_lines']] as [$query, $field]) {
            if (!$query) continue;
            $column = $query->qualifyColumn($field);
            // MySQL 8.0.12 的 JSON_TABLE 不安全处理 JSON null；整列去重后解数组也避免截断历史长名称。
            $arrays = (clone $query)->reorder()->select(DB::raw("CAST($column AS BINARY) AS line_values"))
                ->whereNotNull($column)->distinct()->toBase()->pluck('line_values');
            foreach ($arrays as $raw) {
                $names = json_decode($raw);
                if (is_array($names)) self::append($history, $names);
            }
        }
        if ($opportunities) {
            $column = $opportunities->qualifyColumn('business_line');
            self::append($history, (clone $opportunities)->reorder()->select(DB::raw("CAST($column AS BINARY) AS line_name"))
                ->whereNotNull($column)->distinct()->toBase()->pluck('line_name')->all());
        }
        sort($history, SORT_STRING);
        $options = [];
        self::append($options, BusinessLine::enabledNames()->all());
        self::append($options, $history);
        return array_values($options);
    }

    public static function normalize(array $names): array
    {
        $options = [];
        self::append($options, $names);
        return array_values($options);
    }

    private static function append(array &$options, array $names): void
    {
        foreach ($names as $name) {
            if (!is_string($name)) continue;
            $name = trim($name);
            if ($name !== '') $options[$name] = $name;
        }
    }
}
