<?php

namespace App\Services\Erp\Sales;

/**
 * 订单“生产与发货进度”页的合并时间线。
 *
 * 只把 SalesOrderProgressQueryService 已经查出的工序节点、记录和子工单整理成一条“最新在上”的事件流，
 * 不再访问数据库、不改任何业务事实，因此页面展示口径变化不会影响工单、发货、库存的真实数据。
 * 为什么不直接在 Vue 里拼：次数口径、退回去向、排序都属于业务口径，放在前端会让各页面各算各的。
 */
final class OrderProgressTimeline
{
    private const STAGE_RANK = ['sub' => 0, 'production' => 1, 'shipment' => 2];
    private const OPEN = ['pending', 'in_progress', 'rework'];

    /**
     * @param array $detail 含 production_nodes/shipment_nodes/production_records/shipment_records
     * @param array<int, array> $groups SalesOrderProgressQueryService::subGroups 的结果
     */
    public function attach(array $detail, array $groups): array
    {
        $nodes = [];
        foreach ($groups as $group) $nodes[] = ['stage' => 'sub', 'key' => 'sub:'.$group['work_order_id'], 'node' => $this->groupNode($group)];
        foreach ($detail['production_nodes'] as $node) $nodes[] = ['stage' => 'production', 'key' => $node['key'], 'node' => $node];
        foreach ($detail['shipment_nodes'] as $node) $nodes[] = ['stage' => 'shipment', 'key' => $node['key'], 'node' => $node];
        $position = [];
        $reworkByNode = [];
        foreach ($nodes as $index => $entry) {
            $position[$entry['key']] = $index;
            $reworkByNode[$entry['key']] = (int) ($entry['node']['rework_count'] ?? 0);
        }

        $events = [];
        $index = 0;
        foreach (['production' => $detail['production_records'], 'shipment' => $detail['shipment_records']] as $stage => $records) {
            foreach ($records as $record) {
                $event = $this->event($record, $stage, $reworkByNode[$record['node_key']] ?? 0);
                if ($event) $events[] = $event + ['_pos' => $position[$record['node_key']] ?? 0, '_idx' => $index++];
            }
        }
        foreach ($groups as $group) {
            $inner = $this->strip($this->sort($this->groupEvents($group)));
            $times = array_values(array_filter(array_column($inner, 'time')));
            $events[] = [
                'kind' => 'sub_group', 'key' => 'sub:'.$group['work_order_id'], 'stage' => 'sub', 'node_key' => 'sub:'.$group['work_order_id'],
                'work_order_id' => $group['work_order_id'], 'work_order_no' => $group['work_order_no'], 'name' => $group['name'], 'depth' => $group['depth'],
                'status' => $group['status'], 'summary' => $group['summary'], 'progress_label' => $group['progress_label'],
                'destination_label' => $group['destination_label'], 'fail_count' => count(array_filter($inner, fn ($e) => $e['status'] === 'failed')),
                'time' => $times ? max($times) : null, 'time_from' => $times ? min($times) : null, 'events' => $inner,
                '_pos' => $position['sub:'.$group['work_order_id']] ?? 0, '_idx' => $index++,
            ];
        }
        $events = $this->sort($events);

        return [
            'timeline' => $this->strip($events),
            'progress' => $this->progress($nodes, $detail, $events),
        ];
    }

