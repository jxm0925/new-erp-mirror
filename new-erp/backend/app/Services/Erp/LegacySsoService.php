<?php

namespace App\Services\Erp;

use App\Exceptions\ErpSsoException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LegacySsoService
{
    private const ACTIVE_STATUSES = ['normal', 'active'];

    public function __construct(private readonly RbacUserRoleOwnershipService $roleOwnership)
    {
    }

    /** Consume a signed legacy ERP ticket and project its identity locally. */
    public function consume(string $ticket, ?string $requestIp = null): int
    {
        $payload = $this->verify($ticket);
        $legacyId = (int) $payload['admin_id'];
        $identity = $payload;

        return DB::transaction(function () use ($payload, $identity, $legacyId, $requestIp): int {
            $this->recordConsumption($payload, $legacyId, $requestIp);

            $username = trim((string) ($identity['username'] ?? ''));
            if ($username === '') {
                throw new ErpSsoException('旧 ERP 登录票据缺少账号标识。');
            }

            $conflictingId = DB::table('erp_legacy_admin_users')
                ->where('username', $username)
                ->where('legacy_id', '<>', $legacyId)
                ->value('legacy_id');
            if ($conflictingId) {
                throw new ErpSsoException('该账号名已关联其他旧 ERP 身份，请联系管理员处理。', 409);
            }

            $hasDepartments = array_key_exists('departments', $identity) && is_array($identity['departments']);
            $hasGroups = array_key_exists('auth_groups', $identity) && is_array($identity['auth_groups']);
            $departments = $hasDepartments ? $identity['departments'] : [];
            $groups = $hasGroups ? $identity['auth_groups'] : [];
            $now = now();
            $existingUser = DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first();
            $wasPrincipal = DB::table('erp_department_users')->where('user_legacy_id', $legacyId)->where('is_principal', true)->exists();
            $previousRoleCandidate = $this->previousProjectedRole($existingUser, $wasPrincipal);
            $existingPayload = json_decode(($existingUser->legacy_payload ?? null) ?: '{}', true) ?: [];
            $projectedPayload = array_merge($existingPayload, [
                'source_system' => 'fastadmin',
                'last_sso_issued_at' => (int) $payload['issued_at'],
            ]);
            if (array_key_exists('is_super_admin', $identity)) {
                $projectedPayload['is_super_admin'] = (bool) $identity['is_super_admin'];
            }

            $userValues = [
                    'username' => $username,
                    'nickname' => $this->nullableString($identity['nickname'] ?? null),
                    'mobile' => $this->nullableString($identity['mobile'] ?? null),
                    'email' => $this->nullableString($identity['email'] ?? null),
                    'status' => $this->nullableString($identity['status'] ?? null) ?: ($existingUser->status ?? 'normal'),
                    'sort' => (int) ($identity['sort'] ?? 0),
                    'is_sales' => array_key_exists('is_sales', $identity)
                        ? (bool) $identity['is_sales']
                        : (bool) ($existingUser->is_sales ?? false),
                    'legacy_payload' => json_encode($projectedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ];
            if ($hasDepartments) {
                $userValues['department_ids'] = json_encode(array_values(array_filter(array_map(fn ($row) => (int) ($row['id'] ?? 0), $departments))));
                $userValues['department_names'] = json_encode(array_values(array_filter(array_map(fn ($row) => trim((string) ($row['name'] ?? '')), $departments))), JSON_UNESCAPED_UNICODE);
            }
            if ($hasGroups) {
                $userValues['auth_group_ids'] = json_encode(array_values(array_filter(array_map(fn ($row) => (int) ($row['id'] ?? 0), $groups))));
                $userValues['auth_group_names'] = json_encode(array_values(array_filter(array_map(fn ($row) => trim((string) ($row['name'] ?? '')), $groups))), JSON_UNESCAPED_UNICODE);
            }
            if (!$existingUser) {
                $userValues['created_at'] = $now;
            }
            DB::table('erp_legacy_admin_users')->updateOrInsert(['legacy_id' => $legacyId], $userValues);

            if ($hasDepartments) {
                $this->syncDepartments($legacyId, $departments, $now);
            }

            $this->roleOwnership->syncSsoRole($legacyId, $this->roleFromPayload($identity), $previousRoleCandidate);

            return $legacyId;
        });
    }

    private function verify(string $ticket): array
    {
        $secret = (string) config('sso.shared_secret', '');
        if ($secret === '') {
            throw new ErpSsoException('新 ERP 单点登录密钥未配置。', 503);
        }

        $parts = explode('.', trim($ticket));
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/i', $parts[1])) {
            throw new ErpSsoException('单点登录票据格式无效。');
        }
        $expected = hash_hmac('sha256', $parts[0], $secret);
        if (!hash_equals($expected, strtolower($parts[1]))) {
            throw new ErpSsoException('单点登录票据签名无效。');
        }

        $encoded = strtr($parts[0], '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding) $encoded .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($encoded, true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new ErpSsoException('单点登录票据内容无效。');
        }

        $issuer = (string) ($payload['issuer'] ?? '');
        $legacyId = filter_var($payload['admin_id'] ?? null, FILTER_VALIDATE_INT);
        $issuedAt = filter_var($payload['issued_at'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($payload['expire_at'] ?? null, FILTER_VALIDATE_INT);
        $nonce = (string) ($payload['nonce'] ?? '');
        $ttl = max(60, min(600, (int) config('sso.ticket_ttl', 300)));
        $now = time();

        if ($issuer !== 'fastadmin' || !$legacyId || !$issuedAt || !$expiresAt
            || !preg_match('/^[a-f0-9]{32,128}$/i', $nonce)) {
            throw new ErpSsoException('单点登录票据缺少必要身份信息。');
        }
        if ($issuedAt > $now + 30 || $expiresAt <= $now || $expiresAt <= $issuedAt || $expiresAt - $issuedAt > $ttl + 30) {
            throw new ErpSsoException('单点登录票据已过期，请重新登录。');
        }
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if (! in_array($status, self::ACTIVE_STATUSES, true)) {
            throw new ErpSsoException('该账号状态不允许登录。', 403);
        }

        return $payload;
    }

    private function recordConsumption(array $payload, int $legacyId, ?string $requestIp): void
    {
        try {
            DB::table('erp_sso_ticket_consumptions')->insert([
                'issuer' => (string) $payload['issuer'],
                'nonce' => (string) $payload['nonce'],
                'user_legacy_id' => $legacyId,
                'ticket_issued_at' => CarbonImmutable::createFromTimestampUTC((int) $payload['issued_at']),
                'ticket_expires_at' => CarbonImmutable::createFromTimestampUTC((int) $payload['expire_at']),
                'consumed_at' => now(),
                'request_ip' => $requestIp,
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000') {
                throw new ErpSsoException('该登录票据已经使用，请重新登录。', 409);
            }
            throw $exception;
        }
    }

    private function syncDepartments(int $legacyId, array $departments, $now): void
    {
        DB::table('erp_department_users')->where('user_legacy_id', $legacyId)->delete();
        foreach ($departments as $department) {
            $departmentId = (int) ($department['id'] ?? 0);
            $name = trim((string) ($department['name'] ?? ''));
            if ($departmentId <= 0 || $name === '') continue;

            $departmentValues = [
                    'parent_legacy_id' => (int) ($department['parent_id'] ?? 0),
                    'name' => $name,
                    'status' => (string) ($department['status'] ?? 'normal'),
                    'sort' => (int) ($department['sort'] ?? 0),
                    'legacy_payload' => json_encode(['source_system' => 'fastadmin'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ];
            if (!DB::table('erp_departments')->where('legacy_id', $departmentId)->exists()) {
                $departmentValues['created_at'] = $now;
            }
            DB::table('erp_departments')->updateOrInsert(['legacy_id' => $departmentId], $departmentValues);
            DB::table('erp_department_users')->insert([
                'department_legacy_id' => $departmentId,
                'user_legacy_id' => $legacyId,
                'is_principal' => (bool) ($department['is_principal'] ?? false),
                'is_owner' => false,
                'legacy_payload' => json_encode(['source_system' => 'fastadmin'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function roleFromPayload(array $identity): string
    {
        $groups = is_array($identity['auth_groups'] ?? null) ? $identity['auth_groups'] : [];
        $groupNames = array_values(array_filter(array_map(fn ($row) => trim((string) ($row['name'] ?? '')), $groups)));
        $groupText = implode(' ', $groupNames);
        $departments = is_array($identity['departments'] ?? null) ? $identity['departments'] : [];
        $isPrincipal = collect($departments)->contains(fn ($row) => (bool) ($row['is_principal'] ?? false));

        return match (true) {
            ($identity['username'] ?? '') === 'admin',
            in_array('Admin group', $groupNames, true),
            (bool) ($identity['is_super_admin'] ?? false) => 'admin',
            str_contains($groupText, '销售负责人') || str_contains(strtolower($groupText), 'sales manager') => 'sales_manager',
            $isPrincipal => 'department_principal',
            (bool) ($identity['is_sales'] ?? false) => 'sales_user',
            default => 'production_operator',
        };
    }

    private function previousProjectedRole(?object $user, bool $wasPrincipal): ?string
    {
        if (! $user) return null;
        $legacyPayload = json_decode(($user->legacy_payload ?? null) ?: '{}', true) ?: [];
        if (($legacyPayload['source_system'] ?? null) !== 'fastadmin') return null;

        return $this->roleFromPayload([
            'username' => $user->username,
            'auth_groups' => array_map(fn ($name) => ['name' => $name], json_decode($user->auth_group_names ?: '[]', true) ?: []),
            'departments' => $wasPrincipal ? [['is_principal' => true]] : [],
            'is_sales' => (bool) $user->is_sales,
            'is_super_admin' => (bool) ($legacyPayload['is_super_admin'] ?? false),
        ]);
    }
}
