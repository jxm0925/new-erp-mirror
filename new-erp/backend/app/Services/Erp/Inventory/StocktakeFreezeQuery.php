<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryStocktake;
use Illuminate\Support\Facades\DB;

/**
 * 盘点中单据的只读提示：库存、配料、发货页面及小程序工作台共用，避免“忘了完成盘点”导致仓库长期停摆。
 * 超过 OVERDUE_DAYS 天仍在盘点的单据标记为超期，由前端改为红色提示。
 */
class StocktakeFreezeQuery
{
    public const OVERDUE_DAYS = 3;

    /** @return array 分页结构，data 行含仓库、库位（空为整仓）、开始时间、已持续天数、创建人。 */
    public function paginate(array $filters = [], int $page = 1, string $pageName = 'page'): array
    {
        $base = InventoryStocktake::query()->where('stocktake_status', 'counting')
            ->when($filters['management_scope'] ?? null, fn ($q, $scope) => $q->whereHas('warehouse', fn ($w) => $w->where('management_scope', $scope)))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', (int) $id))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where(fn ($l) => $l->whereNull('location_id')->orWhere('location_id', (int) $id)));
        $overdue = (clone $base)->where('created_at', '<', now()->startOfDay()->subDays(self::OVERDUE_DAYS))->exists();
        $result = $base->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name'])
            ->orderBy('created_at')->orderBy('id')
            ->paginate(10, ['id', 'stocktake_no', 'warehouse_id', 'location_id', 'created_by', 'created_at'], $pageName, $page);
        $names = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $result->getCollection()->pluck('created_by')->filter()->unique())
            ->get(['legacy_id', 'nickname', 'username'])->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        $result->getCollection()->transform(function (InventoryStocktake $row) use ($names) {
            $days = $row->created_at ? (int) $row->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
            return [
                'id' => $row->id, 'stocktake_no' => $row->stocktake_no,
                'warehouse_id' => $row->warehouse_id, 'location_id' => $row->location_id,
                'warehouse' => $row->warehouse, 'location' => $row->location,
                'scope_label' => $row->location ? $row->location->location_name : '整仓',
                'started_at' => $row->created_at?->toDateTimeString(), 'days' => $days,
                'overdue' => $days > self::OVERDUE_DAYS,
                'created_by_name' => $names[(int) $row->created_by] ?? null,
            ];
        });
        return $result->toArray() + ['overdue' => $overdue];
    }
}
