<?php

namespace App\Models\Erp;

class SkuItemRelation extends MasterModel
{
    protected $table = 'erp_sku_item_relations';
    protected $casts = ['qty' => 'decimal:8', 'is_primary' => 'boolean', 'is_bundle_item' => 'boolean', 'effective_at' => 'datetime', 'expired_at' => 'datetime'];
    public function sku() { return $this->belongsTo(Sku::class); }
    public function item() { return $this->belongsTo(Item::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function baseRelation() { return $this->belongsTo(self::class, 'base_relation_id'); }
    public function logs() { return $this->hasMany(SkuItemRelationLog::class, 'relation_id'); }
}
