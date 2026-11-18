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
            // Use the same organization lock as local maintenance so a signed
            // ticket cannot recreate a just-deleted account or reuse a local ID.
            DB::table('erp_rbac_roles')->where('code', 'admin')->lockForUpdate()->first();

            $username = trim((string) ($identity['username'] ?? ''));
            if ($username === '') {
                throw new ErpSsoException('旧 ERP 登录票据缺少账号标识。');
            }

            $hasDepartments = array_key_exists('departments', $identity) && is_array($identity['departments']);
            $hasGroups = array_key_exists('auth_groups', $identity) && is_array($identity['auth_groups']);
            $departments = $hasDepartments ? $identity['departments'] : [];
            $groups = $hasGroups ? $identity['auth_groups'] : [];
            $now = now();
            $sourceId = $legacyId;
            $users = DB::table('erp_legacy_admin_users');
            $mapped = (clone $users)->where('legacy_payload->legacy_sso_identity->issuer', 'fastadmin')
                ->where('legacy_payload->legacy_sso_identity->admin_id', $sourceId)->where('legacy_id', '<>', $sourceId)
                ->lockForUpdate()->first();
            $existingUser = $mapped ?? (clone $users)->where('legacy_id', $sourceId)->lockForUpdate()->first();
            $existingPayload = json_decode(($existingUser->legacy_payload ?? null) ?: '{}', true) ?: [];
            if ($existingUser && ($existingUser->deleted_at !== null || ! in_array($existingUser->status, self::ACTIVE_STATUSES, true))) {
                throw new ErpSsoException('该账号已在新 ERP 停用或删除，不能登录。', 403);
            }
            $localAccount = ($existingPayload['auth_source'] ?? '') === 'local_management';
            if (($localAccount || isset($existingPayload['legacy_sso_identity'])) && !$this->matchesMigratedIdentity($existingPayload, $sourceId)) {
                // 同号但属于另一个身份，单独建档；以后的票据仍通过来源编号找到它。
                $legacyId = (int) (clone $users)->max('legacy_id') + 1;
                $existingUser = null;
                $existingPayload = [];
            } elseif ($existingUser) {
                $legacyId = (int) $existingUser->legacy_id;
            }
            $this->recordConsumption($payload, $legacyId, $requestIp);
            $binding = ['issuer' => 'fastadmin', 'admin_id' => $sourceId, 'username' => $username];
            $loginName = $username;
            if ((clone $users)->where('username', $username)->where('legacy_id', '<>', $legacyId)->exists()) {
                $loginName = $existingUser->username ?? ('sso_'.\Illuminate\Support\Str::ulid());
            }
            $identity['username'] = $loginName;
            if ($existingUser && ($localAccount || $existingUser->local_managed)) {
                $boundUsername = $existingPayload['legacy_sso_identity']['username'] ?? $existingPayload['users_migration']['identity']['username'] ?? $existingUser->username;
                if ($boundUsername !== $username) {
                    $existingPayload['legacy_sso_identity'] = $binding;
                    DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->update([
                        'username' => $loginName,
                        'business_version' => $existingUser->business_version + 1,
                        'legacy_payload' => json_encode($existingPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $now,
                    ]);
                }
                // 已迁入账号用旧身份完成认证，人员档案和授权仍由新 ERP 管理。
                return $legacyId;
            }
            $wasPrincipal = DB::table('erp_department_users')->where('user_legacy_id', $legacyId)->where('is_principal', true)->exists();
            $previousRoleCandidate = $this->previousProjectedRole($existingUser, $wasPrincipal);
            $projectedPayload = array_merge($existingPayload, [
                'source_system' => 'fastadmin',
                'legacy_sso_identity' => $binding,
                'last_sso_issued_at' => (int) $payload['issued_at'],
            ]);
            if (array_key_exists('is_super_admin', $identity)) {
                $projectedPayload['is_super_admin'] = (bool) $identity['is_super_admin'];
            }

            $userValues = [
                    'username' => $loginName,
                    'nickname' => $this->nullableString($identity['nickname'] ?? null),
                    'mobile' => $this->nullableString($identity['mobile'] ?? null),
                    'email' => $this->nullableString($identity['email'] ?? null),
                    'status' => $this->nullableString($identity['status'] ?? null) ?: ($existingUser->status ?? 'normal'),
                    'sort' => (int) ($identity['sort'] ?? 0),
                    'is_sales' => array_key_exists('is_sales', $identity)
                        ? (bool) $identity['is_sales']
                        : (bool) ($existingUser->is_sales ?? false),
                    'legacy_payload' => json_encode($projectedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'business_version' => (int) ($existingUser->business_version ?? 0) + 1,
                    'updated_at' => $now,
                ];
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
                $currentDepartments = DB::table('erp_department_users as du')
                    ->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
                    ->where('du.user_legacy_id', $legacyId)->orderBy('du.id')->get(['d.legacy_id', 'd.name', 'du.is_principal']);
                $identity['departments'] = $currentDepartments->map(fn ($row) => (array) $row)->all();
                DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->update([
                    'department_ids' => json_encode($currentDepartments->pluck('legacy_id')->map(fn ($id) => (int) $id)->all()),
                    'department_names' => json_encode($currentDepartments->pluck('name')->all(), JSON_UNESCAPED_UNICODE),
                ]);
            }

            // 来源系统声明为销售的账号补登记业务员档案（不覆盖本地停用），is_sales 随档案投影。
            if (array_key_exists('is_sales', $identity)) {
                app(BusinessStaffService::class)->syncSourceSalesFlag($legacyId, (bool) $identity['is_sales']);
            }
            $this->roleOwnership->syncSsoRole($legacyId, $this->roleFromPayload($identity), $previousRoleCandidate);

            return $legacyId;
        });
    }

    private function matchesMigratedIdentity(array $localPayload, int $legacyId): bool
    {
        if (array_key_exists('legacy_sso_identity', $localPayload)) {
            $binding = $localPayload['legacy_sso_identity'];
            return ($binding['issuer'] ?? null) === 'fastadmin'
                && in_array($binding['admin_id'] ?? null, [$legacyId, (string) $legacyId], true);
        }

        // 兼容正式用户包记录旧身份之前已同步的员工。
        $migration = $localPayload['users_migration'] ?? [];
        $sourceIdentity = $migration['identity'] ?? [];
        $sourceId = $sourceIdentity['id'] ?? null;
        return ($migration['source'] ?? null) === 'jiantan_erp'
            && ($sourceId === $legacyId || $sourceId === (string) $legacyId);
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
        $memberships = DB::table('erp_department_users')->where('user_legacy_id', $legacyId);
        $localDepartments = DB::table('erp_departments')->whereIn('legacy_id', array_column($departments, 'id'))
            ->where('legacy_payload->auth_source', 'local_management')->whereNull('deleted_at')
            ->lockForUpdate()->get(['legacy_id', 'name', 'status'])->keyBy('legacy_id');
        // 优先使用当前成员关系。首次建档只有编号、名称都吻合才加入本地部门。
        if ($localDepartments->isNotEmpty() && $memberships->exists()) return;
        $memberships->delete();
        foreach ($departments as $department) {
            $departmentId = (int) ($department['id'] ?? 0);
            $name = trim((string) ($department['name'] ?? ''));
            if ($departmentId <= 0 || $name === '') continue;
            $local = $localDepartments->get($departmentId);
            if ($local && ($local->name !== $name || $local->status !== 'normal')) continue;
            $existingDepartment = DB::table('erp_departments')->where('legacy_id', $departmentId)->lockForUpdate()->first();
            if ($existingDepartment->deleted_at ?? null) {
                throw new ErpSsoException('登录身份引用了已删除部门，请联系管理员核对部门归属。', 403);
            }

            $departmentValues = [
                    'parent_legacy_id' => (int) ($department['parent_id'] ?? 0),
                    'name' => $name,
                    'status' => (string) ($department['status'] ?? 'normal'),
                    'sort' => (int) ($department['sort'] ?? 0),
                    'legacy_payload' => json_encode(['source_system' => 'fastadmin'], JSON_UNESCAPED_UNICODE),
                    'business_version' => (int) ($existingDepartment->business_version ?? 0) + 1,
                    'updated_at' => $now,
                ];
            if (!DB::table('erp_departments')->where('legacy_id', $departmentId)->exists()) {
                $departmentValues['created_at'] = $now;
            }
            if (! $local && ! ($existingDepartment->local_managed ?? false)) DB::table('erp_departments')->updateOrInsert(['legacy_id' => $departmentId], $departmentValues);
            DB::table('erp_department_users')->insert([
                'department_legacy_id' => $departmentId,
                'user_legacy_id' => $legacyId,
                'is_principal' => !$local && (bool) ($department['is_principal'] ?? false),
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
