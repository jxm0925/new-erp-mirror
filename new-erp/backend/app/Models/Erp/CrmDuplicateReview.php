<?php

namespace App\Models\Erp;

class CrmDuplicateReview extends MasterModel
{
    protected $table = 'erp_crm_duplicate_reviews';

    protected $casts = ['input_snapshot' => 'array', 'matches' => 'array', 'resolved_at' => 'datetime', 'version' => 'integer'];
}
