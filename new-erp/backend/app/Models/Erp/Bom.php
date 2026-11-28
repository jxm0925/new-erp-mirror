<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends MasterModel
{
    use SoftDeletes;

    protected $table = 'erp_boms';
    protected $casts = [
        'is_default' => 'boolean',
        'effective_date' => 'date',
        'expire_date' => 'date',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function sku() { return $this->belongsTo(Sku::class); }
    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
    public function sourceProduct() { return $this->belongsTo(Product::class, 'source_product_id'); }
    public function sourceSku() { return $this->belongsTo(Sku::class, 'source_sku_id'); }
    public function sourceStandardBom() { return $this->belongsTo(self::class, 'source_standard_bom_id')->withTrashed(); }
    public function items() { return $this->hasMany(BomItem::class, 'bom_id')->orderBy('line_no'); }
    public function logs() { return $this->hasMany(BomLog::class, 'bom_id')->latest(); }
    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
    public function operationStandards() { return $this->hasMany(BomOperationStandard::class, 'bom_id'); }
    public function approvalTask()
    {
        return $this->hasOne(ApprovalTask::class, 'business_id')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('business_object_code', 'BOM'))
            ->select(['erp_approval_tasks.id', 'erp_approval_tasks.business_id', 'erp_approval_tasks.business_object_code', 'erp_approval_tasks.task_status', 'erp_approval_tasks.action_status']);
    }
}
