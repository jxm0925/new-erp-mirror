<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BusinessLine extends MasterModel
{
    protected $table = 'erp_business_lines';

    protected $casts = ['sort_order' => 'integer'];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 'enabled');
    }

    /** @return Collection<int, string> */
    public static function enabledNames(): Collection
    {
        return static::query()->enabled()->orderBy('sort_order')->orderBy('id')->pluck('name');
    }
}
