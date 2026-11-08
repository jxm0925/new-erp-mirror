<?php

namespace App\Services\Erp\Sales;

use App\Models\Erp\SalesOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** 只读迁移档案；不把旧工单或填报工时伪装成新系统执行任务。 */
final class LegacyOrderHistoryQueryService
{
    private function rows(SalesOrder $order, string $table): Builder
    {
        return DB::table('erp_import_rows as r')->join('erp_import_batches as b', 'b.id', '=', 'r.batch_id')
            ->where('b.import_type', 'Legacy Orders')->where('b.status', 'confirmed')
            ->where('r.source_table', $table)->where('r.normalized_data->sales_order_id', (int) $order->id);
    }

    public function history(SalesOrder $order, array $filters): array
    {
        abort_unless($order->isLegacyHistory(), 404, '该订单没有旧系统历史档案。');
        $workOrders = $this->rows($order, 'jt_works_order')->orderBy('r.id')->pluck('r.normalized_data')
            ->map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))
            ->map(fn ($row) => [
                'legacy_work_order_id' => (int) $row['legacy_work_order_id'],
                'work_order_no' => $row['work_order_no'], 'status_name' => $row['status_name'],
                'labor_hours' => (string) $row['labor_hours'],
                'products' => array_map(fn ($product) => [
                    'product_name' => $product['product_name'] ?? null, 'sku_name' => $product['sku_name'] ?? null,
                    'quantity' => $product['quantity'], 'sales_order_line_id' => $product['sales_order_line_id'] ?? null,
                ], $row['products']),
            ])->values();
        $logs = $this->rows($order, 'jt_works_order_logs');
        // DECIMAL 汇总原始小时，不能将个人分配或旧 worktime 舍入值再加一次。
        $total = (clone $logs)->selectRaw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.normalized_data, '$.hours')) AS DECIMAL(18,4))),0) AS hours")->first();
        if (!empty($filters['legacy_work_order_id'])) {
            $logs->where('r.normalized_data->legacy_work_order_id', (int) $filters['legacy_work_order_id']);
        }
        $numbers = $workOrders->pluck('work_order_no', 'legacy_work_order_id');
        $page = $logs->orderByDesc('r.normalized_data->reported_at')->orderByDesc('r.id')
            ->paginate($filters['per_page'] ?? 10, ['r.normalized_data'], 'page', $filters['page'] ?? 1)
            ->through(function ($row) use ($numbers) {
                $data = json_decode($row->normalized_data, true, 512, JSON_THROW_ON_ERROR);
                return [
                    'legacy_log_id' => (int) $data['legacy_log_id'],
                    'legacy_work_order_id' => (int) $data['legacy_work_order_id'],
                    'work_order_no' => $numbers->get($data['legacy_work_order_id']),
                    'content' => $data['content'], 'hours' => (string) $data['hours'],
                    'allocation_difference_hours' => (string) ($data['allocation_difference_hours'] ?? '0'),
                    'user_names' => $data['user_names'], 'reported_at' => $data['reported_at'],
                    'allocations' => array_map(fn ($allocation) => [
                        'user_name' => $allocation['user_name'], 'hours' => (string) $allocation['hours'],
                    ], $data['allocations'] ?? []),
                    'attachments' => self::safeLinks($data['attachments'] ?? []),
                ];
            });
        return ['work_orders' => $workOrders, 'labor_hours' => (string) $total->hours, 'logs' => $page->toArray()];
    }

    /** 原始附件地址只允许 HTTP(S) 或站内绝对路径，拒绝脚本协议与网络路径。 */
    public static function safeLinks(array $links): array
    {
        $result = [];
        foreach ($links as $link) {
            $url = trim(is_array($link) ? (string) ($link['url'] ?? '') : (string) $link);
            if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) continue;
            $local = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $remote = filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
            if ($local || $remote) $result[] = ['url' => $url, 'name' => is_array($link) ? ($link['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: $url)) : basename(parse_url($url, PHP_URL_PATH) ?: $url)];
        }
        return $result;
    }
}
