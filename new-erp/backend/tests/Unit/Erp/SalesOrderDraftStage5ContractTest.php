<?php

namespace Tests\Unit\Erp;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesOrderDraftStage5ContractTest extends TestCase
{
    #[DataProvider('contractCases')]
    public function test_stage5_draft_contract_is_present(string $relativeFile, string $needle): void
    {
        $contents = file_get_contents(base_path($relativeFile));
        $this->assertIsString($contents);
        $this->assertStringContainsString($needle, $contents);
    }

    public static function contractCases(): array
    {
        return [
            '01 order date schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'order_date'"],
            '02 salesperson schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'salesperson_id'"],
            '03 sales department schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'sales_department_id'"],
            '04 carrier snapshot schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'default_carrier_name_snapshot'"],
            '05 line sort schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'sort_order'"],
            '06 attachment version schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'version_no'"],
            '07 attachment replacement schema' => ['database/migrations/2026_07_25_120000_complete_stage5_sales_order_draft_foundation.php', "'replaced_attachment_id'"],
            '08 draft service exists' => ['app/Services/Erp/SalesOrderDraftService.php', 'class SalesOrderDraftService'],
            '09 generated formal number' => ['app/Services/Erp/SalesOrderDraftService.php', "\$this->numbers->next('sales_order', 'SO')"],
            '10 draft order status' => ['app/Services/Erp/SalesOrderDraftService.php', "'order_status'] = 'draft'"],
            '11 fulfillment progress pending' => ['app/Services/Erp/SalesOrderDraftService.php', "'fulfillment_status'] = 'pending'"],
            '12 production not entered' => ['app/Services/Erp/SalesOrderDraftService.php', "'production_confirm_status'] = 'not_entered'"],
            '13 shipment not shipped' => ['app/Services/Erp/SalesOrderDraftService.php', "'shipment_status'] = 'not_shipped'"],
            '14 snapshot service exists' => ['app/Services/Erp/SalesOrderSnapshotService.php', 'class SalesOrderSnapshotService'],
            '15 selected customer lookup' => ['app/Services/Erp/SalesOrderSnapshotService.php', "findOrFail(\$payload['customer_id'])"],
            '16 customer name snapshot' => ['app/Services/Erp/SalesOrderSnapshotService.php', "'customer_name_snapshot'"],
            '17 contact name snapshot' => ['app/Services/Erp/SalesOrderSnapshotService.php', "'contact_name_snapshot'"],
            '18 contact phone snapshot' => ['app/Services/Erp/SalesOrderSnapshotService.php', "'contact_phone_snapshot'"],
            '19 shipping address snapshot' => ['app/Services/Erp/SalesOrderSnapshotService.php', "'shipping_address_snapshot'"],
            '20 carrier name snapshot' => ['app/Services/Erp/SalesOrderSnapshotService.php', "'default_carrier_name_snapshot'"],
            '21 line service exists' => ['app/Services/Erp/SalesOrderLineService.php', 'class SalesOrderLineService'],
            '22 product sku ownership check' => ['app/Services/Erp/SalesOrderLineService.php', 'SKU 不属于当前 Product'],
            '23 sales unit from sku' => ['app/Services/Erp/SalesOrderLineService.php', "'unit_id' => \$sku->salesUnit->id"],
            '24 sku item matcher used' => ['app/Services/Erp/SalesOrderLineService.php', "\$this->matcher->match"],
            '25 physical item gate' => ['app/Services/Erp/SalesOrderLineService.php', "\$lineType === 'physical'"],
            '26 server calculated amount' => ['app/Services/Erp/SalesOrderLineService.php', "'amount_incl_tax' => \$amountInclTax"],
            '27 stable line uuid' => ['app/Services/Erp/SalesOrderLineService.php', "\$existing?->line_uuid"],
            '28 explicit deleted lines' => ['app/Services/Erp/SalesOrderLineService.php', 'deleteExplicitLines'],
            '29 oss attachment disk' => ['config/erp.php', "env('ERP_ORDER_ATTACHMENT_DISK', 'oss')"],
            '30 attachment soft delete' => ['app/Services/Erp/SalesOrderAttachmentService.php', "'status' => 'deleted'"],
            '31 draft delete route' => ['routes/api.php', "Route::delete('orders/{id}'"],
            '32 confirmation precheck route' => ['routes/api.php', "'submitDraftConfirmation'"],
        ];
    }

    public function test_draft_create_path_does_not_create_downstream_documents(): void
    {
        $source = file_get_contents(app_path('Services/Erp/SalesOrderDraftService.php'));
        $create = $this->methodSource($source, 'public function create', 'public function update');
        $this->assertStringNotContainsString('SalesOrderFulfillment::create', $create);
        $this->assertStringNotContainsString('SalesOrderProductionRequirement::create', $create);
        $this->assertStringNotContainsString('InventoryReservation::create', $create);
    }

    public function test_confirmation_submission_is_precheck_only(): void
    {
        $source = file_get_contents(app_path('Services/Erp/SalesOrderDraftService.php'));
        $submit = $this->methodSource($source, 'public function submitForConfirmation', 'private function split');
        $this->assertStringContainsString("'confirm_status' => 'pending_confirmation'", $submit);
        $this->assertStringNotContainsString('SalesOrderFulfillment::create', $submit);
        $this->assertStringNotContainsString('SalesOrderProductionRequirement::create', $submit);
        $this->assertStringNotContainsString('InventoryReservation::create', $submit);
    }

    private function methodSource(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $end = strpos($source, $endNeedle, $start + strlen($startNeedle));
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($source, $start, $end - $start);
    }
}
