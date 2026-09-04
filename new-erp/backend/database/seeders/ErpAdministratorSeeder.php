<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ErpAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('erp_legacy_admin_users') || !Schema::hasTable('erp_rbac_roles')) {
            return;
        }

        $password = (string) env('ERP_SEED_ADMIN_PASSWORD', '');
        if ($password === '') {
            $this->command?->warn('ERP_SEED_ADMIN_PASSWORD 未设置，跳过本地管理员账号初始化。');
            return;
        }

        $username = trim((string) env('ERP_SEED_ADMIN_USERNAME', 'admin')) ?: 'admin';
        $requestedLegacyId = max(1, (int) env('ERP_SEED_ADMIN_LEGACY_ID', 1));
        $resetPassword = filter_var(env('ERP_SEED_ADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOL);

        $existing = DB::table('erp_legacy_admin_users')->where('username', $username)->first();
        $legacyId = $existing?->legacy_id;

        if (!$legacyId) {
            $legacyId = DB::table('erp_legacy_admin_users')->where('legacy_id', $requestedLegacyId)->exists()
                ? ((int) DB::table('erp_legacy_admin_users')->max('legacy_id') + 1)
                : $requestedLegacyId;
        }

        $payload = [
            'username' => $username,
            'nickname' => '系统管理员',
            'status' => 'normal',
            'sort' => 0,
            'is_sales' => false,
            'legacy_payload' => json_encode(['auth_source' => 'local_seed'], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];

        if (!$existing) {
            $payload['legacy_id'] = $legacyId;
            $payload['password_hash'] = Hash::make($password);
            $payload['created_at'] = now();
            DB::table('erp_legacy_admin_users')->insert($payload);
        } else {
            if ($resetPassword || empty($existing->password_hash)) {
                $payload['password_hash'] = Hash::make($password);
            }
            DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->update($payload);
        }

        $adminRoleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        if (!$adminRoleId) {
            throw new \RuntimeException('未找到 admin 角色，请先执行 ErpRbacSeeder。');
        }

        if (Schema::hasTable('erp_rbac_user_roles')) {
            DB::table('erp_rbac_user_roles')->insertOrIgnore([
                'user_legacy_id' => $legacyId,
                'role_id' => $adminRoleId,
            ]);
        }

        if (Schema::hasTable('erp_rbac_role_permissions')) {
            foreach (DB::table('erp_rbac_permissions')->pluck('id') as $permissionId) {
                DB::table('erp_rbac_role_permissions')->insertOrIgnore([
                    'role_id' => $adminRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
}

