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
            'cutting' => $this->cut_length_mm_snapshot === null && ! $this->cutting_requirement_snapshot ? null : [
                'cut_length_mm' => $this->cut_length_mm_snapshot === null ? null : (float) $this->cut_length_mm_snapshot,
                'cutting_quantity_basis' => \App\Services\Erp\FrozenCuttingStockPolicy::isTotalLength($this->cutting_requirement_snapshot ?? []) ? 'total_length' : 'dimensions_pieces',
                'total_length_mm' => $this->cutting_requirement_snapshot['quantity_calculation']['total_length_mm'] ?? null,
                'required_total_length_mm' => $this->cutting_requirement_snapshot['required_total_length_mm'] ?? null,
                'cut_width_mm' => $this->cutting_requirement_snapshot['width_mm'] ?? null,
                'cut_thickness_mm' => $this->cutting_requirement_snapshot['thickness_mm'] ?? null,
                'per_output_piece_qty' => $this->per_output_piece_qty === null ? null : (float) $this->per_output_piece_qty,
                'required_piece_qty' => $this->required_piece_qty === null ? null : (float) $this->required_piece_qty,
                'display' => $this->formatCutRequirement(),
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

    private function formatCutRequirement(): string
    {
        if (\App\Services\Erp\FrozenCuttingStockPolicy::isTotalLength($this->cutting_requirement_snapshot ?? [])) {
            return rtrim(rtrim(bcdiv((string) $this->cutting_requirement_snapshot['required_total_length_mm'], '1000', 8), '0'), '.').'米';
        }
        $length = rtrim(rtrim(number_format((float) $this->cut_length_mm_snapshot, 2, '.', ''), '0'), '.');
        $pieces = rtrim(rtrim(number_format((float) $this->required_piece_qty, 8, '.', ''), '0'), '.');
        $spec = $this->cutting_requirement_snapshot ?? [];
        if (isset($spec['width_mm'], $spec['thickness_mm'])) return "{$length} × {$spec['width_mm']} × {$spec['thickness_mm']} mm × {$pieces}件";
        return "{$length}mm × {$pieces}段";
    }
}
