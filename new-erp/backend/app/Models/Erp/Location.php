<?php

namespace App\Models\Erp;

class Location extends MasterModel
{
    protected $table = 'erp_locations';
    protected $casts = ['allow_mixed' => 'boolean'];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
}
