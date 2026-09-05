<?php

namespace App\Console\Commands;

use App\Models\Erp\WorkOrder;
use App\Services\Erp\ProductionExecutionFoundationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductionExecutionFoundation extends Command
{
    protected $signature = 'erp:backfill-production-execution
        {--work-order=* : Only inspect the specified work-order ids}
        {--dry-run : Report only; this is also the default}
        {--apply : Create execution facts for eligible work orders}';
    protected $description = 'Report or create the Phase 6B.1 execution foundation without inventing historical execution facts.';

    public function handle(ProductionExecutionFoundationService $foundation): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--dry-run 与 --apply 不能同时使用。');
            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $ids = collect($this->option('work-order'))->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id);
        $query = WorkOrder::query()->where('status', 'RELEASED')->orderBy('id');
        if ($ids->isNotEmpty()) $query->whereIn('id', $ids);
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'scanned' => 0, 'eligible' => 0, 'applied' => 0, 'skipped' => 0, 'exceptions' => []];

        $query->chunkById(100, function ($orders) use (&$report, $apply, $foundation): void {
            foreach ($orders as $order) {
                $report['scanned']++;
                $reasons = $this->ineligibleReasons($order);
                if ($reasons !== []) {
                    $report['skipped']++;
                    $report['exceptions'][] = ['work_order_id' => (int) $order->id, 'work_order_no' => $order->work_order_no, 'reasons' => $reasons];
                    continue;
                }
                $report['eligible']++;
                if (! $apply) continue;
                DB::transaction(function () use ($order, $foundation): void {
                    $locked = WorkOrder::query()->lockForUpdate()->findOrFail($order->id);
                    $reasons = $this->ineligibleReasons($locked);
                    if ($reasons !== []) throw new \RuntimeException(implode('; ', $reasons));
                    $foundation->assertReleaseQuantity($locked, $locked->serial_policy_snapshot);
                    $foundation->initializePublished($locked, $locked->serial_policy_snapshot);
                }, 5);
                $report['applied']++;
            }
        });

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $report['exceptions'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function ineligibleReasons(WorkOrder $order): array
    {
        $reasons = [];
        if (DB::table('erp_production_units')->where('work_order_id', $order->id)->exists()
            || DB::table('erp_production_quantity_operations')->where('work_order_id', $order->id)->exists()
            || DB::table('erp_production_tasks')->where('work_order_id', $order->id)->exists()) $reasons[] = 'execution_facts_already_exist';
        if (! in_array($order->production_execution_mode_snapshot, ['unit', 'quantity'], true)) $reasons[] = 'execution_mode_snapshot_missing';
        if (! is_array($order->serial_policy_snapshot) || ($order->serial_policy_snapshot['production_execution_mode'] ?? null) !== $order->production_execution_mode_snapshot) $reasons[] = 'serial_policy_snapshot_missing';
        $operations = collect((array) data_get($order->routing_snapshot, 'operations', []));
        if ($operations->isEmpty()) $reasons[] = 'routing_snapshot_missing';
        if ($operations->flatMap(fn ($operation) => (array) ($operation['material_supply_rules'] ?? []))->isEmpty()) $reasons[] = 'material_supply_snapshot_missing';
        if (! DB::table('erp_work_order_material_requirements')->where('work_order_id', $order->id)->exists()) $reasons[] = 'material_requirement_snapshot_missing';
        if (DB::table('erp_material_deliveries')->where('work_order_id', $order->id)->exists()
            || DB::table('erp_material_picking_tasks')->where('work_order_id', $order->id)->exists()) $reasons[] = 'historical_material_execution_exists';
        return array_values(array_unique($reasons));
    }
}
