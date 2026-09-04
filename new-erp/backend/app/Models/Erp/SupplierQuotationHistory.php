<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Model;

class SupplierQuotationHistory extends Model
{
    public $timestamps = false;

    protected $table = 'erp_supplier_quotation_histories';

    protected $guarded = [];

    protected $casts = [
        'quotation_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function quotation()
    {
        return $this->belongsTo(ItemSupplierPrice::class, 'quotation_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
