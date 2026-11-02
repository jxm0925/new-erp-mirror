<?php

namespace App\Models\Erp;

/** 每次余料申请独立留痕，驳回或撤回后重提新单。 */
class MfgCuttingRemnantReceipt extends MasterModel
{
    protected $table = 'erp_mfg_cutting_remnant_receipts';
    protected $casts = ['requested_lines' => 'array', 'confirmed_lines' => 'array', 'disposition' => 'array',
        'requested_at' => 'datetime', 'confirmed_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        'business_version' => 'integer'];

    public function batch() { return $this->belongsTo(MfgCuttingBatch::class, 'cutting_batch_id'); }
    public function inventoryTransaction() { return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id'); }
}
