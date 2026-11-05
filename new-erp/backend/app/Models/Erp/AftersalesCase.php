<?php

namespace App\Models\Erp;

class AftersalesCase extends MasterModel
{
    protected $table = 'erp_aftersales_cases';
    protected $casts = ['customer_snapshot' => 'array', 'sales_order_id' => 'integer',
        'customer_id' => 'integer', 'customer_confirmed' => 'integer', 'lock_version' => 'integer',
        'reported_at' => 'datetime', 'accepted_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function items() { return $this->hasMany(AftersalesCaseItem::class, 'case_id')->orderBy('id'); }
    public function actions() { return $this->hasMany(AftersalesAction::class, 'case_id')->orderBy('id'); }
    public function claims() { return $this->hasMany(AftersalesClaim::class, 'case_id')->orderBy('id'); }
    public function logs() { return $this->hasMany(AftersalesLog::class, 'case_id')->orderBy('id'); }
    public function attachments() { return $this->hasMany(AftersalesAttachment::class, 'case_id')->where('status', 'active')->orderBy('id'); }
    public function reships() { return $this->hasMany(AftersalesReship::class, 'case_id')->orderBy('id'); }
}
