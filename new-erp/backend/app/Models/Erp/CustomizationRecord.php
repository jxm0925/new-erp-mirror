<?php

namespace App\Models\Erp;

class CustomizationRecord extends MasterModel
{
    protected $table = 'erp_customization_records';

    protected $casts = [
        'requirements_json' => 'array',
        'drawing_files_json' => 'array',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function baseSku() { return $this->belongsTo(Sku::class, 'base_sku_id'); }
    public function customSku() { return $this->belongsTo(Sku::class, 'custom_sku_id'); }
    public function baseItem() { return $this->belongsTo(Item::class, 'base_item_id'); }
    public function customItem() { return $this->belongsTo(Item::class, 'custom_item_id'); }
}
