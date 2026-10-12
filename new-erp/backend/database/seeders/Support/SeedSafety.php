<?php

namespace Database\Seeders\Support;

use Database\Schema\InitialTableMigration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

require_once __DIR__.'/../../schema/InitialTableMigration.php';

/** 安装补缺与配置升级分开：结构先严格核对，任何非重复错误必须向上传播。 */
final class SeedSafety
{
    public static function assertSchema(): void
    {
        self::assertPlan(InitialTableMigration::preflight(DB::connection()));
    }

    public static function assertPlan(array $plan): void
    {
        if (($plan['mode'] ?? null) !== 'existing' || ($plan['missing'] ?? null) !== []) {
            throw new RuntimeException('基础数据补缺要求已完整迁移且通过严格结构预检；请先完成正式迁移。');
        }
    }

    public static function insertMissing(string $table, array $key, array $values): bool
    {
        if (DB::table($table)->where($key)->lockForUpdate()->exists()) {
            return false;
        }
        // 不使用 INSERT IGNORE：外键、非空、长度及并发唯一冲突都应报错并回滚，不能伪装成功。
        DB::table($table)->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
        return true;
    }
}
