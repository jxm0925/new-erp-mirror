<?php

namespace App\Http\Resources\Erp;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderMaterialRequirementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'line_no' => (int) $this->line_no,
            'bom_id' => (int) $this->bom_id,
            'bom_item_id' => (int) $this->bom_item_id,
            'component_item_id' => (int) $this->component_item_id,
            'component' => [
                'code' => $this->component_item_code_snapshot,
                'name' => $this->component_item_name_snapshot,
                'specification' => $this->component_spec_snapshot,
            ],
            'unit' => [
                'id' => $this->unit_id ? (int) $this->unit_id : null,
                'name' => $this->unit_name_snapshot,
                'base_unit_id' => $this->base_unit_id ? (int) $this->base_unit_id : null,
                'base_unit_name' => $this->base_unit_name_snapshot,
            ],
            'formula' => [
                'per_output_qty' => (float) $this->per_output_qty,
                'loss_rate' => (float) $this->loss_rate,
                'fixed_qty' => (float) $this->fixed_qty,
            ],
            'quantity' => [
                'required_qty' => (float) $this->required_qty,
                'base_required_qty' => (float) $this->base_required_qty,
                'issued_qty' => (float) $this->issued_qty,
                'returned_qty' => (float) $this->returned_qty,
                'remaining_qty' => (float) $this->remaining_qty,
                'picked_qty' => (float) $this->picked_qty,
                'delivered_qty' => (float) $this->delivered_qty,
                'received_qty' => (float) $this->received_qty,
                'remaining_to_pick' => max(0, (float) $this->required_qty - (float) $this->picked_qty),
                'remaining_to_deliver' => max(0, (float) $this->picked_qty - (float) $this->delivered_qty),
                'remaining_to_receive' => max(0, (float) $this->delivered_qty - (float) $this->received_qty),
            ],
            'status' => $this->status,
            'business_version' => (int) $this->business_version,
        ];
    }
}
