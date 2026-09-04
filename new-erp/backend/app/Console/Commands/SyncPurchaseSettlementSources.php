<?php

namespace App\Console\Commands;

use App\Models\Erp\PurchaseReceipt;
use App\Services\Erp\PurchaseSettlementSourceApplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPurchaseSettlementSources extends Command
{
    protected $signature = 'erp:sync-purchase-settlement-sources {--receipt_id= : 仅同步指定采购到货单 ID}';

    protected $description = '根据已确认采购到货、质量和退货事实幂等同步采购结算来源';

    public function handle(PurchaseSettlementSourceApplicationService $sources): int
    {
        $query = PurchaseReceipt::query()
            ->where('confirm_status', 'confirmed')
            ->where('settlement_mode', '<>', 'replacement_no_charge')
            ->orderBy('id');
        if ($receiptId = $this->option('receipt_id')) {
            $query->whereKey((int) $receiptId);
        }

        $receiptCount = 0;
        $sourceCount = 0;
        $query->select('id')->chunkById(100, function ($receipts) use ($sources, &$receiptCount, &$sourceCount): void {
            foreach ($receipts as $receipt) {
                $createdOrUpdated = DB::transaction(
                    fn () => $sources->syncReceipt((int) $receipt->id, null, '结算来源同步任务'),
                    5,
                );
                $receiptCount++;
                $sourceCount += count($createdOrUpdated);
            }
        });

        $this->info("已同步 {$receiptCount} 张采购到货单，处理 {$sourceCount} 条采购结算来源。");
        return self::SUCCESS;
    }
}
