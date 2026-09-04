<?php

namespace App\Models\Erp;

class ImportBatch extends MasterModel
{
    protected $table = 'erp_import_batches';
    protected $casts = ['confirmed_at' => 'datetime'];
    public function rows() { return $this->hasMany(ImportRow::class, 'batch_id'); }
}
