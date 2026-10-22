<?php

namespace App\Console\Commands;

use Database\Schema\InitialTableMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

require_once database_path('schema/InitialTableMigration.php');

/**
 * 业务数据重置：保留系统设置（管理员、部门、菜单与权限、角色、编号规则及计数器、登录会话）
 * 和不依赖物料的基础字典（审批配置、币种/汇率、收付款方式、销售渠道、计量单位、物料分类、
 * 工序/生产阶段/包装方案/提成模板），清空其余全部业务数据（订单、仓库库位、库存、物料、SKU、BOM、
 * 工艺路线、工单、采购、财务单据等）。
 *
 * 被清空的表若与当前迁移定义的结构不一致（如建表语句新增了字段），按当前定义删表重建；
 * 保留表出现结构不一致则拒绝执行。执行后跑结构预检，再执行 php artisan migrate 建新扩展表。
 * 不带 --force 只预览，不改任何数据。
 */
class ResetBusinessData extends Command
{
    protected $signature = 'erp:business-data-reset {--force : 确认执行（不带此参数只预览）}';

    protected $description = '清空业务数据（保留系统设置与基础字典），并按当前迁移定义重建结构不一致的业务表';

    /** 保留数据的表；彼此之间的外键已闭合，不引用任何被清空的表。 */
    private const KEEP_TABLES = [
        // 框架
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'sessions',
        // 系统设置
        'erp_legacy_admin_users', 'erp_auth_tokens', 'erp_sso_ticket_consumptions', 'erp_departments', 'erp_department_users',
        'erp_rbac_permissions', 'erp_rbac_role_permissions', 'erp_rbac_roles', 'erp_rbac_user_role_sources', 'erp_rbac_user_roles',
        // 编号计数器一并保留：工序等保留资料已占用的编号不会被重新发出。
        'erp_document_number_rules', 'erp_document_numbers', 'erp_system_management_commands', 'erp_operation_logs',
        // 审批配置（不含审批实例）
        'erp_approval_business_objects', 'erp_approval_business_object_fields', 'erp_approval_business_actions', 'erp_approval_business_events',
        'erp_approval_flow_templates', 'erp_approval_flow_versions', 'erp_approval_form_templates', 'erp_approval_form_versions',
        // 不依赖物料的基础字典
        'erp_finance_currencies', 'erp_finance_exchange_rates', 'erp_payment_methods', 'erp_sales_channels', 'erp_sales_funding_policies',
        'erp_sales_order_pay_types', 'erp_sales_order_trade_platforms', 'erp_units', 'erp_unit_conversions', 'erp_item_categories',
        'erp_production_operations', 'erp_production_operation_workers', 'erp_production_stages', 'erp_production_packaging_schemes',
        'erp_production_performance_templates', 'erp_production_performance_template_lines', 'erp_production_catalog_commands',
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

        $this->info('保留 '.count(array_intersect(self::KEEP_TABLES, $existing)).' 张表的数据：系统设置、审批配置、基础字典、工序/生产阶段/包装方案/提成模板。');
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
            $this->info('结构预检通过（'.$plan['mode'].'）。'.($plan['missing'] ? '待建新表：'.implode('、', $plan['missing']).'，请接着执行 php artisan migrate。' : ''));
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
