<?php

namespace App\Services\Erp;

use App\Models\Erp\Location;
use Illuminate\Database\Eloquent\Builder;

final class MasterDataSummaryService
{
    /** 汇总必须与列表使用同一个过滤后的查询，不能对分页结果计数。 */
    public function summarize(string $entity, Builder $query): array
    {
        $base = (clone $query)->toBase()->reorder()->select([]);
        $counts = (clone $base)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $stats = [
            'enabled' => (int) ($counts['enabled'] ?? 0),
            'disabled' => (int) ($counts['disabled'] ?? 0),
            'draft' => (int) ($counts['draft'] ?? 0),
        ];
        if ($entity === 'units') {
            $stats['quantity'] = (clone $base)->where('unit_type', 'quantity')->count();
            $stats['measure'] = (clone $base)->where('unit_type', '<>', 'quantity')->count();
        }
        if ($entity === 'categories') {
            $stats['root'] = (clone $base)->whereNull('parent_id')->count();
            $stats['sub'] = (clone $base)->whereNotNull('parent_id')->count();
        }
        if ($entity === 'items') {
            $stats['cutting'] = (clone $base)->where(fn ($q) => $q->whereIn('cutting_mode', ['sheet', 'length'])
                ->orWhere(fn ($legacy) => $legacy->where(fn ($mode) => $mode->whereNull('cutting_mode')->orWhere('cutting_mode', ''))
                    ->where('is_length_cut_material', true)))->count();
        }
        if ($entity === 'skus') {
            $stats['missing_item'] = (clone $query)->where('order_line_type', 'physical')
                ->whereDoesntHave('itemRelations', fn ($r) => $r->where('status', 'active')->where('is_primary', true)
                    ->whereHas('item', fn ($item) => $item->where('status', 'enabled')))->count();
        }
        return $stats;
    }

    public function locations(int $warehouseId): array
    {
        $query = Location::query()->where('warehouse_id', $warehouseId);
        $stats = $this->summarize('locations', $query);
        $stats['total'] = (clone $query)->count();
        $stats['mixed'] = (clone $query)->where('allow_mixed', true)->count();
        $stats['capacity'] = (string) (clone $query)->sum('standard_capacity');
        foreach (['area', 'aisle', 'rack'] as $field) {
            $stats[$field.'_count'] = (clone $query)->whereNotNull($field)->whereRaw('TRIM('.$field.") <> ''")
                ->distinct()->count(\Illuminate\Support\Facades\DB::raw('TRIM('.$field.')'));
        }
        $stats['areas'] = (clone $query)->whereNotNull('area')->whereRaw("TRIM(area) <> ''")
            ->selectRaw('TRIM(area) AS name, COUNT(*) AS count')->groupByRaw('TRIM(area)')->orderBy('name')->get()->toArray();
        return $stats;
    }
}