    /** 同一时刻先比阶段（发货>生产>子工单），再比节点位置，最后比入库顺序，保证“后发生的在上”稳定。 */
    private function sort(array $events): array
    {
        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? ''))
                ?: (self::STAGE_RANK[$b['stage']] ?? 0) <=> (self::STAGE_RANK[$a['stage']] ?? 0)
                ?: ($b['_pos'] ?? 0) <=> ($a['_pos'] ?? 0)
                ?: ($b['_idx'] ?? 0) <=> ($a['_idx'] ?? 0);
        });
        return $events;
    }

    private function event(array $r, string $stage, int $nodeReworkCount): ?array
    {
        $key = (string) $r['key'];
        $open = in_array($r['status'], self::OPEN, true);
        $time = $this->ts($r['completed_at'] ?? null) ?: $this->ts($r['occurred_at'] ?? null);
        $isCurrent = (bool) preg_match('/(^current:|:current$)/', $key);
        // 尚未接单/开始的“当前任务”不是已发生的事，交给“当前进度”条，不进时间线。
        if ($isCurrent && $open) {
            $time = $this->ts($r['started_at'] ?? null) ?: $this->ts($r['claimed_at'] ?? null);
            if (! $time) return null;
        }
        $time ??= $this->ts($r['started_at'] ?? null) ?: $this->ts($r['claimed_at'] ?? null);

        $statusLabel = (string) ($r['status_label'] ?? '');
        $result = $statusLabel;
        $round = null;
        $attempt = $r['attempt'] ?? null;
        if (str_starts_with($key, 'serial-result:')) {
            $complete = str_ends_with($key, ':complete');
            $packingLabel = str_contains($statusLabel, '打包');
            if ($packingLabel) $result = $statusLabel;
            else $result = $complete ? '作业完成' : ($r['status'] === 'failed' ? '不合格' : '合格');
            if ($attempt && ! $packingLabel) $round = '第 '.$attempt.' 次';
        } elseif (str_starts_with($key, 'report:')) {
            if (preg_match('/^第 (\d+) 次合格$/u', $statusLabel, $m)) { $result = '返修合格'; $round = '返修第 '.$m[1].' 次'; }
            elseif ($statusLabel === '返工合格') { $result = '返修合格'; $round = '返修'; }
            elseif ($statusLabel === '已完成' && $nodeReworkCount > 0) $round = '首次';
        }
        $returnTo = $r['return_to'] ?? null;
        $description = $r['description'] ?? null;
        if ($returnTo && $description) $description = trim((string) preg_replace('/^退回[^；]*；?/u', '', $description)) ?: null;

        return [
            'kind' => 'event', 'key' => $key, 'stage' => $stage, 'node_key' => $r['node_key'], 'label' => $r['label'],
            'status' => $r['status'] === 'rework' ? 'in_progress' : $r['status'], 'result_label' => $result, 'round_label' => $round,
            'person' => $r['person'] ?? null, 'operator_name' => $r['operator_name'] ?? null, 'collaborators' => $r['collaborators'] ?? [],
            'claimed_at' => $this->ts($r['claimed_at'] ?? null), 'started_at' => $this->ts($r['started_at'] ?? null),
            'completed_at' => $this->ts($r['completed_at'] ?? null), 'time' => $time,
            'reason' => $r['reason'] ?? null, 'return_to' => $returnTo, 'description' => $description,
            'scope_label' => $r['scope_label'] ?? null, 'package_no' => $r['package_no'] ?? null,
            'unit_labels' => $r['unit_labels'] ?? [], 'quantity' => $r['quantity'] ?? null, 'media' => $r['media'] ?? [],
            'cutting_runs' => $r['cutting_runs'] ?? [], 'is_cancelled' => (bool) ($r['is_cancelled'] ?? false),
        ];
    }

    /** 子工单只展示它自己的工序记录；“上级工序领用”等交接事实本来就在其记录里。 */
    private function groupEvents(array $group): array
    {
        $reworkByNode = array_column($group['nodes'], 'rework_count', 'key');
        $events = [];
        foreach ($group['records'] as $index => $record) {
            $event = $this->event($record, 'sub', (int) ($reworkByNode[$record['node_key']] ?? 0));
            if ($event) $events[] = $event + ['_pos' => 0, '_idx' => $index];
        }
        return $events;
    }

    private function strip(array $events): array
    {
        return array_map(fn ($e) => array_diff_key($e, ['_pos' => 1, '_idx' => 1]), $events);
    }

    private function groupNode(array $group): array
    {
        return ['label' => $group['name'], 'status' => $group['status'], 'progress_label' => $group['progress_label'], 'rework_count' => 0,
            'status_label' => $group['summary']];
    }

    private function progress(array $nodes, array $detail, array $events): ?array
    {
        $open = array_values(array_filter($nodes, fn ($e) => in_array($e['node']['status'], ['pending', 'in_progress'], true)));
        if (! $nodes) return null;
        if (! $open) {
            $all = array_values(array_filter($nodes, fn ($e) => $e['node']['status'] !== 'cancelled'));
            if (! $all) return ['state' => 'cancelled', 'title' => '已取消', 'detail' => null, 'upcoming' => []];
            $last = $events[0]['time'] ?? null;
            $lastNode = end($all)['node'];
            return ['state' => 'completed', 'title' => '已全部完成', 'detail' => trim(($last ? substr($last, 5, 11).' ' : '').($lastNode['status_label'] ?? '')) ?: null, 'upcoming' => []];
        }
        $current = $open[0];
        $node = $current['node'];
        $currentRecord = null;
        foreach (['production_records', 'shipment_records'] as $field) {
            foreach ($detail[$field] as $record) if ($record['node_key'] === $current['key'] && preg_match('/(^current:|:current$)/', (string) $record['key']) && in_array($record['status'], self::OPEN, true)) { $currentRecord = $record; break 2; }
        }
        $label = $node['label'];
        $statusLabel = $currentRecord['status_label'] ?? ($node['status'] === 'in_progress' ? '进行中' : ($node['status_label'] ?? '待开始'));
        $upcoming = array_values(array_unique(array_map(fn ($e) => $e['node']['label'], array_slice($open, 1))));
        return ['state' => 'doing', 'title' => $label.' · '.$statusLabel, 'detail' => null, 'upcoming' => $upcoming, 'stage' => $current['stage'], 'node_key' => $current['key']];
    }

    private function ts(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        return substr(str_replace('T', ' ', (string) $value), 0, 19);
    }
}
