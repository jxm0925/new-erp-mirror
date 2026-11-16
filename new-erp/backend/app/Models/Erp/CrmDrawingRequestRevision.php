<?php

namespace App\Models\Erp;

use LogicException;

class CrmDrawingRequestRevision extends MasterModel
{
    protected $table = 'erp_crm_drawing_request_revisions';
    protected $casts = ['confirmed_at' => 'datetime'];

    public function request() { return $this->belongsTo(CrmDrawingRequest::class, 'request_id'); }
    public function revision() { return $this->belongsTo(TechnicalDrawingRevision::class, 'revision_id'); }
    public function usage() { return $this->belongsTo(TechnicalDrawingUsage::class, 'usage_id'); }

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            $confirmation = ['confirmed_at', 'confirmed_by_legacy_id', 'confirmed_by_name', 'confirmation_note', 'updated_at'];
            if (array_diff(array_keys($row->getDirty()), $confirmation) || ($row->getOriginal('confirmed_at') && $row->isDirty())) {
                throw new LogicException('已交图和客户确认事实必须保留，请新增版本。');
            }
        });
        static::deleting(fn () => throw new LogicException('已交付的图纸版本必须保留。'));
    }
}
