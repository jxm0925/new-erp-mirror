<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Validation\ValidationException;

/** Validate structured BOM cutting dimensions, never free-text specs. */
final class CuttingOutputEligibilityService
{
    public function validateBomLine(Item $item, array $line): void
    {
        $mode = $item->cuttingMode();
        if ($mode === 'sheet') {
            foreach (['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty'] as $field) {
                if (! isset($line[$field]) || bccomp((string) $line[$field], '0', 2) <= 0) {
                    throw ValidationException::withMessages(['items' => "板材 {$item->item_name} 必须维护单件长、宽、厚和每份产出的件数。"]);
                }
            }
        } elseif (isset($line['cut_width_mm']) || isset($line['cut_thickness_mm']) || ! empty($line['allow_cut_rotation'])) {
            throw ValidationException::withMessages(['items' => '只有板材下料可以填写板件宽度、厚度和旋转要求。']);
        }
    }
}
