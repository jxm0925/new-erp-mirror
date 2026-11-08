<?php

namespace Database\Seeders;

use App\Services\Erp\BusinessStaffService;
use App\Services\Erp\RbacUserRoleOwnershipService;
use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 用户确认的正式人员基线：已有账号及其全部关联组保持，只为本次新账号补缺。 */
class ErpOfficialUsersSeeder extends Seeder
{
    use RunsSafeSeed;

    public const ACCOUNT_FIELDS = ['id', 'legacy_id', 'username', 'password_hash', 'nickname', 'mobile', 'email', 'status', 'sort',
        'is_sales', 'department_ids', 'department_names', 'auth_group_ids', 'auth_group_names', 'local_managed', 'business_version', 'created_at', 'updated_at'];
    public const DEPARTMENT_FIELDS = ['id', 'legacy_id', 'parent_legacy_id', 'name', 'status', 'sort', 'local_managed', 'business_version', 'created_at', 'updated_at'];
    public const MEMBERSHIP_FIELDS = ['id', 'department_legacy_id', 'user_legacy_id', 'is_principal', 'is_owner', 'created_at', 'updated_at'];
    public const STAFF_FIELDS = ['id', 'user_legacy_id', 'staff_type', 'department_legacy_id', 'status', 'effective_from', 'effective_to',
        'source', 'remark', 'business_version', 'created_by_legacy_id', 'updated_by_legacy_id', 'created_at', 'updated_at'];
    private array $newUsers = [];
    private array $denied = [];

    private function seed(): void
    {
        $bytes = file_get_contents(__DIR__.'/data/erp_official_users.json');
        if ($bytes === false) throw new RuntimeException('正式人员数据包缺失，停止初始化，不能回退创建其他默认密码。');
        try { $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('正式人员数据包JSON无效，未输出认证数据。'); }
        $this->validatePackage($data);
        $this->denied = array_map('intval', $data['tombstones']['user_legacy_ids']);
        $this->newUsers = [];
        DB::connection()->disableQueryLog();
        if (!DB::table('erp_rbac_roles')->where('code', 'admin')->lockForUpdate()->first()) throw new RuntimeException('请先初始化正式角色定义，再补正式人员。');
        $timezone = DB::selectOne('SELECT @@session.time_zone AS value')->value;
        $phpTimezone = date_default_timezone_get();
        try {
            DB::statement("SET SESSION time_zone = '+00:00'");
            date_default_timezone_set('UTC');
            $this->departments($data['departments']);
            foreach ($data['accounts'] as $row) {
                $legacyId = (int) $row['legacy_id'];
                // legacy_id是已建立的员工身份；正常人工改名、停用、删除和密码调整都必须保留整行。
                if (DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->lockForUpdate()->exists()) continue;
                if (DB::table('erp_legacy_admin_users')->where('id', $row['id'])->orWhere('username', $row['username'])->lockForUpdate()->exists()) {
                    throw new RuntimeException('正式人员缺失身份的主键或登录名已被占用：'.$legacyId);
                }
                $row['department_ids'] = '[]';
                $row['department_names'] = '[]';
                $row['auth_group_ids'] = '[]';
                $row['auth_group_names'] = '[]';
                $row['local_managed'] = true;
                $row['legacy_payload'] = $this->json(['auth_source' => 'local_management', 'official_users_version' => 1]);
                DB::table('erp_legacy_admin_users')->insert($row);
                $this->newUsers[$legacyId] = true;
            }
            $this->memberships($data['memberships']);
            $this->staff($data['business_staff']);
            $this->roles($data['role_groups']);
            foreach (array_keys($this->newUsers) as $userId) {
                $members = DB::table('erp_department_users as du')->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
                    ->where('du.user_legacy_id', $userId)->whereNull('d.deleted_at')->orderBy('du.id')->get(['d.legacy_id', 'd.name']);
                DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->update([
                    'department_ids' => $this->json($members->pluck('legacy_id')->map(fn ($id) => (int) $id)->all()),
                    'department_names' => $this->json($members->pluck('name')->all())]);
                app(BusinessStaffService::class)->refreshProjection($userId);
            }
        } catch (QueryException $error) {
            // SQL异常会携带插入绑定值；密码哈希只能落账号字段，不能进入命令行异常或日志快照。
            throw new RuntimeException('正式人员补缺发生数据库冲突，事务已回滚；未输出账号认证字段。');
        } finally {
            date_default_timezone_set($phpTimezone);
            DB::statement('SET SESSION time_zone = ?', [$timezone]);
        }
    }

