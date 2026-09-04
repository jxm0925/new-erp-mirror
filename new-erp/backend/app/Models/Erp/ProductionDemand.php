<?php

namespace App\Models\Erp;

/**
 * Domain name for the existing sales production requirement table.
 *
 * The table is intentionally not duplicated: old sales-confirmation rows are
 * already the durable source of production demand and the compatibility model
 * remains available to older sales code during the staged migration.
 */
class ProductionDemand extends SalesOrderProductionRequirement
{
    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'production_demand_id');
    }
}
