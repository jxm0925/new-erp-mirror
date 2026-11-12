<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TechnicalDrawingRevision extends MasterModel
{
    protected $table = 'erp_technical_drawing_revisions';
    protected $hidden = ['storage_disk', 'storage_path', 'file_hash'];
    protected $casts = ['version_no' => 'integer', 'file_size' => 'integer', 'completed_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime'];

    public function drawing(): BelongsTo { return $this->belongsTo(TechnicalDrawing::class, 'drawing_id'); }
    public function usages(): HasMany { return $this->hasMany(TechnicalDrawingUsage::class, 'revision_id'); }

    protected static function booted(): void
    {
        static::updating(function (self $revision) {
            // 完成事实只追加版本；不能通过修正附件或作者改写已经计入的工作量。
            $confirmation = ['confirmed_at', 'confirmed_by_legacy_id', 'confirmed_by_name', 'confirmation_remark', 'updated_at'];
            if (array_diff(array_keys($revision->getDirty()), $confirmation)) throw new LogicException('已完成图纸不能改写，请登记新版本。');
        });
        static::deleting(fn () => throw new LogicException('已完成图纸版本必须保留。'));
    }
}
