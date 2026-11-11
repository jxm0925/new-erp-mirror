<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TradePlatformApplicationService
{
    private const TABLE = 'erp_sales_order_trade_platforms';

    public static function version(object $row): string
    {
        return hash('sha256', json_encode([
            (int) $row->legacy_id, (int) $row->parent_legacy_id, (string) $row->name,
            (string) $row->short_name, (string) $row->trade_type, (int) $row->sort,
            (bool) $row->enabled, (string) $row->updated_at,
            (int) ((json_decode($row->legacy_payload ?? '{}', true) ?: [])['archive_version'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function present(object $row): array
    {
        $data = (array) $row;
        unset($data['legacy_payload'], $data['parent_metadata']);
        $data['enabled'] = (bool) $row->enabled;
        $data['payment_mode'] = self::paymentMode($row->legacy_payload);
        $data['effective_payment_mode'] = $data['payment_mode'] ?? self::paymentMode($row->parent_metadata ?? null);
        $data += self::buyerIdentity($row->legacy_payload);
        $data['business_version'] = self::version($row);
        return $data;
    }

    public static function paymentMode(?string $metadata): ?string
    {
        $mode = (json_decode($metadata ?? '{}', true) ?: [])['payment_mode'] ?? null;
        return in_array($mode, ['online', 'offline'], true) ? $mode : null;
    }

    /**
     * 买家ID识别方式只设在主平台：每个主平台档案就是一家店铺（如“【京东】状元雨专卖店”）。
     * platform＝同一电商平台各店买家ID相同，按“所属电商平台＋买家ID”认人；shop＝各店不同，按“本店＋买家ID”认人。
     * 未设置时 CRM 不生成正式平台身份，避免把来源不明的买家ID当成唯一身份。
     */
    public static function buyerIdentity(?string $metadata): array
    {
        $data = json_decode($metadata ?? '{}', true) ?: [];
        $scope = in_array($data['buyer_identity_scope'] ?? null, ['platform', 'shop'], true) ? $data['buyer_identity_scope'] : null;
        $group = trim((string) ($data['buyer_platform_group'] ?? '')) ?: null;
        return ['buyer_identity_scope' => $scope, 'buyer_platform_group' => $group];
    }

    public function save(?int $id, array $data, object $operator): array
    {
        return DB::transaction(function () use ($id, $data, $operator): array {
            // 销售订单用 legacy_id 作为平台选项身份。沿用该列但只分配本地新编号，
            // 不改历史ID、不连接旧库。独立计数器行同时串行化父子状态写入，避免停用竞态。
            $this->lockIdentityCounter();
            $old = $id ? DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && !$old, 404, '成交平台不存在。');
            if ($old) {
                if (!hash_equals(self::version($old), (string) $data['expected_version'])) {
                    $this->fail('expected_version', '成交平台已被其他人修改，请刷新后重新编辑。');
                }
                // 已有订单保留主/子平台组合；编辑名称、排序和状态不能迁移原有层级。
                if ((int) $data['parent_legacy_id'] !== (int) $old->parent_legacy_id) {
                    $this->fail('parent_legacy_id', '平台创建后不能变更上级，请新增平台并停用原平台。');
                }
            }
            $values = [
                'name' => trim($data['name']), 'short_name' => trim((string) ($data['short_name'] ?? '')) ?: null,
                'trade_type' => trim((string) ($data['trade_type'] ?? '')) ?: null,
                'parent_legacy_id' => (int) $data['parent_legacy_id'], 'sort' => (int) $data['sort'],
                'enabled' => $data['status'] === 'enabled',
            ];
            // 交易类型只有国内（domestic）/国外（foreign）；子平台跟随主平台，订单按此筛选主平台。
            if ($values['parent_legacy_id']) {
                $values['trade_type'] = DB::table(self::TABLE)->where('legacy_id', $values['parent_legacy_id'])->value('trade_type') ?: 'domestic';
            } elseif (!in_array($values['trade_type'], ['domestic', 'foreign'], true)) {
                $this->fail('trade_type', '请选择交易类型：国内交易或国外交易。');
            }
            $metadata = array_intersect_key($data, ['payment_mode' => true, 'buyer_identity_scope' => true, 'buyer_platform_group' => true]);
            if ($values['parent_legacy_id']) {
                // 子平台是主平台下的线索来源，不是店铺，不设置买家ID识别方式。
                unset($metadata['buyer_identity_scope'], $metadata['buyer_platform_group']);
            } elseif (($metadata['buyer_identity_scope'] ?? null) === 'platform' && trim((string) ($metadata['buyer_platform_group'] ?? '')) === '') {
                $this->fail('buyer_platform_group', '各店买家ID相同时，请填写所属电商平台（如：京东），同一电商平台的店铺填写相同名称。');
            }
            if (array_key_exists('buyer_platform_group', $metadata)) {
                $metadata['buyer_platform_group'] = trim((string) $metadata['buyer_platform_group']) ?: null;
            }
            if ($old && !$values['parent_legacy_id']) {
                $beforeIdentity = self::buyerIdentity($old->legacy_payload);
                $afterIdentity = self::buyerIdentity($this->nextMetadata($old, $metadata));
                $beforeGroup = $beforeIdentity['buyer_identity_scope'] === 'platform' ? mb_strtolower((string) $beforeIdentity['buyer_platform_group']) : null;
                $afterGroup = $afterIdentity['buyer_identity_scope'] === 'platform' ? mb_strtolower((string) $afterIdentity['buyer_platform_group']) : null;
                if ($beforeIdentity['buyer_identity_scope'] !== $afterIdentity['buyer_identity_scope'] || $beforeGroup !== $afterGroup) {
                    // shortcut: 已有身份暂不转换作用域，提供带冲突核实的身份转换流程后再开放。
                    $used = DB::table('erp_crm_customer_identities')->where('status', 'confirmed')
                        ->where(function ($query) use ($old, $beforeGroup): void {
                            $query->where('platform_legacy_id', $old->legacy_id);
                            if ($beforeGroup !== null) $query->orWhere(fn ($q) => $q->where('scope', 'platform')->whereRaw('LOWER(platform_group) = ?', [$beforeGroup]));
                        })->lockForUpdate()->first(['id']);
                    if ($used) $this->fail('buyer_identity_scope', '该平台已有正式客户身份，不能直接更改买家ID识别方式或所属电商平台，请先核实已有身份。');
                }
            }
            $fingerprint = hash('sha256', json_encode($values + array_filter($metadata), JSON_UNESCAPED_UNICODE));
            if (!$id) {
                $existing = DB::table(self::TABLE)->where('legacy_payload->base_archive_request_id', $data['client_request_id'])->first();
                if ($existing) {
                    $source = json_decode($existing->legacy_payload, true);
                    if (($source['request_fingerprint'] ?? '') !== $fingerprint) $this->fail('client_request_id', '该保存请求已成功处理，请刷新列表后再编辑。');
                    return self::present($existing);
                }
            }
            $this->assertHierarchy($old, $values);
            $duplicate = DB::table(self::TABLE)->where('parent_legacy_id', $values['parent_legacy_id'])->where('name', $values['name']);
            if ($id) $duplicate->where('id', '<>', $id);
            if ($duplicate->exists()) $this->fail('name', '同一上级下已存在同名成交平台。');
            if ($old) {
                DB::table(self::TABLE)->where('id', $id)->update([...$values, 'legacy_payload' => $this->nextMetadata($old, $metadata), 'updated_at' => now()]);
                if (!$values['parent_legacy_id'] && $values['trade_type'] !== $old->trade_type) {
                    DB::table(self::TABLE)->where('parent_legacy_id', $old->legacy_id)->update(['trade_type' => $values['trade_type'], 'updated_at' => now()]);
                }
            } else {
                $counter = DB::table('erp_document_numbers')->where('document_type', 'trade_platform_identity')->where('number_date', '1970-01-01');
                $identity = max((int) $counter->value('current_sequence'), (int) DB::table(self::TABLE)->max('legacy_id')) + 1;
                $counter->update(['current_sequence' => $identity, 'updated_at' => now()]);
                $id = DB::table(self::TABLE)->insertGetId([
                    ...$values, 'legacy_id' => $identity,
                    'legacy_payload' => json_encode($metadata + ['source' => 'local_master', 'archive_version' => 1, 'base_archive_request_id' => $data['client_request_id'], 'request_fingerprint' => $fingerprint]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $new = DB::table(self::TABLE)->where('id', $id)->first();
            $this->log($old ? 'update' : 'create', $old, $new, $operator);
            return self::present($new);
        }, 5);
    }

    public function setStatus(int $id, string $status, string $version, object $operator): array
    {
        return DB::transaction(function () use ($id, $status, $version, $operator): array {
            $this->lockIdentityCounter();
            $old = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404, '成交平台不存在。');
            if (!hash_equals(self::version($old), $version)) $this->fail('expected_version', '成交平台已被其他人修改，请刷新后重试。');
            $values = ['parent_legacy_id' => (int) $old->parent_legacy_id, 'enabled' => $status === 'enabled'];
            $this->assertHierarchy($old, $values);
            DB::table(self::TABLE)->where('id', $id)->update(['enabled' => $values['enabled'], 'legacy_payload' => $this->nextMetadata($old), 'updated_at' => now()]);
            $new = DB::table(self::TABLE)->where('id', $id)->first();
            $this->log($values['enabled'] ? 'enable' : 'disable', $old, $new, $operator);
            return self::present($new);
        }, 5);
    }

    private function nextMetadata(object $row, array $updates = []): string
    {
        $metadata = json_decode($row->legacy_payload ?? '{}', true) ?: [];
        $metadata['archive_version'] = (int) ($metadata['archive_version'] ?? 0) + 1;
        return json_encode(array_replace($metadata, $updates), JSON_UNESCAPED_UNICODE);
    }

    /** 平台设置及客户身份写入先取此锁，再锁客户与身份，避免规则变更和绑定并发。 */
    public function lockIdentityCounter(): void
    {
        DB::table('erp_document_numbers')->insertOrIgnore([
            'document_type' => 'trade_platform_identity', 'number_date' => '1970-01-01',
            'current_sequence' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_document_numbers')->where('document_type', 'trade_platform_identity')->where('number_date', '1970-01-01')->lockForUpdate()->first();
    }

    private function assertHierarchy(?object $old, array $values): void
    {
        if ($values['parent_legacy_id']) {
            $parent = DB::table(self::TABLE)->where('legacy_id', $values['parent_legacy_id'])->first();
            if (!$parent || (int) $parent->parent_legacy_id !== 0) $this->fail('parent_legacy_id', '上级必须是有效主平台，仅支持主平台和子平台两级。');
            if ($values['enabled'] && !$parent->enabled) $this->fail('parent_legacy_id', '请先启用上级主平台。');
        }
        if ($old && !$values['enabled'] && DB::table(self::TABLE)->where('parent_legacy_id', $old->legacy_id)->where('enabled', true)->exists()) {
            $this->fail('status', '请先停用该主平台下的子平台。');
        }
    }

    private function log(string $action, ?object $old, object $new, object $operator): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'trade_platform', 'action' => $action, 'target_type' => self::TABLE, 'target_id' => $new->id,
            'old_snapshot' => $old ? json_encode(self::present($old), JSON_UNESCAPED_UNICODE) : null,
            'new_snapshot' => json_encode(self::present($new), JSON_UNESCAPED_UNICODE),
            'reason' => '基础档案维护', 'operator_id' => $operator->legacy_id,
            'operator_name' => $operator->nickname ?? $operator->username ?? null, 'created_at' => now(),
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
