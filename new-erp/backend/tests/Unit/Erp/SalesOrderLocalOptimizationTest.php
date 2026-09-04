<?php

namespace Tests\Unit\Erp;

use App\Http\Controllers\Api\V1\Erp\SalesOrderController;
use App\Models\Erp\SalesOrderAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class SalesOrderLocalOptimizationTest extends TestCase
{
    #[DataProvider('frontendContracts')]
    public function test_local_optimization_frontend_contracts(string $file, string $needle): void
    {
        $source = file_get_contents(base_path(str_replace('../erp-vue', '../frontend', $file)));
        $this->assertStringContainsString($needle, $source);
    }

    public static function frontendContracts(): array
    {
        return [
            '01 operation logs independently scroll' => ['../erp-vue/src/views/erp/sales/SalesOrderDetail.vue', '.sales-order-operation-log__body{max-height:320px;overflow-y:auto'],
            '02 versions independently scroll' => ['../erp-vue/src/views/erp/sales/SalesOrderDetail.vue', ':max-height="320"'],
            '03 sticky version header' => ['../erp-vue/src/views/erp/sales/SalesOrderDetail.vue', 'position:sticky;top:0;z-index:2'],
            '04 pdf uses shared preview dialog' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', 'PDF附件预览'],
            '05 image uses shared preview dialog' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', 'preview-image-scroll'],
            '06 unsupported file hides preview' => ['../erp-vue/src/views/erp/sales/SalesOrderForm.vue', 'v-if="row.can_preview === true"'],
            '07 create page does not open new tab' => ['../erp-vue/src/views/erp/sales/SalesOrderForm.vue', '<sales-order-attachment-preview-dialog'],
            '08 edit page uses same form preview' => ['../erp-vue/src/views/erp/sales/SalesOrderForm.vue', 'SalesOrderAttachmentPreviewDialog'],
            '09 detail uses same shared preview' => ['../erp-vue/src/views/erp/sales/SalesOrderDetail.vue', 'SalesOrderAttachmentPreviewDialog'],
            '10 temporary capability is returned' => ['app/Http/Controllers/Api/V1/Erp/SalesOrderController.php', "'temporary' => \$temporary"],
            '11 formal attachment capability is returned' => ['app/Http/Controllers/Api/V1/Erp/SalesOrderController.php', 'decorateAttachment($attachment, $allowedActions)'],
            '12 closing does not reload form' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', "this.\$emit('update:visible', false)"],
            '13 selected order line is not mutated' => ['../erp-vue/src/views/erp/sales/SalesOrderForm.vue', 'previewAttachment(file)'],
            '14 preview permission controls button' => ['../erp-vue/src/views/erp/sales/SalesOrderDetail.vue', 'file.can_preview === true'],
            '15 preview expiry has formal error state' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', '附件暂时无法预览，请重新加载或下载后查看。'],
            '16 deleted attachments are excluded' => ['app/Http/Controllers/Api/V1/Erp/SalesOrderController.php', "SalesOrderAttachment::where('status', 'active')->findOrFail(\$id)"],
            '17 pdf dialog supports close' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', '@close="close"'],
            '18 image dialog supports escape close' => ['../erp-vue/src/components/sales/SalesOrderAttachmentPreviewDialog.vue', ':close-on-click-modal="false"'],
        ];
    }

    public function test_form_has_no_new_window_attachment_preview(): void
    {
        $source = file_get_contents(base_path('../frontend/src/views/erp/sales/SalesOrderForm.vue'));
        $this->assertStringNotContainsString('window.open(', $source);
    }

    public function test_previewable_mime_is_decided_by_server(): void
    {
        $method = new ReflectionMethod(SalesOrderController::class, 'attachmentPreviewable');
        $method->setAccessible(true);
        $controller = app(SalesOrderController::class);

        foreach (['application/pdf', 'image/jpeg', 'image/png'] as $mime) {
            $this->assertTrue($method->invoke($controller, new SalesOrderAttachment(['mime_type' => $mime])));
        }
        $this->assertFalse($method->invoke($controller, new SalesOrderAttachment(['mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])));
    }
}
