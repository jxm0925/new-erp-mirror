<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** 仅补缺默认本地管理员，已有账号、密码、状态和授权保持原样。 */
class ErpAdministratorSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        // 正式管理员以稳定身份识别；人工改名、停用或软删除后不另造同名管理员。
        if (DB::table('erp_legacy_admin_users')->where('legacy_id', 1)->lockForUpdate()->exists()) return;
        SeedSafety::insertMissing('erp_legacy_admin_users', ['username' => 'admin'], [
            'legacy_id' => max(1, (int) DB::table('erp_legacy_admin_users')->max('legacy_id') + 1),
            'password_hash' => Hash::make('123456'),
            'nickname' => '管理员',
            'status' => 'normal',
            'local_managed' => true,
            'legacy_payload' => json_encode(['auth_source' => 'local_management']),
        ]);
    }
}
