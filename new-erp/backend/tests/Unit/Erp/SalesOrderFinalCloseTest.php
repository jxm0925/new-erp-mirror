<?php

namespace Tests\Unit\Erp;

use App\Http\Controllers\Api\V1\Erp\SalesOrderController;
use App\Models\Erp\SalesOrderLine;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesOrderFinalCloseTest extends TestCase
{
    public function test_order_detail_returns_saved_system_item_snapshot(): void
    {
        $line = new SalesOrderLine([
            'line_type' => 'physical',
            'item_match_status' => 'matched',
            'item_snapshot' => [
                'item_code' => 'ITEM-SNAPSHOT-001',
                'item_name' => '订单保存时的系统 Item',
            ],
        ]);

        $result = $this->invokeControllerMethod('orderLineItemProjection', $line);

        $this->assertSame('matched', $result['status']);
        $this->assertSame('ITEM-SNAPSHOT-001', $result['item_code']);
        $this->assertSame('订单保存时的系统 Item', $result['item_name']);
    }

    public function test_service_or_no_delivery_line_displays_no_item_required(): void
    {
        foreach (['service', 'no_delivery'] as $lineType) {
            $result = $this->invokeControllerMethod(
                'orderLineItemProjection',
                new SalesOrderLine(['line_type' => $lineType, 'item_match_status' => 'not_required'])
            );

            $this->assertSame('not_required', $result['status']);
            $this->assertSame('无需 Item', $result['message']);
        }
    }

    public function test_historical_missing_item_is_reported_as_anomaly(): void
    {
        $result = $this->invokeControllerMethod(
            'orderLineItemProjection',
            new SalesOrderLine([
                'line_type' => 'physical',
                'item_match_status' => 'missing',
                'item_match_block_reason' => '默认 Item 已失效',
            ])
        );

        $this->assertSame('abnormal', $result['status']);
        $this->assertSame('默认 Item 已失效', $result['message']);
    }

    public function test_sales_order_visible_enums_are_centralized_in_chinese_dictionary(): void
    {
        $dictionary = file_get_contents(base_path('../frontend/src/utils/erpStatus.js'));

        foreach ([
            "open: '待处理'",
            "create: '创建订单'",
            "update: '编辑订单'",
            "submit_confirmation: '提交确认前检查'",
            "active: '有效'",
            "replaced: '已替换'",
            "deleted: '已删除'",
        ] as $mapping) {
            $this->assertStringContainsString($mapping, $dictionary);
        }
    }

    public function test_legal_pdf_and_image_attachment_mime_rules_are_accepted(): void
    {
        $this->invokeControllerMethod('assertAttachmentFileAllowed', $this->file('pdf', 'application/pdf'), 'contract');
        $this->invokeControllerMethod('assertAttachmentFileAllowed', $this->file('jpg', 'image/jpeg'), 'contract');
        $this->invokeControllerMethod('assertAttachmentFileAllowed', $this->file('png', 'image/png'), 'design_drawing');

        $this->addToAssertionCount(3);
    }

    public function test_contract_text_file_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->invokeControllerMethod('assertAttachmentFileAllowed', $this->file('txt', 'text/plain'), 'contract');
    }

    public function test_extension_and_real_mime_mismatch_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->invokeControllerMethod('assertAttachmentFileAllowed', $this->file('jpg', 'text/plain'), 'contract');
    }

    public function test_attachment_endpoints_keep_button_permissions(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/Erp/SalesOrderController.php'));

        $this->assertStringContainsString("abortUnlessPermission(\$request, 'sales_order.upload_attachment')", $source);
        $this->assertStringContainsString("abortUnlessPermission(\$request, 'sales_order.view_attachment')", $source);
        $this->assertStringContainsString("abortUnlessPermission(\$request, 'sales_order.delete_attachment')", $source);
    }

    public function test_attachment_list_exposes_required_metadata_and_actions(): void
    {
        $source = file_get_contents(base_path('../frontend/src/views/erp/sales/SalesOrderDetail.vue'));

        foreach ([
            'file.file_type',
            'file.file_name',
            'fileSize(file.file_size)',
            'file.uploaded_by',
            'file.uploaded_at',
            'file.version_no',
            'previewAttachment(file)',
            'downloadAttachment(file)',
            'deleteAttachment(file)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_precheck_button_is_close_when_passed_and_return_edit_when_blocked(): void
    {
        $source = file_get_contents(base_path('../frontend/src/views/erp/sales/SalesOrderDetail.vue'));

        $this->assertStringContainsString("confirmButtonText: passed ? '关闭' : '返回编辑'", $source);
        $this->assertStringContainsString("query: blocked && blocked.field ? { focus: blocked.field } : {}", $source);
        $this->assertStringContainsString('focusRequestedField', file_get_contents(base_path('../frontend/src/views/erp/sales/SalesOrderForm.vue')));
    }

    public function test_precheck_does_not_change_order_or_create_downstream_records(): void
    {
        $source = file_get_contents(app_path('Services/Erp/SalesOrderDraftService.php'));
        $method = $this->methodSource($source, 'public function precheckConfirmation', 'private function assertDraftDeletable');

        $this->assertStringNotContainsString('->update(', $method);
        $this->assertStringNotContainsString('SalesOrderFulfillment::create', $method);
        $this->assertStringNotContainsString('SalesOrderProductionRequirement::create', $method);
        $this->assertStringNotContainsString('InventoryReservation::create', $method);
        $this->assertStringContainsString("'downstream_created' => false", $method);
    }

    private function invokeControllerMethod(string $methodName, mixed ...$arguments): mixed
    {
        $method = new ReflectionMethod(SalesOrderController::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(app(SalesOrderController::class), ...$arguments);
    }

    private function file(string $extension, string $mime): object
    {
        return new class($extension, $mime)
        {
            public function __construct(
                private readonly string $extension,
                private readonly string $mime,
            ) {
            }

            public function getClientOriginalExtension(): string
            {
                return $this->extension;
            }

            public function getMimeType(): string
            {
                return $this->mime;
            }
        };
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
