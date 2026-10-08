<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/** Accepted delivery and onsite receipts share the same original stock and production target. */
final class ProductionMaterialReceiptQueryService
{
    public function query()
    {
        return DB::table('erp_material_receipt_lines as receipt_line')
            ->join('erp_material_receipts as receipt', 'receipt.id', '=', 'receipt_line.receipt_id')
            ->leftJoin('erp_material_delivery_lines as delivery_line', 'delivery_line.id', '=', 'receipt_line.delivery_line_id')
            ->join('erp_material_picking_task_lines as pick_line', 'pick_line.id', '=', DB::raw('COALESCE(receipt_line.picking_task_line_id, delivery_line.picking_task_line_id)'))
            ->where('receipt.status', 'CONFIRMED');
    }
}
