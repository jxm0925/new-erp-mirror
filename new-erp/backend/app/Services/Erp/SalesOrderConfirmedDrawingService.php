<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\Crm\CrmDrawingRequestService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/** 客户确认事实固定在订单行；后续交图、来源转交或默认版本变化都不重取。 */
final class SalesOrderConfirmedDrawingService
{
    public const FIELD = 'confirmed_drawing_revision_ids';
    private const PUBLIC_FIELDS = [
        'request_revision_id', 'request_id', 'request_no', 'title', 'subject', 'customer_id',
        'lead_id', 'opportunity_id', 'source_type', 'source_id', 'source_no',
        'revision_id', 'usage_id', 'drawing_id', 'drawing_no', 'drawing_name', 'version_no',
        'designer_name', 'completed_date', 'confirmed_at', 'confirmed_by_legacy_id',
        'confirmed_by_name', 'confirmation_note', 'confirmation_remark',
        'original_name', 'mime_type', 'file_size', 'files', 'file_count',
    ];

    public function ids(array $line): array
    {
        $values = array_key_exists(self::FIELD, $line) ? $line[self::FIELD]
            : array_column((array) data_get($line, 'drawing_snapshot.confirmed_versions', []), 'request_revision_id');
        Validator::make(['ids' => $values], ['ids' => 'array|max:1000', 'ids.*' => 'required|integer|min:1|distinct'])->validate();
        $ids = array_map('intval', $values);
        sort($ids);
        return $ids;
    }

    public function prepare(SalesOrder $order, array $lines, ?object $actor, ?int $customerId = null): array
    {
        $customerId ??= (int) $order->customer_id;
        $selections = [];
        $needsSnapshots = false;
        foreach (array_values($lines) as $index => $line) {
            if (array_key_exists(self::FIELD, $line)) {
                $selections[$index] = $this->ids($line);
                $needsSnapshots = $needsSnapshots || $selections[$index] !== [];
            } else $needsSnapshots = $needsSnapshots || !empty($line['id']);
        }
        // 普通空选版不读全单；有引用时只读一次，同时供历史版本复用和逐行客户校验。
        if (!$needsSnapshots) return $selections;
        $existing = $order->exists ? $order->lines()->get(['id', 'drawing_snapshot'])->keyBy('id') : collect();
        $known = [];
        foreach ($existing as $stored) {
            foreach ((array) data_get($stored->drawing_snapshot, 'confirmed_versions', []) as $version) {
                if ((int) ($version['customer_id'] ?? 0) === $customerId) $known[(int) $version['request_revision_id']] = $version;
            }
        }
        $result = [];
        foreach (array_values($lines) as $index => $line) {
            if (!array_key_exists(self::FIELD, $line)) {
                foreach ((array) data_get($existing->get((int) ($line['id'] ?? 0))?->drawing_snapshot, 'confirmed_versions', []) as $version) {
                    abort_unless((int) ($version['customer_id'] ?? 0) === $customerId, 422, '订单已更换客户，请移除原客户确认图纸后重新选择。');
                }
                continue;
            }
            $versions = [];
            foreach ($selections[$index] as $id) {
                if (isset($known[$id])) $versions[] = $known[$id];
                else {
                    abort_unless($actor, 401, '请先登录后选择客户确认图纸。');
                    $opportunityId = $order->crmOpportunityOrder?->opportunity_id;
                    $version = app(CrmDrawingRequestService::class)->confirmedForOrder($id, $actor, $customerId, $opportunityId ? (int) $opportunityId : null, true);
                    abort_unless((int) ($version['revision_id'] ?? 0) > 0 && !empty($version['confirmed_at']), 422, '所选图纸还没有明确的客户确认版本。');
                    $version['files'] = array_map(fn ($file) => Arr::only($file, ['id', 'sort', 'file_type', 'file_type_label', 'original_name', 'mime_type', 'file_size']), $version['files']);
                    $versions[] = Arr::only(array_replace($version, ['request_revision_id' => $id, 'customer_id' => $customerId]), self::PUBLIC_FIELDS);
                }
            }
            $result[$index] = $versions;
        }
        return $result;
    }

    public function snapshot(SalesOrder $order, array $line, ?SalesOrderLine $existing, ?object $actor, ?array $prepared = null, int $index = 0): ?array
    {
        $base = array_key_exists('drawing_snapshot', $line) ? $line['drawing_snapshot'] : $existing?->drawing_snapshot;
        $base = is_array($base) ? $base : null;
        if (is_array($base)) unset($base['confirmed_versions']);
        $versions = (array) data_get($existing?->drawing_snapshot, 'confirmed_versions', []);
        if (array_key_exists(self::FIELD, $line)) {
            $versions = $prepared !== null && array_key_exists($index, $prepared)
                ? $prepared[$index] : $this->prepare($order, [$line], $actor)[0];
            abort_unless(array_column($versions, 'request_revision_id') === $this->ids($line), 422, '客户确认图纸选择已变化，请重新预览后保存。');
        }
        foreach ($versions as $version) {
            abort_unless((int) ($version['customer_id'] ?? 0) === (int) $order->customer_id, 422, '订单已更换客户，请移除原客户确认图纸后重新选择。');
        }
        if ($versions) {
            $base ??= [];
            $base['confirmed_versions'] = $versions;
        }
        return $base;
    }

    public function assertCustomer(SalesOrder $order, array $lines, int $customerId, array $deletedLineIds = []): void
    {
        if ((int) $order->customer_id === $customerId) return;
        $incoming = collect($lines)->filter(fn ($line) => !empty($line['id']))->keyBy('id');
        foreach ($order->lines()->whereNotIn('id', $deletedLineIds)->get(['id', 'drawing_snapshot']) as $existing) {
            if (!(array) data_get($existing->drawing_snapshot, 'confirmed_versions', [])) continue;
            $line = $incoming->get($existing->id);
            abort_unless($line && array_key_exists(self::FIELD, $line), 422, '订单已更换客户，请移除原客户确认图纸后重新选择。');
        }
    }

    /** 商业/信息字段保存不会重新同步生产行，客户确认引用仍须独立落到对应行。 */
    public function sync(SalesOrder $order, array $lines, ?object $actor, ?array $prepared = null): void
    {
        $prepared ??= $this->prepare($order, $lines, $actor);
        foreach (array_values($lines) as $index => $line) {
            if (!array_key_exists(self::FIELD, $line)) continue;
            $query = $order->lines()->lockForUpdate();
            $saved = !empty($line['id']) ? $query->findOrFail($line['id'])
                : (!empty($line['line_uuid']) ? $query->where('line_uuid', $line['line_uuid'])->firstOrFail() : $query->where('line_no', $index + 1)->firstOrFail());
            $snapshot = $this->snapshot($order, $line, $saved, $actor, $prepared, $index);
            if ($snapshot !== $saved->drawing_snapshot) $saved->update(['drawing_snapshot' => $snapshot]);
        }
    }

}
