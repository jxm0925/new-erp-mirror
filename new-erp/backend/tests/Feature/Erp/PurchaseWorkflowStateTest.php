<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseOrderItem;
use App\Models\Erp\PurchaseRequest;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\PurchaseWorkflowApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseWorkflowStateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_actions_enforce_status_boundaries_and_write_operator_log(): void
    {
        [$order] = $this->orderFixture();
        $workflow = app(PurchaseWorkflowApplicationService::class);

        $submitted = $workflow->submitOrder($order->id, '采购员张三');
        $this->assertSame('submitted', $submitted->purchase_status);
        $approved = $workflow->approveOrder($order->id, '采购主管李四');
        $this->assertSame('processing', $approved->purchase_status);

        try {
            $workflow->cancelOrder($order->id, '采购员张三');
            $this->fail('已审核订单不应允许取消');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('已审核订单请关闭', $exception->getMessage());
        }

        $closed = $workflow->closeOrder($order->id, '采购主管李四');
        $this->assertSame('closed', $closed->purchase_status);
        $this->assertDatabaseHas('erp_purchase_logs', [
            'target_type' => 'purchase_order',
            'target_id' => $order->id,
            'action' => 'close',
            'operator' => '采购主管李四',
        ]);
    }

    public function test_fully_planned_request_cannot_be_closed(): void
    {
        [, $item] = $this->orderFixture();
        $request = PurchaseRequest::create([
            'request_no' => 'PRQ-WORKFLOW-001',
            'item_id' => $item->id,
            'request_status' => 'confirmed',
            'status' => 'confirmed',
            'request_qty' => 10,
            'planned_qty' => 10,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('已经全部进入采购计划');
        app(PurchaseWorkflowApplicationService::class)->closeRequest($request->id, '采购员张三');
    }

    private function orderFixture(): array
    {
        $unit = Unit::create(['unit_code' => 'EA-WF', 'unit_name' => '件', 'unit_type' => 'quantity', 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'ITEM-WF', 'item_name' => '状态机测试物料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_purchase_item' => true, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => 'SUP-WF', 'supplier_name' => '状态机测试供应商', 'status' => 'enabled', 'approval_status' => 'approved']);
        $order = PurchaseOrder::create([
            'purchase_order_no' => 'PO-WORKFLOW-001',
            'supplier_id' => $supplier->id,
            'purchase_status' => 'draft',
            'audit_status' => 'pending',
            'receipt_status' => 'not_received',
        ]);
        PurchaseOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'order_qty' => 10,
            'remaining_qty' => 10,
            'unit_price' => 20,
            'amount' => 200,
            'purchase_unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor_snapshot' => 1,
        ]);

        return [$order, $item, $supplier];
    }
}
