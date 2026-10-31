<?php

namespace App\Console\Commands;

use App\Services\Erp\InventoryAvailabilityService;
use Database\Schema\InitialTableMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

require_once database_path('schema/InitialTableMigration.php');

/**
 * 生产模块 v2 一次性切换：删除旧生产/下料/工单相关表及其迁移记录，
 * 释放旧生产占用的库存锁定量和板材预留，然后跑严格结构预检。
 * 不带 --force 只打印将要执行的内容，不改任何数据。
 */
class ResetProductionV2 extends Command
{
    protected $signature = 'erp:production-v2-reset {--force : 确认执行（不带此参数只预览）}';

    protected $description = '生产模块 v2 切换：删除旧生产表、旧迁移记录并释放旧生产占用的库存';

    /** 旧生产模块的数据表（v2 重新设计，不迁移旧数据）。 */
    private const DROP_TABLES = [
        'erp_assembly_component_demands', 'erp_assembly_inventory_reservations', 'erp_assembly_production_plans', 'erp_cutting_allowed_outputs',
        'erp_cutting_commands', 'erp_cutting_corrections', 'erp_cutting_cost_dispositions', 'erp_cutting_demand_revisions', 'erp_cutting_demands',
        'erp_cutting_events', 'erp_cutting_handover_decisions', 'erp_cutting_handovers', 'erp_cutting_inventory_reservations', 'erp_cutting_orders',
        'erp_cutting_output_allocations', 'erp_cutting_plan_allocations', 'erp_cutting_remnant_receipt_lines', 'erp_cutting_remnant_receipts',
        'erp_cutting_result_routes', 'erp_cutting_results', 'erp_cutting_settlement_batches', 'erp_cutting_task_participants', 'erp_cutting_tasks',
        'erp_cutting_warehouse_receipt_allocations', 'erp_cutting_warehouse_receipts', 'erp_material_deliveries', 'erp_material_delivery_lines',
        'erp_material_delivery_task_assignments', 'erp_material_delivery_task_events', 'erp_material_delivery_task_lines',
        'erp_material_delivery_tasks', 'erp_material_delivery_waves', 'erp_material_physical_reservations', 'erp_material_picking_task_lines',
        'erp_material_picking_tasks', 'erp_material_procurement_sources', 'erp_material_receipt_lines', 'erp_material_receipts',
        'erp_production_cutting_inputs', 'erp_production_cutting_operations', 'erp_production_execution_commands', 'erp_production_input_holdings',
        'erp_production_internal_issue_lines', 'erp_production_internal_issue_tasks', 'erp_production_inventory_reservations',
        'erp_production_job_bundle_labor_allocations', 'erp_production_job_bundle_lines', 'erp_production_job_bundles',
        'erp_production_kitting_confirmation_lines', 'erp_production_kitting_confirmations', 'erp_production_labor_allocation_rules',
        'erp_production_labor_sessions', 'erp_production_master_orders', 'erp_production_material_commands', 'erp_production_material_consumptions',
        'erp_production_material_events', 'erp_production_material_return_cost_allocations', 'erp_production_material_return_inspections',
        'erp_production_material_return_lines', 'erp_production_material_returns', 'erp_production_material_supplement_lines',
        'erp_production_material_supplement_requests', 'erp_production_operation_handovers', 'erp_production_output_lineage_links',
        'erp_production_output_records', 'erp_production_output_warehouse_postings', 'erp_production_performance_assignments',
        'erp_production_performance_commands', 'erp_production_performance_rate_versions', 'erp_production_performance_shares',
        'erp_production_preparation_order_lines', 'erp_production_preparation_orders', 'erp_production_quality_inspections',
        'erp_production_quantity_operations', 'erp_production_reports', 'erp_production_serials', 'erp_production_target_material_requirements',
        'erp_production_task_assignments', 'erp_production_task_collaborators', 'erp_production_task_targets', 'erp_production_tasks',
        'erp_production_unit_equipment_identities', 'erp_production_unit_operations', 'erp_production_units',
        'erp_production_workstation_stock_confirmations', 'erp_public_material_preparation_tasks', 'erp_routing_operation_output_rules',
        'erp_sales_order_production_requirements', 'erp_warehouse_labor_sessions', 'erp_work_order_command_ledgers',
        'erp_work_order_completion_lines', 'erp_work_order_completions', 'erp_work_order_finished_goods_receipts',
        'erp_work_order_inventory_continuations', 'erp_work_order_material_preparation_versions', 'erp_work_order_material_preparations',
        'erp_work_order_material_requirements', 'erp_work_order_material_supply_rules', 'erp_work_order_planned_output_versions',
        'erp_work_order_planned_outputs', 'erp_work_order_preparation_materials', 'erp_work_order_release_gate_checks', 'erp_work_order_status_logs',
        'erp_work_order_technical_attachments', 'erp_work_order_technical_versions', 'erp_work_orders',
    ];

