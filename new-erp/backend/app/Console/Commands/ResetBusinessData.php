<?php

namespace App\Console\Commands;

use Database\Schema\InitialTableMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

require_once database_path('schema/InitialTableMigration.php');

/**
 * 开发库重置为纯净库：只保留系统设置（管理员、部门、菜单与权限、角色授权、编号规则）和登录会话，
 * 其余全部清空（含编号计数器、审批配置、基础字典、工序等）；需要的基础数据由 seeder 重新灌入。
 *
 * 被清空的表若与当前迁移定义的结构不一致（如建表语句新增了字段），按当前定义删表重建；
 * 保留表出现结构不一致则拒绝执行。执行后跑结构预检，再执行 php artisan migrate 建新扩展表。
 * 不带 --force 只预览，不改任何数据。
 */
class ResetBusinessData extends Command
{
    protected $signature = 'erp:business-data-reset {--force : 确认执行（不带此参数只预览）}';

    protected $description = '开发库重置为纯净库：只保留系统设置，其余清空（含编号计数器），并按当前迁移定义重建结构不一致的表';

    /** 保留数据的表（系统设置）；彼此之间的外键已闭合，不引用任何被清空的表。 */
    private const KEEP_TABLES = [
        'sessions', 'erp_auth_tokens', 'erp_sso_ticket_consumptions',
        'erp_legacy_admin_users', 'erp_departments', 'erp_department_users',
        'erp_rbac_permissions', 'erp_rbac_role_permissions', 'erp_rbac_roles', 'erp_rbac_user_role_sources', 'erp_rbac_user_roles',
        'erp_document_number_rules',
    ];

    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('只支持 MySQL。');
            return self::FAILURE;
        }
        $version = (string) (((array) $connection->selectOne('SELECT VERSION() AS v'))['v'] ?? '');
        $definitions = InitialTableMigration::definitions();
        $existing = collect($connection->select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"))
            ->pluck('name')->all();
        $unknownKeep = array_diff(self::KEEP_TABLES, array_keys($definitions));
        if ($unknownKeep) {
            $this->error('保留清单含未定义的表：'.implode('、', $unknownKeep));
            return self::FAILURE;
        }

        $clear = []; $rebuild = []; $keptDrift = []; $rows = 0;
        foreach ($definitions as $table => $definition) {
            if (! in_array($table, $existing, true)) continue;
            $differences = $definition->schemaDifferences($this->showCreate($table), $version);
            if (in_array($table, self::KEEP_TABLES, true)) {
                if ($differences) $keptDrift[] = $table.'（'.implode('、', $differences).'）';
                continue;
            }
            $count = (int) DB::table($table)->count();
            $rows += $count;
            if ($differences) $rebuild[$table] = $count;
            else $clear[$table] = $count;
        }
        if ($keptDrift) {
            $this->error('以下保留表的结构与迁移定义不一致，保留数据时不能重建，请先处理：'.implode('；', $keptDrift));
            return self::FAILURE;
        }

        $this->info('保留 '.count(array_intersect(self::KEEP_TABLES, $existing)).' 张表的数据：管理员、部门、菜单与权限、角色授权、编号规则、登录会话。');
        $this->info('将清空业务表 '.(count($clear) + count($rebuild)).' 张，共 '.$rows.' 行。');
        if ($rebuild) {
            $this->warn('其中结构与当前迁移定义不一致、将按定义删表重建 '.count($rebuild).' 张：'.implode('、', array_keys($rebuild)));
        }
        $notEmpty = array_filter($clear + $rebuild);
        arsort($notEmpty);
        if ($notEmpty) $this->line('有数据的表（前 20）：'.collect($notEmpty)->take(20)->map(fn ($n, $t) => "{$t}={$n}")->implode('，'));
        if (! $this->option('force')) {
            $this->line('这是预览，未改任何数据。确认后执行：php artisan erp:business-data-reset --force');
            return self::SUCCESS;
        }

        $original = (string) (((array) $connection->selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS v'))['v'] ?? '1');
        try {
            DB::statement('SET SESSION FOREIGN_KEY_CHECKS = 0');
            foreach (array_keys($rebuild) as $table) {
                DB::statement('DROP TABLE `'.$table.'`');
                DB::statement($definitions[$table]->createSql($version));
            }
            foreach (array_keys($clear) as $table) DB::statement('TRUNCATE TABLE `'.$table.'`');
        } finally {
            DB::statement('SET SESSION FOREIGN_KEY_CHECKS = '.($original === '0' ? '0' : '1'));
        }
        $this->info('业务数据已清空'.($rebuild ? '，'.count($rebuild).' 张表已按当前定义重建' : '').'。');

        try {
            $plan = $this->preflight($connection, $definitions);
            $this->info('结构预检通过（'.$plan['mode'].'）。'.($plan['missing'] ? '待建新表：'.implode('、', $plan['missing']).'，请接着执行 php artisan migrate。' : '').'基础数据请按需执行 php artisan db:seed。');
        } catch (Throwable $error) {
            $this->error('结构预检未通过：'.$error->getMessage());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    private function showCreate(string $table): string
    {
        return (string) (((array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`'))['Create Table'] ?? '');
    }

    /** 有待建扩展表时按扩展口径预检，否则按完整基线预检。 */
    private function preflight($connection, array $definitions): array
    {
        $existing = collect($connection->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))->pluck('name')->all();
        foreach ($definitions as $table => $definition) {
            if (! in_array($table, $existing, true) && $definition::ESTABLISHED_EXTENSION) {
                return $definition->preflightEstablishedExtension($connection);
            }
        }
        return InitialTableMigration::preflight($connection);
    }
}
