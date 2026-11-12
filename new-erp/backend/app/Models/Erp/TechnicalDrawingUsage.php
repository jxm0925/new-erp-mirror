<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalDrawingUsage extends MasterModel
{
    protected $table = 'erp_technical_drawing_usages';
    protected $hidden = ['request_hash'];
    protected $casts = ['active' => 'boolean'];

    public function drawing(): BelongsTo { return $this->belongsTo(TechnicalDrawing::class, 'drawing_id'); }
    public function revision(): BelongsTo { return $this->belongsTo(TechnicalDrawingRevision::class, 'revision_id'); }
}