    /** 上述表对应的迁移文件已从仓库删除，迁移记录需同步清理。 */
    private const DROP_MIGRATIONS = [
        '2026_10_12_000020_create_erp_assembly_component_demands_table', '2026_10_12_000021_create_erp_assembly_inventory_reservations_table',
        '2026_10_12_000022_create_erp_assembly_production_plans_table', '2026_10_12_000030_create_erp_cutting_allowed_outputs_table',
        '2026_10_12_000031_create_erp_cutting_commands_table', '2026_10_12_000032_create_erp_cutting_corrections_table',
        '2026_10_12_000033_create_erp_cutting_cost_dispositions_table', '2026_10_12_000034_create_erp_cutting_demand_revisions_table',
        '2026_10_12_000035_create_erp_cutting_demands_table', '2026_10_12_000036_create_erp_cutting_events_table',
        '2026_10_12_000037_create_erp_cutting_handover_decisions_table', '2026_10_12_000038_create_erp_cutting_handovers_table',
        '2026_10_12_000039_create_erp_cutting_inventory_reservations_table', '2026_10_12_000040_create_erp_cutting_orders_table',
        '2026_10_12_000041_create_erp_cutting_output_allocations_table', '2026_10_12_000042_create_erp_cutting_plan_allocations_table',
        '2026_10_12_000043_create_erp_cutting_remnant_receipt_lines_table', '2026_10_12_000044_create_erp_cutting_remnant_receipts_table',
        '2026_10_12_000045_create_erp_cutting_result_routes_table', '2026_10_12_000046_create_erp_cutting_results_table',
        '2026_10_12_000047_create_erp_cutting_settlement_batches_table', '2026_10_12_000048_create_erp_cutting_task_participants_table',
        '2026_10_12_000049_create_erp_cutting_tasks_table', '2026_10_12_000050_create_erp_cutting_warehouse_receipt_allocations_table',
        '2026_10_12_000051_create_erp_cutting_warehouse_receipts_table', '2026_10_12_000141_create_erp_material_deliveries_table',
        '2026_10_12_000142_create_erp_material_delivery_lines_table', '2026_10_12_000143_create_erp_material_delivery_task_assignments_table',
        '2026_10_12_000144_create_erp_material_delivery_task_events_table', '2026_10_12_000145_create_erp_material_delivery_task_lines_table',
        '2026_10_12_000146_create_erp_material_delivery_tasks_table', '2026_10_12_000147_create_erp_material_delivery_waves_table',
        '2026_10_12_000152_create_erp_material_physical_reservations_table', '2026_10_12_000155_create_erp_material_picking_task_lines_table',
        '2026_10_12_000156_create_erp_material_picking_tasks_table', '2026_10_12_000157_create_erp_material_procurement_sources_table',
        '2026_10_12_000158_create_erp_material_receipt_lines_table', '2026_10_12_000159_create_erp_material_receipts_table',
        '2026_10_12_000203_create_erp_production_cutting_inputs_table', '2026_10_12_000204_create_erp_production_cutting_operations_table',
        '2026_10_12_000205_create_erp_production_execution_commands_table', '2026_10_12_000207_create_erp_production_input_holdings_table',
        '2026_10_12_000208_create_erp_production_internal_issue_lines_table', '2026_10_12_000209_create_erp_production_internal_issue_tasks_table',
        '2026_10_12_000210_create_erp_production_inventory_reservations_table',
        '2026_10_12_000211_create_erp_production_job_bundle_labor_allocations_table',
        '2026_10_12_000212_create_erp_production_job_bundle_lines_table', '2026_10_12_000213_create_erp_production_job_bundles_table',
        '2026_10_12_000214_create_erp_production_kitting_confirmation_lines_table',
        '2026_10_12_000215_create_erp_production_kitting_confirmations_table',
        '2026_10_12_000216_create_erp_production_labor_allocation_rules_table', '2026_10_12_000217_create_erp_production_labor_sessions_table',
        '2026_10_12_000219_create_erp_production_master_orders_table', '2026_10_12_000220_create_erp_production_material_commands_table',
        '2026_10_12_000221_create_erp_production_material_consumptions_table', '2026_10_12_000222_create_erp_production_material_events_table',
        '2026_10_12_000223_create_erp_production_material_return_cost_allocations_table',
        '2026_10_12_000224_create_erp_production_material_return_inspections_table',
        '2026_10_12_000225_create_erp_production_material_return_lines_table', '2026_10_12_000226_create_erp_production_material_returns_table',
        '2026_10_12_000227_create_erp_production_material_supplement_lines_table',
        '2026_10_12_000228_create_erp_production_material_supplement_requests_table',
        '2026_10_12_000229_create_erp_production_operation_handovers_table', '2026_10_12_000231_create_erp_production_output_lineage_links_table',
        '2026_10_12_000232_create_erp_production_output_records_table', '2026_10_12_000233_create_erp_production_output_warehouse_postings_table',
        '2026_10_12_000235_create_erp_production_performance_assignments_table',
        '2026_10_12_000236_create_erp_production_performance_commands_table', '2026_10_12_000237_create_erp_production_performance_shares_table',
        '2026_10_12_000238_create_erp_production_preparation_order_lines_table', '2026_10_12_000239_create_erp_production_preparation_orders_table',
        '2026_10_12_000240_create_erp_production_quality_inspections_table', '2026_10_12_000241_create_erp_production_quantity_operations_table',
        '2026_10_12_000242_create_erp_production_reports_table', '2026_10_12_000245_create_erp_production_serials_table',
        '2026_10_12_000247_create_erp_production_target_material_requirements_table',
        '2026_10_12_000248_create_erp_production_task_assignments_table', '2026_10_12_000249_create_erp_production_task_collaborators_table',
        '2026_10_12_000250_create_erp_production_task_targets_table', '2026_10_12_000251_create_erp_production_tasks_table',
        '2026_10_12_000252_create_erp_production_unit_equipment_identities_table', '2026_10_12_000253_create_erp_production_unit_operations_table',
        '2026_10_12_000254_create_erp_production_units_table', '2026_10_12_000255_create_erp_production_workstation_stock_confirmations_table',
        '2026_10_12_000257_create_erp_public_material_preparation_tasks_table', '2026_10_12_000330_create_erp_routing_operation_output_rules_table',
        '2026_10_12_000346_create_erp_sales_order_production_requirements_table', '2026_10_12_000425_create_erp_work_order_command_ledgers_table',
        '2026_10_12_000426_create_erp_work_order_completion_lines_table', '2026_10_12_000427_create_erp_work_order_completions_table',
        '2026_10_12_000428_create_erp_work_order_finished_goods_receipts_table',
        '2026_10_12_000429_create_erp_work_order_inventory_continuations_table',
        '2026_10_12_000430_create_erp_work_order_material_preparation_versions_table',
        '2026_10_12_000431_create_erp_work_order_material_preparations_table', '2026_10_12_000432_create_erp_work_order_material_requirements_table',
        '2026_10_12_000433_create_erp_work_order_material_supply_rules_table',
        '2026_10_12_000434_create_erp_work_order_planned_output_versions_table', '2026_10_12_000435_create_erp_work_order_planned_outputs_table',
        '2026_10_12_000436_create_erp_work_order_preparation_materials_table', '2026_10_12_000437_create_erp_work_order_release_gate_checks_table',
        '2026_10_12_000438_create_erp_work_order_status_logs_table', '2026_10_12_000439_create_erp_work_order_technical_attachments_table',
        '2026_10_12_000440_create_erp_work_order_technical_versions_table', '2026_10_12_000441_create_erp_work_orders_table',
        '2026_10_14_000001_create_erp_production_performance_rate_versions_table', '2026_10_16_000001_create_erp_warehouse_labor_sessions_table',
    ];

