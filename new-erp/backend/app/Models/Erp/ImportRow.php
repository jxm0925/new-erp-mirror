<?php

namespace App\Models\Erp;

class ImportRow extends MasterModel
{
    protected $table = 'erp_import_rows';
    protected $casts = ['raw_data' => 'array', 'normalized_data' => 'array'];
    public function batch() { return $this->belongsTo(ImportBatch::class, 'batch_id'); }
}
