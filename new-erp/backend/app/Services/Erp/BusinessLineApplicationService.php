<?php

namespace App\Services\Erp;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BusinessLineApplicationService
{
    private const TABLE = 'erp_business_lines';

    public static function version(object $row): string
    {
        return hash('sha256', json_encode([
            (int) $row->id, $row->code, $row->name, (int) $row->sort_order, $row->status,
            $row->remark, $row->updated_at,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name,
            'sort_order' => (int) $row->sort_order, 'sort' => (int) $row->sort_order,
            'status' => $row->status, 'enabled' => $row->status === 'enabled', 'remark' => $row->remark,
            'business_version' => self::version($row), 'created_by' => $row->created_by, 'updated_by' => $row->updated_by,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    public function save(?int $id, array $data, object $operator): array
    {
        $requestedCode = trim((string) ($data['code'] ?? ''));
        $requestId = $id === null ? ($data['client_request_id'] ?? null) : null;
        $fingerprint = hash('sha256', json_encode([
            'code' => $requestedCode, 'name' => trim($data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? $data['sort'] ?? 0),
            'status' => $data['status'] ?? 'enabled', 'remark' => $data['remark'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($id, $data, $operator, $requestedCode, $requestId, $fingerprint): array {
                $old = $id !== null ? DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first() : null;
                abort_if($id !== null && ! $old, 404, '业务线不存在。');
                if ($old) $this->checkVersion($old, (string) ($data['expected_version'] ?? ''));
                if ($requestId !== null) {
                    $existing = DB::table(self::TABLE)->where('create_request_id', $requestId)->first();
                    if ($existing) return $this->replay($existing, $fingerprint);
                }
                $automaticCode = $old === null && $requestedCode === '';
                $values = [
                    'name' => trim($data['name']),
                    'code' => $requestedCode !== '' ? $requestedCode : ($old->code ?? $this->nextCode()),
                    'sort_order' => (int) ($data['sort_order'] ?? $data['sort'] ?? $old->sort_order ?? 0),
                    'status' => $data['status'] ?? $old->status ?? 'enabled',
                    'remark' => array_key_exists('remark', $data) ? $data['remark'] : ($old->remark ?? null),
                    'updated_by' => $operator->legacy_id ?? $operator->id, 'updated_at' => now()->format('Y-m-d H:i:s.u'),
                ];
                if ($values['name'] === '') $this->fail('name', '请填写业务线名称。');
                foreach (['code' => '业务线编码已被使用。', 'name' => '已存在同名业务线。'] as $field => $message) {
                    if (DB::table(self::TABLE)->where($field, $values[$field])->when($id, fn ($query) => $query->where('id', '<>', $id))->exists()) {
                        $this->fail($field, $message);
                    }
                }
                if ($old) {
                    DB::table(self::TABLE)->where('id', $id)->update($values);
                } else {
                    do {
                        try {
                            $id = DB::table(self::TABLE)->insertGetId([
                                ...$values, 'created_by' => $operator->legacy_id ?? $operator->id, 'created_at' => $values['updated_at'],
                                'create_request_id' => $requestId, 'create_fingerprint' => $requestId === null ? null : $fingerprint,
                            ]);
                            break;
                        } catch (QueryException $error) {
                            // 人工编码可能恰好抢先占用自动号；在同一事务内续号，避免回滚后反复分配同一个号码。
                            if (! $automaticCode || (int) ($error->errorInfo[1] ?? 0) !== 1062
                                || ! str_contains($error->getMessage(), 'erp_business_lines_code_unique')) throw $error;
                            $values['code'] = $this->nextCode();
                        }
                    } while (true);
                }
                $new = DB::table(self::TABLE)->where('id', $id)->first();
                $this->log($old ? 'update' : 'create', $old, $new, $operator);
                return self::present($new);
            }, 5);
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            if ($requestId !== null) {
                $existing = DB::table(self::TABLE)->useWritePdo()->where('create_request_id', $requestId)->first();
                if ($existing) return $this->replay($existing, $fingerprint);
            }
            if (str_contains($error->getMessage(), 'erp_business_lines_code_unique')) $this->fail('code', '业务线编码已被使用。');
            if (str_contains($error->getMessage(), 'erp_business_lines_name_unique')) $this->fail('name', '已存在同名业务线。');
            throw $error;
        }
    }

    public function setStatus(int $id, string $status, string $version, object $operator): array
    {
        return DB::transaction(function () use ($id, $status, $version, $operator): array {
            $old = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404, '业务线不存在。');
            $this->checkVersion($old, $version);
            if ($old->status === $status) return self::present($old);
            DB::table(self::TABLE)->where('id', $id)->update([
                'status' => $status, 'updated_by' => $operator->legacy_id ?? $operator->id,
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
            $new = DB::table(self::TABLE)->where('id', $id)->first();
            $this->log($status === 'enabled' ? 'enable' : 'disable', $old, $new, $operator);
            return self::present($new);
        }, 5);
    }

    private function nextCode(): string
    {
        do {
            $code = app(DocumentNumberService::class)->next('business_line', 'BL');
        } while (DB::table(self::TABLE)->where('code', $code)->lockForUpdate()->exists());
        return $code;
    }

    private function replay(object $row, string $fingerprint): array
    {
        if (! hash_equals((string) $row->create_fingerprint, $fingerprint)) {
            $this->fail('client_request_id', '该保存请求已用于其他业务线内容，请刷新后重试。');
        }
        return self::present($row);
    }

    private function checkVersion(object $row, string $version): void
    {
        if (! hash_equals(self::version($row), $version)) {
            $this->fail('expected_version', '业务线已被其他人修改，请刷新后重新编辑。');
        }
    }

    private function log(string $action, ?object $old, object $new, object $operator): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'business_line', 'action' => $action, 'target_type' => self::TABLE, 'target_id' => $new->id,
            'old_snapshot' => $old ? json_encode(self::present($old), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'new_snapshot' => json_encode(self::present($new), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => '业务线基础档案维护', 'operator_id' => $operator->legacy_id ?? $operator->id,
            'operator_name' => $operator->nickname ?? $operator->username ?? null, 'created_at' => now(),
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
