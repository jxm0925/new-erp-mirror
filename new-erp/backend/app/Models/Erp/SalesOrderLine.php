<?php

namespace App\Models\Erp;

class SalesOrderLine extends MasterModel
{
    protected $table = 'erp_sales_order_lines';

    protected $casts = [
        'need_pump' => 'boolean',
        'is_customized' => 'boolean',
        'is_special_customized' => 'boolean',
        'configuration_snapshot' => 'array',
        'product_snapshot' => 'array',
        'sku_snapshot' => 'array',
        'item_snapshot' => 'array',
        'bom_snapshot' => 'array',
        'routing_snapshot' => 'array',
        'drawing_snapshot' => 'array',
        'technical_attachment_snapshot' => 'array',
        'inspection_snapshot' => 'array',
        'item_match_snapshot' => 'array',
        'commercial_snapshot' => 'array',
        'fulfillment_factor_snapshot' => 'decimal:8',
        'undetermined_qty' => 'decimal:8',
        'item_base_required_qty' => 'decimal:8',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sku() { return $this->belongsTo(Sku::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function itemBaseUnit() { return $this->belongsTo(Unit::class, 'item_base_unit_id'); }
    public function fulfillments() { return $this->hasMany(SalesOrderFulfillment::class); }
    public function productionRequirements() { return $this->hasMany(SalesOrderProductionRequirement::class); }
    public function attachments() { return $this->hasMany(SalesOrderAttachment::class, 'sales_order_line_id'); }
}
