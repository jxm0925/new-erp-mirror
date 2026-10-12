<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;

/** 保留旧调用入口，但账号和授权必须由正式账号管理处理，环境变量不再触发安全变更。 */
class ErpAdministratorSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $this->command?->warn('管理员Seeder未创建账号、修改密码或分配权限；请通过正式账号管理或另行审批的安装流程处理。');
    }
}
