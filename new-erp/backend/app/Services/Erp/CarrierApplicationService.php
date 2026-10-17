<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CarrierApplicationService
{
    private const TABLE = 'erp_sales_order_deliveries';
    private const IDENTITY_TYPE = 'sales_carrier_identity';

    public static function version(object $row): string
    {
        return hash('sha256', json_encode([
            (int) $row->legacy_id, (string) $row->name, (string) $row->code,
            (string) $row->trade_type, (int) $row->sort, (bool) $row->enabled,
            (string) $row->updated_at, (int) (self::metadata($row)['archive_version'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'carrier_option_id' => (int) $row->legacy_id,
            'name' => $row->name, 'code' => $row->code, 'sort' => (int) $row->sort,
            'status' => $row->enabled ? 'enabled' : 'disabled', 'enabled' => (bool) $row->enabled,
            'business_version' => self::version($row), 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    public function save(?int $id, array $data, object $operator): array
    {
        return DB::transaction(function () use ($id, $data, $operator): array {
            $this->lockIdentityCounter();
            $old = $id ? DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404, '承运商不存在。');
            if ($old && ! hash_equals(self::version($old), (string) $data['expected_version'])) {
                $this->fail('expected_version', '承运商已被其他人修改，请刷新后重新编辑。');
            }
            $values = [
                'name' => trim($data['name']), 'code' => trim((string) ($data['code'] ?? '')) ?: null,
                'sort' => (int) $data['sort'], 'enabled' => $data['status'] === 'enabled',
            ];
            if ($values['name'] === '') $this->fail('name', '请填写承运商名称。');
            $fingerprint = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $id) {
                $existing = DB::table(self::TABLE)->where('legacy_payload->base_archive_request_id', $data['client_request_id'])->first();
                if ($existing) {
                    if ((self::metadata($existing)['request_fingerprint'] ?? '') !== $fingerprint) {
                        $this->fail('client_request_id', '该保存请求已用于其他承运商内容，请刷新后重试。');
                    }
                    return self::present($existing);
                }
            }
            $duplicates = DB::table(self::TABLE)->when($id, fn ($query) => $query->where('id', '<>', $id));
            if ((clone $duplicates)->where('name', $values['name'])->exists()) $this->fail('name', '已存在同名承运商。');
            if ($values['code'] !== null && (clone $duplicates)->where('code', $values['code'])->exists()) {
                $this->fail('code', '承运商业务编码已被使用。');
            }
            if ($old) {
                // 历史贸易类型和来源元数据不由本维护入口解释或覆盖。
                $metadata = self::metadata($old);
                $metadata['archive_version'] = (int) ($metadata['archive_version'] ?? 0) + 1;
                DB::table(self::TABLE)->where('id', $id)->update([
                    ...$values, 'legacy_payload' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            } else {
                $counter = DB::table('erp_document_numbers')->where('document_type', self::IDENTITY_TYPE)->where('number_date', '1970-01-01');
                $identity = max((int) $counter->value('current_sequence'), (int) DB::table(self::TABLE)->max('legacy_id')) + 1;
                $counter->update(['current_sequence' => $identity, 'updated_at' => now()]);
                $id = DB::table(self::TABLE)->insertGetId([
                    ...$values, 'legacy_id' => $identity, 'trade_type' => null,
                    'legacy_payload' => json_encode(['source' => 'local_master', 'archive_version' => 1,
                        'base_archive_request_id' => $data['client_request_id'], 'request_fingerprint' => $fingerprint], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
            abort_unless($old, 404, '承运商不存在。');
            if (! hash_equals(self::version($old), $version)) $this->fail('expected_version', '承运商已被其他人修改，请刷新后重试。');
            $metadata = self::metadata($old);
            $metadata['archive_version'] = (int) ($metadata['archive_version'] ?? 0) + 1;
            DB::table(self::TABLE)->where('id', $id)->update([
                'enabled' => $status === 'enabled', 'legacy_payload' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $new = DB::table(self::TABLE)->where('id', $id)->first();
            $this->log($new->enabled ? 'enable' : 'disable', $old, $new, $operator);
            return self::present($new);
        }, 5);
    }

    private function lockIdentityCounter(): void
    {
        // 此行为正式创建分配本地身份的计数事实，沿用基础档案模式，不初始化业务编码规则。
        DB::table('erp_document_numbers')->insertOrIgnore([
            'document_type' => self::IDENTITY_TYPE, 'number_date' => '1970-01-01',
            'current_sequence' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_document_numbers')->where('document_type', self::IDENTITY_TYPE)->where('number_date', '1970-01-01')->lockForUpdate()->first();
    }

    private static function metadata(object $row): array
    {
        $metadata = json_decode($row->legacy_payload ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        return is_array($metadata) ? $metadata : [];
    }

    private function log(string $action, ?object $old, object $new, object $operator): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'sales_carrier', 'action' => $action, 'target_type' => self::TABLE, 'target_id' => $new->id,
            'old_snapshot' => $old ? json_encode(self::present($old), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'new_snapshot' => json_encode(self::present($new), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => '承运商基础档案维护', 'operator_id' => $operator->legacy_id ?? $operator->id,
            'operator_name' => $operator->nickname ?? $operator->username ?? null, 'created_at' => now(),
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
