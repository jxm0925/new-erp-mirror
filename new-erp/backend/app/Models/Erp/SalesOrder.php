<?php

namespace App\Models\Erp;

class SalesOrder extends MasterModel
{
    protected $table = 'erp_sales_orders';

    protected static function booted(): void
    {
        // 销售勾的加急 / 延期自动对应优先级（销售负责人单独调过的不再自动改）；优先级变了，没做完的工单跟着改。
        static::saving(function (SalesOrder $order): void {
            if (! $order->exists || $order->isDirty(['is_urgent', 'is_delay', 'delay'])) \App\Services\Erp\SalesOrderPriorityService::applyFlags($order);
        });
        static::updated(function (SalesOrder $order): void {
            if ($order->wasChanged('priority')) app(\App\Services\Erp\SalesOrderPriorityService::class)->syncWorkOrders($order);
        });
    }

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
        'priority' => 'integer',
        'priority_adjusted_at' => 'datetime:Y-m-d H:i:s',
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
        'payment_method_snapshot' => 'array',
        'channel_ordered_at' => 'datetime:Y-m-d H:i:s',
        'business_version' => 'integer',
        'inventory_locked_at' => 'datetime',
    ];

    public function lines() { return $this->hasMany(SalesOrderLine::class); }
    public function fulfillments() { return $this->hasMany(SalesOrderFulfillment::class); }
    public function versions() { return $this->hasMany(SalesOrderVersion::class); }
    public function logs() { return $this->hasMany(SalesOrderLog::class); }
    public function attachments() { return $this->hasMany(SalesOrderAttachment::class); }
    public function salesReturns() { return $this->hasMany(SalesReturn::class); }
    public function shipments() { return $this->hasMany(SalesShipment::class); }
    public function changes() { return $this->hasMany(SalesOrderChange::class); }
    public function changeCandidates() { return $this->hasMany(SalesOrderChangeCandidate::class, 'sales_order_id'); }
    public function salesChannel() { return $this->belongsTo(SalesChannel::class); }
    public function fundingPolicy() { return $this->belongsTo(SalesFundingPolicy::class); }
    public function paymentMethod() { return $this->belongsTo(PaymentMethod::class); }
}
