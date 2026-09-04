<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;

class AftersalesReservedController extends Controller
{
    public function interfaceContract()
    {
        return response()->json([
            'reserved' => true,
            'message' => '售后和退货接口已预留。正式功能将在订单和工单主流程打通后作为独立阶段开发。',
            'principles' => [
                '售后和退货不混入生产工单主状态',
                '退货入库必须通过库存事务，不直接修改库存余额',
                '售后可后续触发返修工单、换货、补发、退款，但每个动作必须有独立单据和日志',
            ],
            'links' => [
                'sales_order_id',
                'sales_order_line_id',
                'product_id',
                'sku_id',
                'item_id',
                'device_no',
                'batch_no',
                'shipment_id',
                'inventory_transaction_id',
                'production_work_order_id',
            ],
        ]);
    }

    public function indexCases() { return $this->reserved('售后案例列表'); }
    public function storeCase() { return $this->reserved('新建售后案例'); }
    public function showCase(int $id) { return $this->reserved('售后案例详情', $id); }
    public function acceptCase(int $id) { return $this->reserved('受理售后案例', $id); }
    public function resolveCase(int $id) { return $this->reserved('处理售后案例', $id); }
    public function indexReturns() { return $this->reserved('退货单列表'); }
    public function storeReturn() { return $this->reserved('新建退货单'); }
    public function showReturn(int $id) { return $this->reserved('退货单详情', $id); }
    public function receiveReturn(int $id) { return $this->reserved('退货收货', $id); }
    public function postReturn(int $id) { return $this->reserved('退货入库过账', $id); }

    private function reserved(string $name, ?int $id = null)
    {
        return response()->json([
            'reserved' => true,
            'id' => $id,
            'name' => $name,
            'message' => '接口已预留，当前批次不实现售后/退货业务逻辑。',
        ]);
    }
}

