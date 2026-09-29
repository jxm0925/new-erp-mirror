<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, WorkOrder};
use Illuminate\Database\Eloquent\Builder;

/** 备货候选与写入共用边界；下料产出通过工序内下料登记核算，不再另建备货流程。 */
final class StockPrebuildEligibilityService
{
    public function items(): Builder
    {
        return Item::query()->where('status', 'enabled')->where('is_production_item', true)
            ->whereRaw("COALESCE(NULLIF(cutting_mode, ''), IF(is_length_cut_material, 'length', 'none')) = 'none'");
    }

    public function assertItems(int ...$itemIds): void
    {
        foreach (array_unique($itemIds) as $itemId) {
            if ($itemId <= 0 || ! $this->items()->whereKey($itemId)->exists()) {
                throw new WorkOrderDomainException('stock_prebuild_cutting_item_forbidden',
                    '请选择已启用的生产产出物料；整板和定长原料应作为用料，不能作为本工序产出。',
                    422, ['item_id' => $itemId]);
            }
        }
    }

    public function assertWorkOrder(WorkOrder $workOrder): void
    {
        if ($workOrder->source_type === 'stock_prebuild') {
            $this->assertItems((int) $workOrder->output_item_id, (int) $workOrder->effective_output_item_id_snapshot);
            $this->assertSnapshot((array) $workOrder->routing_snapshot, (int) $workOrder->target_routing_operation_id);
        }
    }

    public function assertSnapshot(array $snapshot, int $targetNodeId): void
    {
        $operations = collect($snapshot['operations'] ?? []);
        $end = $operations->firstWhere('routing_operation_id', $targetNodeId);
        foreach ($operations as $operation) {
            if ($end && (int) $operation['sequence'] > (int) $end['sequence']) continue;
            if (! empty($operation['output_item_id'])) $this->assertItems((int) $operation['output_item_id']);
        }
    }

}