    /** 旧生产直接在库存余额上加锁的预留表。 */
    private const LOCK_TABLES = ['erp_production_inventory_reservations', 'erp_cutting_inventory_reservations', 'erp_assembly_inventory_reservations'];

    /** v2 仍保留的生产权限；其余 production.* 旧权限及其角色授权一并删除。 */
    private const KEEP_PERMISSIONS = [
        'production', 'production.work_order', 'production.base', 'production.operation', 'production.routing', 'production.performance',
        'production.performance_stats', 'production.work_order.view', 'production.work_order.create', 'production.work_order.edit', 'production.work_order.publish',
        'production.work_order.cancel', 'production.task.view', 'production.task.collaborate', 'production.performance.manage',
        'production.operation.view', 'production.operation.create', 'production.operation.edit', 'production.operation.toggle', 'production.operation.delete',
        'production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.routing.activate',
        'production.routing.default', 'production.routing.delete', 'production.serial', 'production.serial.view',
        'production.serial.assign', 'production.board', 'production.tech', 'production.task.execute', 'production.task.assign', 'production.scrap.approve',
        'production.receipt.execute', 'production.tech.manage', 'production.material_return.request', 'production.material_return.approve',
        'production.material_delivery.manage',
    ];

    public function handle(InventoryAvailabilityService $availability): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('只支持 MySQL。');
            return self::FAILURE;
        }
        $existing = collect($connection->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))
            ->pluck('name')->all();
        $tables = array_values(array_intersect(self::DROP_TABLES, $existing));
        $ledger = in_array('migrations', $existing, true)
            ? DB::table('migrations')->whereIn('migration', self::DROP_MIGRATIONS)->pluck('migration')->all() : [];
        $foreignKeys = $connection->select('SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS name, REFERENCED_TABLE_NAME AS target
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        $danglingKeys = array_values(array_filter($foreignKeys, fn ($fk) => in_array($fk->target, self::DROP_TABLES, true)
            && ! in_array($fk->table_name, self::DROP_TABLES, true)));
        $locks = $this->lockCorrections($existing);
        $reservedPhysicals = in_array('erp_material_physicals', $existing, true) ? $this->reservedPhysicalQuery()->count() : 0;
        $stalePermissions = in_array('erp_rbac_permissions', $existing, true) ? $this->stalePermissionQuery()->pluck('id')->all() : [];
        $issuedPhysicals = in_array('erp_material_physicals', $existing, true)
            ? DB::table('erp_material_physicals')->where('status', 'ISSUED')->count() : 0;

        $this->info('将删除旧生产数据表 '.count($tables).' 张，清理迁移记录 '.count($ledger).' 条，解除保留表外键 '.count($danglingKeys).' 个。');
        $this->info('将释放旧生产占用的库存锁定：'.count($locks).' 个库存批次；板材预留恢复可用：'.$reservedPhysicals.' 张。');
        $this->info('将删除旧生产权限 '.count($stalePermissions).' 个（含角色授权）。');
        if ($issuedPhysicals > 0) {
            $this->warn("有 {$issuedPhysicals} 张板材已被旧下料领出（在制），不会自动退回库存，如需要请按实际情况做库存调整。");
        }
        if (! $this->option('force')) {
            $this->line('这是预览。确认无误后执行：php artisan erp:production-v2-reset --force');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($locks, $availability, $existing, $stalePermissions): void {
            foreach ($locks as $lock) {
                $balance = DB::table('erp_inventory_balances')->where('id', $lock['balance_id'])->lockForUpdate()->first();
                if (! $balance) continue;
                $locked = $lock['expected'];
                $released = bcsub((string) $balance->quantity_locked, $locked, 8);
                DB::table('erp_inventory_balances')->where('id', $balance->id)->update([
                    'quantity_locked' => $locked,
                    'quantity_available' => $availability->calculate((float) $balance->quantity_on_hand, (float) $locked,
                        (float) $balance->quantity_defective, (float) $balance->quantity_pending),
                    'updated_at' => now(),
                ]);
                $location = DB::table('erp_inventory_location_balances')->where('item_id', $balance->item_id)
                    ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)->lockForUpdate()->first();
                if ($location) {
                    $locationLocked = max(0, (float) bcsub((string) $location->quantity_locked, $released, 8));
                    DB::table('erp_inventory_location_balances')->where('id', $location->id)->update([
                        'quantity_locked' => $locationLocked,
                        'quantity_available' => $availability->calculate((float) $location->quantity_on_hand, $locationLocked,
                            (float) $location->quantity_defective, (float) $location->quantity_pending),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (in_array('erp_material_physicals', $existing, true)) {
                $this->reservedPhysicalQuery()->update(['physical.status' => 'AVAILABLE', 'physical.updated_at' => now()]);
            }
            if (in_array('erp_production_execution_events', $existing, true)) {
                // 只保留工序目录档案的变更记录；旧工单执行事件随旧模块一起清除。
                DB::table('erp_production_execution_events')->where('aggregate_type', 'not like', 'process\_%')->delete();
            }
            if ($stalePermissions) {
                DB::table('erp_rbac_role_permissions')->whereIn('permission_id', $stalePermissions)->delete();
                DB::table('erp_rbac_permissions')->whereIn('id', $stalePermissions)->delete();
            }
            if (in_array('erp_rbac_permissions', $existing, true)) {
                $baseId = DB::table('erp_rbac_permissions')->where('code', 'production.base')->value('id');
                DB::table('erp_rbac_permissions')->where('code', 'production.performance')->update([
                    'name' => '提成模板', 'parent_id' => $baseId, 'path' => '/production/performance/templates',
                    'component' => 'ProductionPerformanceTemplates', 'icon' => 'el-icon-coin', 'sort' => 806, 'updated_at' => now(),
                ]);
                DB::table('erp_rbac_permissions')->where('code', 'production.performance.manage')->update(['name' => '维护提成模板', 'updated_at' => now()]);
                DB::table('erp_rbac_permissions')->where('code', 'production.work_order.publish')->update(['name' => '下达工单', 'updated_at' => now()]);
            }
        }, 3);

        foreach ($danglingKeys as $fk) {
            DB::statement('ALTER TABLE `'.$fk->table_name.'` DROP FOREIGN KEY `'.$fk->name.'`');
        }
        $original = (string) (((array) $connection->selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS v'))['v'] ?? '1');
        try {
            DB::statement('SET SESSION FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
        } finally {
            DB::statement('SET SESSION FOREIGN_KEY_CHECKS = '.($original === '0' ? '0' : '1'));
        }
        if ($ledger) DB::table('migrations')->whereIn('migration', $ledger)->delete();
        $this->info('旧生产表和迁移记录已清理。');

        try {
            $plan = $this->preflight($connection);
            $this->info('结构预检通过（'.$plan['mode'].'）。'.($plan['missing'] ? '待建新表：'.implode('、', $plan['missing']).'，请接着执行 php artisan migrate。' : ''));
        } catch (Throwable $error) {
            $this->error('结构预检未通过：'.$error->getMessage());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /** 只修正被旧生产预留表占用过的库存批次：锁定量回到“现有销售等正式预留”的合计。 */
    private function lockCorrections(array $existing): array
    {
        $balanceIds = [];
        foreach (self::LOCK_TABLES as $table) {
            if (in_array($table, $existing, true)) {
                $balanceIds = array_merge($balanceIds, DB::table($table)->distinct()->pluck('inventory_balance_id')->all());
            }
        }
        $balanceIds = array_values(array_unique(array_map('intval', $balanceIds)));
        if (! $balanceIds) return [];
        $kept = DB::table('erp_inventory_reservations')->whereIn('inventory_balance_id', $balanceIds)
            ->whereIn('reservation_status', ['active', 'partially_released'])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(reservation_snapshot, '$.balance_table')) = 'erp_inventory_balances'")
            ->groupBy('inventory_balance_id')->selectRaw('inventory_balance_id, SUM(reserved_qty) AS qty')->pluck('qty', 'inventory_balance_id');
        $corrections = [];
        foreach (DB::table('erp_inventory_balances')->whereIn('id', $balanceIds)->get(['id', 'quantity_locked']) as $balance) {
            $expected = bcadd((string) ($kept[$balance->id] ?? '0'), '0', 8);
            if (bccomp((string) $balance->quantity_locked, $expected, 8) > 0) {
                $corrections[] = ['balance_id' => (int) $balance->id, 'expected' => $expected];
            }
        }
        return $corrections;
    }

    private function stalePermissionQuery()
    {
        return DB::table('erp_rbac_permissions')->where('code', 'like', 'production.%')->whereNotIn('code', self::KEEP_PERMISSIONS);
    }

    private function reservedPhysicalQuery()
    {
        return DB::table('erp_material_physicals as physical')
            ->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
            ->where('physical.status', 'RESERVED')->where('holding.position_type', 'WAREHOUSE')->where('holding.status', 'ACTIVE');
    }

    /** 有待建扩展表（如提成模板）时按扩展口径预检，否则按完整基线预检。 */
    private function preflight($connection): array
    {
        $definitions = InitialTableMigration::definitions();
        $existing = collect($connection->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'))->pluck('name')->all();
        foreach ($definitions as $table => $definition) {
            if (! in_array($table, $existing, true) && $definition::ESTABLISHED_EXTENSION) {
                return $definition->preflightEstablishedExtension($connection);
            }
        }
        return InitialTableMigration::preflight($connection);
    }
}
