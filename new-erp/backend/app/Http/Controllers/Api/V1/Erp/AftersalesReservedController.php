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

}

