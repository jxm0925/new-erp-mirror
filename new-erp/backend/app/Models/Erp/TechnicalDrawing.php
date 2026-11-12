<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalDrawing extends MasterModel
{
    protected $table = 'erp_technical_drawings';
    protected $casts = ['first_confirmed_at' => 'datetime'];

    public function revisions(): HasMany { return $this->hasMany(TechnicalDrawingRevision::class, 'drawing_id'); }
    public function usages(): HasMany { return $this->hasMany(TechnicalDrawingUsage::class, 'drawing_id'); }
}
