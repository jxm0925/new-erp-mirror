<?php

namespace App\Models\Erp;

class Bom extends MasterModel
{
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
    public function sourceStandardBom() { return $this->belongsTo(self::class, 'source_standard_bom_id'); }
    public function items() { return $this->hasMany(BomItem::class, 'bom_id')->orderBy('line_no'); }
    public function logs() { return $this->hasMany(BomLog::class, 'bom_id')->latest(); }
}
