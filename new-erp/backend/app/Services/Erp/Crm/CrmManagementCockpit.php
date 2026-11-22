<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmDailyReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

final class CrmManagementCockpit
{
    public const CONCLUSIONS = ['month_judgment', 'breakpoint', 'best_practice', 'manager_action', 'opportunities',
        'breakthrough', 'highest_amount', 'help_blockers', 'tomorrow_result'];
    public const COACHING = ['customer', 'fact', 'goal', 'seller_action', 'support', 'deadline', 'acceptance', 'result', 'status'];

    public static function calendar(string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $total = 0;
        $passed = 0;
        for ($cursor = $day->copy()->startOfMonth(); $cursor->month === $day->month; $cursor->addDay()) {
            if ($cursor->isSunday()) continue;
            $total++;
            if ($cursor <= $day) $passed++;
        }
        return ['total_days' => $total, 'passed_days' => $passed];
    }

    /** One narrow date-range scan. Each seller's latest nonblank target is counted once per currency. */
    public static function monthTargets(string $date, int $department, array $owners): array
    {
        $latest = [];
        $reports = CrmDailyReport::where(['kind' => 'sales', 'department_id' => $department])
            ->whereIn('scope_id', $owners)->whereBetween('report_date', [substr($date, 0, 7).'-01', $date])
            ->whereNotNull('confirmed_at')->orderBy('report_date')
            ->get(['scope_id', 'body->currency as currency', 'body->base->month_close_target as orders', 'body->base->month_ship_target as shipments']);
        foreach ($reports as $report) {
            foreach (['orders', 'shipments'] as $key) {
                if (is_numeric($report->$key)) $latest[$report->currency ?: 'CNY'][$key][$report->scope_id] = (float) $report->$key;
            }
        }
        $result = [];
        foreach ($latest as $currency => $fields) {
            foreach ($fields as $key => $values) $result[$currency][$key] = round(array_sum($values), 2);
        }
        return $result;
    }

    public static function validate(array $input): array
    {
        $money = 'nullable|numeric|min:0|max:999999999999';
        $rules = [
            'settings' => 'sometimes|array:total_days,passed_days,due_people,month_target,month_ship_target,today_target',
            'settings.total_days' => 'nullable|integer|min:1|max:31',
            'settings.passed_days' => 'nullable|integer|min:0|max:31',
            'settings.due_people' => 'nullable|integer|min:0|max:10000',
            'conclusions' => 'sometimes|array:'.implode(',', self::CONCLUSIONS),
            'conclusions.*' => 'nullable|string|max:1500',
            'personnel' => 'sometimes|array|max:1000',
            'personnel.*' => 'array:sales_id,today_target,key_count,high_count,reason,risk',
            'personnel.*.sales_id' => 'required|integer|min:1|distinct',
            'personnel.*.today_target' => $money,
            'personnel.*.key_count' => 'nullable|integer|min:0|max:9999',
            'personnel.*.high_count' => 'nullable|integer|min:0|max:9999',
            'personnel.*.reason' => 'nullable|string|max:1000',
            'personnel.*.risk' => 'nullable|in:低,中,高',
            'coaching' => 'sometimes|array|max:3',
            'coaching.*' => 'array:priority,sales_id,expected_amount,'.implode(',', self::COACHING),
            'coaching.*.priority' => 'required|in:P1,P2,P3|distinct',
            'coaching.*.sales_id' => 'nullable|integer|min:1',
            'coaching.*.expected_amount' => $money,
        ];
        foreach (['month_target', 'month_ship_target', 'today_target'] as $key) $rules['settings.'.$key] = $money;
        foreach (self::COACHING as $key) $rules['coaching.*.'.$key] = 'nullable|string|max:500';
        $data = Validator::make($input, $rules)->validate();
        foreach (['personnel', 'coaching'] as $section) {
            foreach ($data[$section] ?? [] as $i => $row) {
                if (isset($row['sales_id'])) $data[$section][$i]['sales_id'] = (int) $row['sales_id'];
            }
        }
        return $data;
    }

    // On a restricted read use empty source; on a write retain the values from the locked report.
    public static function protectAmounts(array $data, array $source = []): array
    {
        foreach (['month_target', 'month_ship_target', 'today_target'] as $key) {
            $data['settings'][$key] = $source['settings'][$key] ?? null;
        }
        $data['conclusions']['highest_amount'] = $source['conclusions']['highest_amount'] ?? null;
        foreach (['personnel' => ['sales_id', 'today_target'], 'coaching' => ['priority', 'expected_amount']] as $section => [$id, $field]) {
            $stored = collect($source[$section] ?? [])->keyBy($id);
            foreach ($data[$section] ?? [] as $i => $row) $data[$section][$i][$field] = $stored->get($row[$id])[$field] ?? null;
        }
        return $data;
    }

    public static function aiSuggestions(array $raw, array $owners): array
    {
        $conclusions = [];
        foreach (self::CONCLUSIONS as $key) {
            if (is_string($raw['conclusions'][$key] ?? null)) $conclusions[$key] = mb_substr(trim($raw['conclusions'][$key]), 0, 1500);
        }
        $coaching = [];
        $customers = [];
        foreach ((array) ($raw['coaching'] ?? []) as $row) {
            if (!is_array($row) || !in_array($row['priority'] ?? '', ['P1','P2','P3'], true)) continue;
            $id = (int) ($row['sales_id'] ?? 0);
            if (!in_array($id, $owners, true)) continue;
            if (isset($coaching[$row['priority']])) continue;
            $customer = is_string($row['customer'] ?? null) ? trim($row['customer']) : '';
            if ($customer !== '' && isset($customers[$customer])) continue;
            if ($customer !== '') $customers[$customer] = true;
            $entry = ['priority' => $row['priority'], 'sales_id' => $id];
            foreach (['customer', 'fact', 'goal', 'seller_action', 'support', 'deadline', 'acceptance'] as $key) {
                if (is_string($row[$key] ?? null)) $entry[$key] = mb_substr(trim($row[$key]), 0, 500);
            }
            $coaching[$row['priority']] = $entry;
            if (count($coaching) === 3) break;
        }
        return ['conclusions' => $conclusions, 'coaching' => array_values($coaching)];
    }
}
