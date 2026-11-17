<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Builder;

class CrmOpportunityFile extends MasterModel
{
    protected $table = 'erp_crm_opportunity_files';
    protected $casts = ['version_no' => 'integer', 'confirmed_at' => 'datetime'];

    public function opportunity() { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function attachment() { return $this->belongsTo(CrmAttachment::class, 'attachment_id'); }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereHas('attachment', fn ($attachment) => $attachment->where('is_active', true));
    }
}
