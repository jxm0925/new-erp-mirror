<?php

namespace App\Models\Erp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReturnItem extends MasterModel
{
    protected $table = 'erp_sales_return_items';

    protected $casts = [
        'requested_sales_qty' => 'decimal:8',
        'requested_base_qty' => 'decimal:8',
        'received_base_qty' => 'decimal:8',
        'restock_base_qty' => 'decimal:8',
        'pending_base_qty' => 'decimal:8',
        'scrap_base_qty' => 'decimal:8',
        'rejected_base_qty' => 'decimal:8',
        'fulfillment_snapshot' => 'array',
    ];

    /** 关闭只释放未收余量，已收数量按该笔申请冻结的销售单位比例折算。 */
    public static function reservationsForLines(array $lineIds, ?int $exceptReturnId = null): Collection
    {
        $received = 'LEAST(item.requested_sales_qty, COALESCE(ROUND(item.received_base_qty * item.requested_sales_qty / NULLIF(item.requested_base_qty, 0), 8), 0))';
        return DB::table('erp_sales_return_items as item')
            ->join('erp_sales_returns as document', 'document.id', '=', 'item.sales_return_id')
            ->whereIn('item.sales_order_line_id', $lineIds)->where('document.return_status', '!=', 'cancelled')
            ->when($exceptReturnId !== null, fn ($query) => $query->where('document.id', '!=', $exceptReturnId))
            ->select('item.id', 'item.sales_order_line_id', 'item.sales_return_id', 'document.return_status')
            ->selectRaw("$received AS received_sales_qty, CASE WHEN document.return_status = 'closed' THEN $received
                ELSE item.requested_sales_qty END AS reserved_sales_qty")
            ->orderBy('item.id')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get();
    }

    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function orderLine() { return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id'); }
    public function fulfillment() { return $this->belongsTo(SalesOrderFulfillment::class, 'fulfillment_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function baseUnit() { return $this->belongsTo(Unit::class, 'base_unit_id'); }
    public function receiptItems() { return $this->hasMany(SalesReturnReceiptItem::class, 'sales_return_item_id'); }
    public function credit() { return $this->hasOne(SalesReturnItemCredit::class, 'sales_return_item_id'); }
    public function costAllocations() { return $this->hasMany(SalesReturnCostAllocation::class, 'sales_return_item_id'); }
}
