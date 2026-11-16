<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 确定的角色/权限目录按原身份补缺；权限矩阵仅在本次新建角色时初始化。 */
class ErpRbacSeeder extends Seeder
{
    use RunsSafeSeed;

    public const ROLE_FIELDS = ['id', 'code', 'name', 'data_scope', 'enabled', 'is_system', 'remark', 'created_at', 'updated_at', 'business_version'];
    public const PERMISSION_FIELDS = ['id', 'parent_id', 'code', 'name', 'type', 'path', 'component', 'icon', 'sort', 'enabled', 'is_system', 'remark', 'created_at', 'updated_at'];

    private function seed(): void
    {
        $path = __DIR__.'/data/erp_approved_rbac_configuration.json';
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->validatePackage($data);
        $timezone = DB::selectOne('SELECT @@session.time_zone AS value')->value;
        try {
            DB::statement("SET SESSION time_zone = '+00:00'");
            $this->restore($data);
        } finally {
            DB::statement('SET SESSION time_zone = ?', [$timezone]);
        }
    }

    private function restore(array $data): void
    {
        $newRoleCodes = array_values(array_diff(array_column($data['roles'], 'code'), DB::table('erp_rbac_roles')->lockForUpdate()->pluck('code')->all()));
        $pending = array_column($data['permissions'], null, 'id');
        $finished = [];
        while ($pending) {
            $progress = false;
            foreach ($pending as $id => $row) {
                if ($row['parent_id'] !== null && !isset($finished[$row['parent_id']])) continue;
                SeedSafety::restoreRows('erp_rbac_permissions', [$row], ['code']);
                $finished[$id] = true;
                unset($pending[$id]);
                $progress = true;
            }
            if (!$progress) throw new RuntimeException('确定权限目录的父子关系存在循环。');
        }
        SeedSafety::restoreRows('erp_rbac_roles', $data['roles'], ['code']);
        $roleIds = DB::table('erp_rbac_roles')->pluck('id', 'code')->all();
        $permissionIds = DB::table('erp_rbac_permissions')->pluck('id', 'code')->all();
        foreach ($data['role_permissions'] as $row) {
            // 已有角色即使被人工清空权限，也不再恢复整组授权。
            if (!in_array($row['role_code'], $newRoleCodes, true)) continue;
            SeedSafety::restoreRows('erp_rbac_role_permissions', [['role_id' => $roleIds[$row['role_code']], 'permission_id' => $permissionIds[$row['permission_code']]]], ['role_id', 'permission_id'], null);
        }
    }

    private function validatePackage(array $data): void
    {
        if (($data['contract_version'] ?? null) !== 1 || ($data['timestamps_timezone'] ?? null) !== 'UTC') throw new RuntimeException('确定RBAC数据包契约无效。');
        $identities = [];
        foreach (['roles' => self::ROLE_FIELDS, 'permissions' => self::PERMISSION_FIELDS] as $key => $fields) {
            if (!is_array($data[$key] ?? null) || !array_is_list($data[$key]) || count($data[$key]) !== ($data['table_counts'][$key] ?? null)) throw new RuntimeException('确定RBAC记录数量无效：'.$key);
            $ids = $codes = [];
            foreach ($data[$key] as $row) {
                if (array_diff(array_keys($row), $fields) || array_diff($fields, array_keys($row)) || !is_int($row['id']) || $row['id'] < 1
                    || isset($ids[$row['id']]) || !is_string($row['code']) || $row['code'] === '' || isset($codes[$row['code']])) throw new RuntimeException('确定RBAC身份或字段无效：'.$key);
                $ids[$row['id']] = $codes[$row['code']] = true;
            }
            $identities[$key] = compact('ids', 'codes');
        }
        foreach ($data['permissions'] as $row) if ($row['parent_id'] !== null && !isset($identities['permissions']['ids'][$row['parent_id']])) throw new RuntimeException('确定权限目录缺少父身份。');
        if (!is_array($data['role_permissions'] ?? null) || !array_is_list($data['role_permissions'])
            || count($data['role_permissions']) !== ($data['table_counts']['role_permissions'] ?? null)) throw new RuntimeException('确定角色权限数量无效。');
        $seen = [];
        foreach ($data['role_permissions'] as $row) {
            $key = ($row['role_code'] ?? '').'|'.($row['permission_code'] ?? '');
            if (array_diff(array_keys($row), ['role_code', 'permission_code']) || isset($seen[$key])
                || !isset($identities['roles']['codes'][$row['role_code'] ?? ''], $identities['permissions']['codes'][$row['permission_code'] ?? ''])) throw new RuntimeException('确定角色权限关联身份无效。');
            $seen[$key] = true;
        }
    }
}
