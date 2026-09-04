<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillWorkOrderSources extends Command
{
    protected $signature = 'erp:backfill-work-order-sources {--dry-run : 仅检查，不写入}';
    protected $description = '安全映射历史销售生产工单的统一来源字段，并输出完整性检查';

    public function handle(): int
    {
        if (! Schema::hasColumns('erp_work_orders', ['source_type', 'source_id', 'output_item_id', 'target_routing_operation_id'])) {
            $this->error('Phase 6A 迁移尚未执行。');
            return self::FAILURE;
        }

        $query = DB::table('erp_work_orders as wo')
            ->join('erp_sales_order_production_requirements as d', 'd.id', '=', 'wo.production_demand_id')
            ->leftJoin('erp_sales_orders as so', 'so.id', '=', 'd.sales_order_id')
            ->whereNotNull('wo.production_demand_id')
            ->where(fn ($q) => $q->whereNull('wo.source_id')->orWhereNull('wo.output_item_id'));
        $count = (clone $query)->count();
        $this->info("待映射历史销售工单：{$count} 条。模式：".($this->option('dry-run') ? '只读检查' : '正式回填'));

        if (! $this->option('dry-run') && $count > 0) {
            $query->select(['wo.id', 'd.sales_order_id', 'd.requirement_no', 'd.item_id', 'so.sales_order_no'])
                ->orderBy('wo.id')->chunkById(200, function ($rows): void {
                    DB::transaction(function () use ($rows): void {
                        foreach ($rows as $row) {
                            DB::table('erp_work_orders')->where('id', $row->id)->update([
                                'source_type' => 'sales_order',
                                'source_id' => $row->sales_order_id,
                                'source_no_snapshot' => $row->sales_order_no,
                                'source_title_snapshot' => $row->requirement_no,
                                'output_item_id' => $row->item_id,
                                'updated_at' => now(),
                            ]);
                        }
                    });
                }, 'wo.id', 'id');
        }

        $routeBackfill = DB::table('erp_work_orders')->whereIn('status', ['DRAFT', 'WAIT_RELEASE'])
            ->whereNull('routing_snapshot')->whereNotNull('output_item_id');
        $this->line('待冻结默认路线的未发布工单：'.(clone $routeBackfill)->count().' 条。');
        if (! $this->option('dry-run')) {
            $routeBackfill->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $routing = \App\Models\Erp\ProductionRouting::with(['outputItem', 'operations.operation'])
                        ->where('output_item_id', $row->output_item_id)->where('status', 'active')->where('is_default', true)->first();
                    if (! $routing) continue;
                    DB::table('erp_work_orders')->where('id', $row->id)->update([
                        'production_routing_id' => $routing->id,
                        'routing_version_snapshot' => $routing->version,
                        'routing_snapshot' => json_encode(app(\App\Services\Erp\ProductionMasterDataService::class)->snapshot($routing), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        $targetBackfill = DB::table('erp_work_orders')->where('source_type', 'stock_prebuild')
            ->whereNull('target_routing_operation_id')->whereNotNull('production_routing_id')->whereNotNull('target_operation_id');
        $targetCount = (clone $targetBackfill)->count();
        $ambiguous = 0;
        foreach ((clone $targetBackfill)->get(['id', 'production_routing_id', 'target_operation_id']) as $row) {
            $matches = DB::table('erp_production_routing_operations')->where('routing_id', $row->production_routing_id)->where('operation_id', $row->target_operation_id)->pluck('id');
            if ($matches->count() !== 1) { $ambiguous++; continue; }
            if (! $this->option('dry-run')) DB::table('erp_work_orders')->where('id', $row->id)->update(['target_routing_operation_id' => $matches->first(), 'updated_at' => now()]);
        }
        $this->line("备货目标路线节点待回填：{$targetCount} 条；歧义：{$ambiguous} 条。");

        $invalid = DB::table('erp_work_orders')
            ->where(fn ($q) => $q->whereNotIn('source_type', ['sales_order', 'production_plan', 'trial', 'stock_prebuild'])
                ->orWhere(fn ($x) => $x->where('source_type', 'sales_order')->whereNull('production_demand_id')))
            ->count();
        $this->line("来源完整性异常：{$invalid} 条。");
        $releasedMissing = DB::table('erp_work_orders')->where('status', 'RELEASED')->whereNull('routing_snapshot')->count();
        $this->line("已发布但缺少历史路线快照：{$releasedMissing} 条（不使用当前路线伪造历史）。");
        return $invalid === 0 && $ambiguous === 0 ? self::SUCCESS : self::FAILURE;
    }
}
