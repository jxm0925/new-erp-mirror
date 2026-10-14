<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\Item;
use App\Services\Erp\PurchaseReceiptPostingEligibilityService;
use PHPUnit\Framework\TestCase;

class PurchaseReceiptPartialPostingRuleTest extends TestCase
{
    public function test_quantity_items_may_post_qualified_part_before_defects_are_closed(): void
    {
        $item = new Item(['serial_tracking_mode' => 'none', 'material_management_mode' => 'quantity']);

        $this->assertFalse(PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting($item));
    }

    public function test_serial_and_physical_items_still_require_defects_closed_first(): void
    {
        $serial = new Item(['serial_tracking_mode' => 'required', 'material_management_mode' => 'quantity']);
        $optionalSerial = new Item(['serial_tracking_mode' => 'optional', 'material_management_mode' => 'quantity']);
        $physical = new Item(['serial_tracking_mode' => 'none', 'material_management_mode' => 'physical']);

        $this->assertTrue(PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting($serial));
        $this->assertTrue(PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting($optionalSerial));
        $this->assertTrue(PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting($physical));
        $this->assertTrue(PurchaseReceiptPostingEligibilityService::requiresQualityClosedBeforePosting(null));
    }
}
