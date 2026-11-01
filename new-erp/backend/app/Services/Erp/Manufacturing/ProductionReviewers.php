<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\MfgWorkOrder;
use App\Services\Erp\BusinessStaffService;
use Illuminate\Support\Facades\DB;

/**
 * 报废、不良处置的审核人：工单填了生产负责人就由他审；没填就由业务员档案里任一启用的生产负责人审；
 * 一个生产负责人都没有就不审，员工提交即生效（留痕）。审核人自己提交的直接生效。
 */
final class ProductionReviewers
{
    /** @return array<int, int> 审核人账号；空 = 不需要审核 */
    public static function for(MfgWorkOrder $wo): array
    {
        $staff = app(BusinessStaffService::class);
        if ($wo->owner_legacy_id && $staff->isActive((int) $wo->owner_legacy_id, 'production_manager')) return [(int) $wo->owner_legacy_id];
        $staff->ensureBackfilled();
        $users = DB::table('erp_legacy_admin_users')->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
        $staff->applyActive($users, 'production_manager');
        return $users->pluck('legacy_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** 这个人提交的报废／不良处置要不要等审核。 */
    public static function needsReview(MfgWorkOrder $wo, ?int $actorId): bool
    {
        $ids = self::for($wo);
        return $ids !== [] && ! in_array((int) $actorId, $ids, true);
    }

    /** 这个人能不能审这张工单的报废／不良处置。 */
    public static function canReview(MfgWorkOrder $wo, ?int $actorId): bool
    {
        $ids = self::for($wo);
        return $ids === [] || in_array((int) $actorId, $ids, true);
    }

    public static function names(MfgWorkOrder $wo): string
    {
        $ids = self::for($wo);
        if (! $ids) return '';
        return DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['nickname', 'username'])
            ->map(fn ($u) => $u->nickname ?: $u->username)->implode('、');
    }
}
