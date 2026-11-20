<?php

namespace App\Models\Erp;

class Location extends MasterModel
{
    protected $table = 'erp_locations';
    protected $casts = ['allow_mixed' => 'boolean'];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function storedItems() { return $this->belongsToMany(Item::class, 'erp_item_storage_locations', 'location_id', 'item_id')->withTimestamps(); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
}
