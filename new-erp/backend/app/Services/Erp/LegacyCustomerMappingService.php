<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 旧订单迁入只能使用已确认的客户合并映射，不能按姓名重新建档。 */
class LegacyCustomerMappingService
{
    /** @return array<int, int> 旧客户ID => 当前客户ID */
    public function resolveMany(array $legacyIds): array
    {
        $ids = [];
        foreach ($legacyIds as $id) {
            if (!ctype_digit((string) $id) || (int) $id < 1) {
                throw new RuntimeException('旧客户ID无效，订单迁入已停止。');
            }
            $ids[(int) $id] = (int) $id;
        }
        if (!$ids) return [];

        $rows = DB::table('erp_import_rows as r')
            ->join('erp_import_batches as b', 'b.id', '=', 'r.batch_id')
            ->where('b.import_type', 'Legacy Customers')->where('b.status', 'confirmed')
            ->where('r.source_table', 'jt_customer')->whereIn('r.source_id', array_values($ids))
            ->orderByDesc('b.id')->orderByDesc('r.id')
            ->get(['r.source_id', 'r.target_id', 'r.batch_id']);
        $map = [];
        $batches = [];
        foreach ($rows as $row) {
            $sourceId = (int) $row->source_id;
            if (array_key_exists($sourceId, $map)) {
                if ($batches[$sourceId] === (int) $row->batch_id) {
                    throw new RuntimeException('同一批次存在重复客户映射：'.$sourceId);
                }
                continue;
            }
            // 最新批次明确跳过的来源不能回退到更早的错误身份。
            $map[$sourceId] = $row->target_id ? (int) $row->target_id : null;
            $batches[$sourceId] = (int) $row->batch_id;
        }
        $existing = DB::table('erp_sales_customers')->whereIn('id', array_values(array_filter($map)))
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
        foreach ($ids as $id) {
            if (empty($map[$id]) || !isset($existing[$map[$id]])) {
                throw new RuntimeException('旧客户尚未建立有效合并映射，请先同步客户，禁止重新建档：'.$id);
            }
        }
        return $map;
    }
}
