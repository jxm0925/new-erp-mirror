<?php

namespace App\Models\Erp;

class Warehouse extends MasterModel
{
    protected $table = 'erp_warehouses';
    protected $casts = ['manager_user_id' => 'integer'];
    public function managerUser()
    {
        return $this->belongsTo(AdminAccount::class, 'manager_user_id', 'legacy_id')
            ->select(['legacy_id', 'username', 'nickname', 'status']);
    }
    public function locations() { return $this->hasMany(Location::class); }
}