    private function departments(array $rows): void
    {
        $pending = array_column($rows, null, 'legacy_id');
        $blocked = [];
        while ($pending) {
            $progress = false;
            foreach ($pending as $id => $row) {
                $existing = DB::table('erp_departments')->where('legacy_id', $id)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->deleted_at !== null || $existing->status !== 'normal') $blocked[$id] = true;
                    unset($pending[$id]);
                    $progress = true;
                    continue;
                }
                $parentId = (int) $row['parent_legacy_id'];
                if ($parentId && isset($pending[$parentId])) continue;
                if ($parentId && isset($blocked[$parentId])) {
                    $blocked[$id] = true;
                    unset($pending[$id]);
                    $progress = true;
                    continue;
                }
                if ($parentId && !DB::table('erp_departments')->where('legacy_id', $parentId)->exists()) throw new RuntimeException('正式部门缺少明确上级：'.$id);
                if (DB::table('erp_departments')->where('id', $row['id'])->lockForUpdate()->exists()) throw new RuntimeException('正式部门主键被其他身份占用：'.$id);
                $row['legacy_payload'] = $this->json(['auth_source' => 'local_management', 'official_users_version' => 1]);
                DB::table('erp_departments')->insert($row);
                if ($row['status'] !== 'normal') $blocked[$id] = true;
                unset($pending[$id]);
                $progress = true;
            }
            if (!$progress) throw new RuntimeException('正式部门层级有循环。');
        }
    }

    private function memberships(array $rows): void
    {
        $groups = $this->group($rows, 'user_legacy_id');
        foreach (array_keys($this->newUsers) as $userId) {
            if (DB::table('erp_department_users')->where('user_legacy_id', $userId)->lockForUpdate()->exists()) continue;
            foreach ($groups[$userId] ?? [] as $row) {
                if (!$this->activeDepartment($row['department_legacy_id'])) continue;
                if (DB::table('erp_department_users')->where('id', $row['id'])->lockForUpdate()->exists()) throw new RuntimeException('正式部门成员主键被其他身份占用。');
                $row['legacy_payload'] = $this->json(['official_users_version' => 1]);
                DB::table('erp_department_users')->insert($row);
            }
        }
    }

    private function staff(array $rows): void
    {
        $groups = $this->group($rows, 'user_legacy_id');
        foreach (array_keys($this->newUsers) as $userId) {
            // 包括人工清空的关联组：已有账号根本不会进入newUsers，已有残留档案组也不补齐。
            if (DB::table('erp_business_staff')->where('user_legacy_id', $userId)->lockForUpdate()->exists()) continue;
            foreach ($groups[$userId] ?? [] as $row) {
                if (DB::table('erp_business_staff')->where('id', $row['id'])->lockForUpdate()->exists()) throw new RuntimeException('正式业务档案主键被其他身份占用。');
                if ($row['department_legacy_id'] !== null && !$this->activeDepartment($row['department_legacy_id'])) $row['department_legacy_id'] = null;
                DB::table('erp_business_staff')->insert($row);
            }
        }
    }

    private function roles(array $groups): void
    {
        $ownership = app(RbacUserRoleOwnershipService::class);
        foreach ($groups as $group) {
            $userId = (int) $group['user_legacy_id'];
            if (!isset($this->newUsers[$userId])) continue;
            if (DB::table('erp_rbac_user_roles')->where('user_legacy_id', $userId)->lockForUpdate()->exists()
                || DB::table('erp_rbac_user_role_sources')->where('user_legacy_id', $userId)->lockForUpdate()->exists()) continue;
            foreach ($group['roles'] as $assignment) {
                $role = DB::table('erp_rbac_roles')->where('code', $assignment['role_code'])->lockForUpdate()->first();
                if (!$role) throw new RuntimeException('正式人员角色编码未初始化：'.$assignment['role_code']);
                $ownership->addManualRole($userId, (int) $role->id);
            }
        }
    }

    private function validatePackage(#[\SensitiveParameter] array $data): void
    {
        if (($data['contract_version'] ?? null) !== 1 || ($data['timestamps_timezone'] ?? null) !== 'UTC'
            || !isset($data['table_counts'], $data['tombstones']['user_legacy_ids']) || !is_array($data['tombstones']['user_legacy_ids'])) {
            throw new RuntimeException('正式人员数据包契约无效。');
        }
        $accounts = $departments = [];
        foreach (['accounts' => self::ACCOUNT_FIELDS, 'departments' => self::DEPARTMENT_FIELDS,
            'memberships' => self::MEMBERSHIP_FIELDS, 'business_staff' => self::STAFF_FIELDS] as $key => $fields) {
            $rows = $data[$key] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) !== ($data['table_counts'][$key] ?? null)) throw new RuntimeException('正式人员记录数不匹配：'.$key);
            $ids = [];
            $identities = [];
            foreach ($rows as $row) {
                if (array_diff(array_keys($row), $fields) || !isset($row['id']) || !is_int($row['id']) || $row['id'] <= 0 || isset($ids[$row['id']])) throw new RuntimeException('正式人员记录字段或主键无效：'.$key);
                $identity = match ($key) {
                    'accounts', 'departments' => (string) ($row['legacy_id'] ?? ''),
                    'memberships' => ($row['user_legacy_id'] ?? '').':'.($row['department_legacy_id'] ?? ''),
                    'business_staff' => ($row['user_legacy_id'] ?? '').':'.($row['staff_type'] ?? ''),
                };
                if ($identity === '' || isset($identities[$identity])) throw new RuntimeException('正式人员存在重复业务身份：'.$key);
                $ids[$row['id']] = $identities[$identity] = true;
                if ($key === 'accounts') {
                    if (!is_int($row['legacy_id']) || $row['legacy_id'] <= 0 || empty($row['username']) || empty($row['password_hash'])
                        || password_get_info($row['password_hash'])['algoName'] === 'unknown'
                        || in_array($row['legacy_id'], $data['tombstones']['user_legacy_ids'], true)
                        || ($row['username'] === 'admin' && $row['legacy_id'] !== 1)) throw new RuntimeException('正式账号身份或初始化认证数据不完整。');
                    $accounts[$row['legacy_id']] = $row;
                }
                if ($key === 'departments') $departments[$row['legacy_id']] = $row;
            }
        }
        if (($accounts[1]['username'] ?? null) !== 'admin') throw new RuntimeException('正式用户包缺少确定的默认管理员身份。');
        foreach ($departments as $row) if ($row['parent_legacy_id'] && !isset($departments[$row['parent_legacy_id']])) throw new RuntimeException('正式部门父身份缺失。');
        foreach (['memberships', 'business_staff'] as $key) foreach ($data[$key] as $row) {
            if (!isset($accounts[$row['user_legacy_id']]) || in_array($row['user_legacy_id'], $data['tombstones']['user_legacy_ids'], true)) throw new RuntimeException('正式人员关联引用已删或缺失身份。');
            if ($row['department_legacy_id'] !== null && !isset($departments[$row['department_legacy_id']])) throw new RuntimeException('正式人员关联引用缺失部门。');
        }
        if (!is_array($data['role_groups'] ?? null) || count($data['role_groups']) !== ($data['table_counts']['role_groups'] ?? null)) throw new RuntimeException('正式人员角色组数量不匹配。');
        $seen = [];
        $assignmentCount = 0;
        foreach ($data['role_groups'] as $group) {
            $id = $group['user_legacy_id'] ?? null;
            if (!isset($accounts[$id]) || isset($seen[$id]) || !is_array($group['roles'] ?? null)) throw new RuntimeException('正式人员角色组身份无效。');
            $seen[$id] = true;
            $codes = [];
            foreach ($group['roles'] as $role) {
                if (empty($role['role_code']) || isset($codes[$role['role_code']]) || ($role['sources'] ?? null) !== ['manual']) throw new RuntimeException('正式人员角色编码或人工归属无效。');
                $codes[$role['role_code']] = true;
                $assignmentCount++;
            }
        }
        if (count($seen) !== count($accounts) || $assignmentCount !== ($data['table_counts']['role_assignments'] ?? null)
            || $assignmentCount !== ($data['table_counts']['role_sources'] ?? null)) throw new RuntimeException('正式人员角色记录或人工来源不完整。');
    }

    private function activeDepartment(int $id): bool { return DB::table('erp_departments')->where('legacy_id', $id)->whereNull('deleted_at')->where('status', 'normal')->exists(); }
    private function group(array $rows, string $field): array
    {
        $groups = [];
        foreach ($rows as $row) $groups[$row[$field]][] = $row;
        return $groups;
    }
    private function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
