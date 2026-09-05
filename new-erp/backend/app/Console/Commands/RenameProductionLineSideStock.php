<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenameProductionLineSideStock extends Command
{
    protected $signature = 'erp:rename-production-line-side-stock
        {--dry-run : Report only; this is also the default}
        {--apply : Rename current routing configuration to workstation_stock}';

    protected $description = 'Rename current production routing supply configuration from line_side_stock to workstation_stock without rewriting frozen work-order history.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--dry-run 与 --apply 不能同时使用。');
            return self::INVALID;
        }

        $query = DB::table('erp_routing_operation_material_supply_rules')->where('supply_mode', 'line_side_stock');
        $report = [
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'matched' => (clone $query)->count(),
            'updated' => 0,
            'frozen_work_order_snapshots_rewritten' => 0,
        ];
        if ($this->option('apply') && $report['matched'] > 0) {
            $report['updated'] = $query->update([
                'supply_mode' => 'workstation_stock',
                'business_version' => DB::raw('business_version + 1'),
                'updated_at' => now(),
            ]);
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
