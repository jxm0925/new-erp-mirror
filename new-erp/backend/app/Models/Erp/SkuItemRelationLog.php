<?php

namespace App\Models\Erp;

class SkuItemRelationLog extends MasterModel
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;
    protected $table = 'erp_sku_item_relation_logs';

    public function sku() { return $this->belongsTo(Sku::class); }
    public function relation() { return $this->belongsTo(SkuItemRelation::class, 'relation_id'); }
    public function oldItem() { return $this->belongsTo(Item::class, 'old_item_id'); }
    public function newItem() { return $this->belongsTo(Item::class, 'new_item_id'); }
}
