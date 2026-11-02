<?php

namespace App\Console\Commands;

use App\Models\Erp\SalesOrder;
use App\Services\Erp\Manufacturing\CancellationProductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Historical stock conversions are reported, never silently reversed. */
class ReconcileCancelledOrders extends Command
{
    protected $signature = 'erp:reconcile-cancelled-orders {--apply : Repair cancellation projections and pause still-associated open production}';
    protected $description = 'Reconcile cancelled order status and list historical automatic stock conversions';

    public function handle(): int
    {
        $rows = [];
        foreach (SalesOrder::where('order_status', 'cancelled')->orderBy('id')->get() as $order) {
            $historical = DB::table('erp_mfg_work_orders')->where('source_type', 'stock')->where('stock_reason', 'order_released')->where('sales_order_no', $order->sales_order_no)->pluck('work_order_no')->all();
            $rows[] = [$order->sales_order_no, $order->fulfillment_status, implode(', ', $historical) ?: '-'];
            if (!$this->option('apply')) continue;
            DB::transaction(function () use ($order) {
                $locked = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->order_status !== 'cancelled') return;
                if ($locked->fulfillment_status !== 'cancelled') $locked->update(['fulfillment_status' => 'cancelled']);
                $actor = (object) ['legacy_id' => null, 'nickname' => '系统任务', 'username' => '系统任务'];
                app(CancellationProductionService::class)->begin($locked, '历史已取消订单，补充生产处置', $actor);
            }, 5);
        }
        $this->table(['订单', '原交付状态', '历史转备货（仅列出供主管核对）'], $rows);
        $this->info($this->option('apply') ? '取消状态已修正，仍关联的未结工单已进入处置；历史备货及资金记录保留。' : '只读预览；使用 --apply 执行修正。');
        return self::SUCCESS;
    }
}
