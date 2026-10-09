<?php

namespace App\Models\Erp;

class Warehouse extends MasterModel
{
    protected $table = 'erp_warehouses';
    protected $casts = ['manager_user_id' => 'integer'];
    public function managementScope(): ?string
    {
        return in_array($this->management_scope, ['factory', 'office'], true) ? $this->management_scope : null;
    }
    public function managerUser()
    {
        return $this->belongsTo(AdminAccount::class, 'manager_user_id', 'legacy_id')
            ->select(['legacy_id', 'username', 'nickname', 'status']);
    }
    public function locations() { return $this->hasMany(Location::class); }
}
