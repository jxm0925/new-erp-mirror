<?php

namespace App\Console\Commands;

use App\Services\Erp\InventoryAlertApplicationService;
use Illuminate\Console\Command;

class RecalculateInventoryAlerts extends Command
{
    protected $signature = 'erp:inventory-alerts:recalculate {--item_id=} {--warehouse_id=}';

    protected $description = 'Recalculate persisted active inventory alerts using the unified Alert Service';

    public function handle(InventoryAlertApplicationService $alerts): int
    {
        $itemId = $this->option('item_id') === null ? null : (int) $this->option('item_id');
        $warehouseId = $this->option('warehouse_id') === null ? null : (int) $this->option('warehouse_id');
        $count = $alerts->recalculateActivePolicies($itemId, $warehouseId);

        $this->info("Recalculated {$count} active inventory alert policy scope(s).");

        return self::SUCCESS;
    }
}
