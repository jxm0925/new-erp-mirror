<?php

namespace App\Events;

use App\Models\Erp\InventoryAlert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryAlertChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;
    public function __construct(public InventoryAlert $alert) {}
    public function broadcastOn(): array { return [new PrivateChannel('inventory-alerts')]; }
    public function broadcastAs(): string { return 'inventory.alert.changed'; }
    public function broadcastWith(): array
    {
        $item = $this->alert->item; $warehouse = $this->alert->warehouse;
        return [
            'alert_id' => $this->alert->id, 'item_id' => $this->alert->item_id,
            'item_code' => $item?->item_code, 'item_name' => $item?->item_name,
            'warehouse_id' => $this->alert->warehouse_id, 'warehouse_name' => $warehouse?->warehouse_name,
            'alert_status' => $this->alert->alert_status, 'severity' => $this->alert->severity,
            'available_qty' => (float) $this->alert->available_qty, 'safety_stock' => (float) ($this->alert->safety_stock_snapshot ?? 0),
            'min_stock' => (float) ($this->alert->min_stock_snapshot ?? 0),
            'suggested_replenishment_qty' => (float) ($this->alert->suggested_replenishment_qty_snapshot ?? 0),
            'occurred_at' => optional($this->alert->last_changed_at)->toDateTimeString(),
        ];
    }
}
