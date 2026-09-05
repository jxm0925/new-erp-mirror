<?php

namespace App\Models\Erp;

class SalesOrder extends MasterModel
{
    protected $table = 'erp_sales_orders';

    protected $casts = [
        'order_flags' => 'array',
        'crm_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'shipping_snapshot' => 'array',
        'logistics_snapshot' => 'array',
        'legacy_payload' => 'array',
        // ERP order dates are business-local values. Serializing them as ISO UTC
        // silently shifts the date in browser date pickers and creates false
        // fulfillment diffs during a confirmed-order edit.
        'order_time' => 'datetime:Y-m-d H:i:s',
        'order_date' => 'date:Y-m-d',
        'required_delivery_date' => 'date:Y-m-d',
        'delay_date' => 'date:Y-m-d',
        'is_urgent' => 'boolean',
        'quickly' => 'boolean',
        'is_customized' => 'boolean',
        'is_special_customized' => 'boolean',
        'is_delay' => 'boolean',
        'delay' => 'boolean',
        'need_pump' => 'boolean',
        'is_printable' => 'boolean',
        'is_invoice' => 'boolean',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'shipping_address_snapshot' => 'array',
        'funding_policy_snapshot' => 'array',
        'payment_terms_snapshot' => 'array',
        'channel_ordered_at' => 'datetime:Y-m-d H:i:s',
        'business_version' => 'integer',
        'inventory_locked_at' => 'datetime',
    ];

    public function lines() { return $this->hasMany(SalesOrderLine::class); }
    public function fulfillments() { return $this->hasMany(SalesOrderFulfillment::class); }
    public function productionRequirements() { return $this->hasMany(SalesOrderProductionRequirement::class); }
    public function versions() { return $this->hasMany(SalesOrderVersion::class); }
    public function logs() { return $this->hasMany(SalesOrderLog::class); }
    public function attachments() { return $this->hasMany(SalesOrderAttachment::class); }
    public function salesReturns() { return $this->hasMany(SalesReturn::class); }
    public function shipments() { return $this->hasMany(SalesShipment::class); }
    public function changes() { return $this->hasMany(SalesOrderChange::class); }
    public function changeCandidates() { return $this->hasMany(SalesOrderChangeCandidate::class, 'sales_order_id'); }
    public function salesChannel() { return $this->belongsTo(SalesChannel::class); }
    public function fundingPolicy() { return $this->belongsTo(SalesFundingPolicy::class); }
}
