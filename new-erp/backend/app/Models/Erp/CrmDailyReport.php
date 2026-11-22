<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Model;

final class CrmDailyReport extends Model
{
    protected $table = 'erp_crm_daily_reports';
    protected $guarded = ['id'];
    protected $casts = ['body' => 'array', 'analysis' => 'array', 'confirmed_at' => 'datetime', 'version' => 'integer'];
}
