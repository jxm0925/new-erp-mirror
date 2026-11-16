<?php

namespace App\Services\Erp\Crm;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CrmSalesDelegationService
{
    private const TABLE = 'erp_crm_lead_delegations';

    public function __construct(private readonly CrmAssignmentAccessService $access, private readonly CrmSalesStaffService $staff) {}

    public function activeOwnerIds(object $actor): array
    {
        return $this->activeOwners($actor)->pluck('g.owner_legacy_id')->map(fn ($id) => (int) $id)->all();
    }

    public function delegatesFor(object $actor, int $ownerId, bool $lock = false): bool
    {
        if (!$ownerId || $ownerId === (int) $actor->legacy_id) return false;
        return $this->activeOwners($actor)->where('g.owner_legacy_id', $ownerId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['g.id']) !== null;
    }

    public function activeOwners(object $actor): Builder
    {
        $today = today()->toDateString();
        $query = DB::table(self::TABLE.' as g')->where('g.delegate_legacy_id', $actor->legacy_id)->whereNull('g.ended_at')
            ->where('g.effective_from', '<=', $today)->where('g.effective_to', '>=', $today);
        foreach (['owner', 'delegate'] as $party) {
            $query->join("erp_legacy_admin_users as {$party}_user", "{$party}_user.legacy_id", '=', "g.{$party}_legacy_id")
                ->whereNull("{$party}_user.deleted_at")->whereIn(DB::raw("LOWER(TRIM({$party}_user.status))"), ['normal', 'active'])
                ->join("erp_business_staff as {$party}_staff", "{$party}_staff.user_legacy_id", '=', "g.{$party}_legacy_id")
                ->whereRaw("COALESCE({$party}_staff.department_legacy_id, 0) = g.{$party}_department_id")
                ->where("{$party}_staff.staff_type", 'sales')->where("{$party}_staff.status", 'enabled')
                ->where(fn ($q) => $q->whereNull("{$party}_staff.effective_from")->orWhere("{$party}_staff.effective_from", '<=', $today))
                ->where(fn ($q) => $q->whereNull("{$party}_staff.effective_to")->orWhere("{$party}_staff.effective_to", '>=', $today));
        }
        return $query->select('g.owner_legacy_id');
    }

    private function departments(object $actor): array
    {
        abort_unless($this->access->hasPermission($actor, 'crm.team.grant'), 403, '无权管理团队销售代班。');
        if (app(CrmCustomerAccessService::class)->dataScope($actor, 'crm.team.grant') === 'all') return $this->access->managedDepartments($actor);
        $managed = DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)->where('is_principal', true)->pluck('department_legacy_id')->all();
        return $this->access->expandDepartments($managed);
    }

    private function records(object $actor, bool $self): Builder
    {
        $query = DB::table(self::TABLE);
        if ($self) {
            $this->assertSelfSales($actor);
            return $query->where('owner_legacy_id', $actor->legacy_id);
        }
        $departments = $this->departments($actor);
        return $query->whereIn('owner_department_id', $departments)->whereIn('delegate_department_id', $departments);
    }

    public function assertSelfSales(object $actor): void
    {
        abort_unless($this->access->activeSales((int) $actor->legacy_id), 403, '仅在职销售可以安排本人的销售代班。');
    }

    public function listing(object $actor, array $filters, bool $self = false)
    {
        $today = today()->toDateString();
        $query = $this->records($actor, $self)
            ->select('*')->selectRaw("CASE WHEN ended_at IS NOT NULL THEN 'ended' WHEN effective_from > ? THEN 'scheduled' WHEN effective_to < ? THEN 'expired' ELSE 'active' END AS status", [$today, $today]);
        if ($keyword = trim($filters['keyword'] ?? '')) $query->where(fn ($q) => $q->where('owner_name', 'like', "%{$keyword}%")->orWhere('delegate_name', 'like', "%{$keyword}%"));
        $rows = DB::query()->fromSub($query, 'delegations');
        if (!empty($filters['status'])) $rows->where('status', $filters['status']);
        return $rows->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function create(object $actor, array $data, bool $self = false): int
    {
        if ($self) {
            $this->assertSelfSales($actor);
            abort_if(isset($data['owner_legacy_id']) && (int) $data['owner_legacy_id'] !== (int) $actor->legacy_id, 403, '只能安排本人的销售代班。');
            $data['owner_legacy_id'] = (int) $actor->legacy_id;
        }
        $departments = $self ? null : $this->departments($actor);
        return DB::transaction(function () use ($actor, $data, $departments) {
            $owner = (int) $data['owner_legacy_id'];
            $delegate = (int) $data['delegate_legacy_id'];
            abort_if($owner === $delegate, 422, '请假销售和代班销售不能是同一人。');
            $this->access->lockPeople([$owner, $delegate]);
            $staffDepartments = DB::table('erp_business_staff')->where('staff_type', 'sales')->whereIn('user_legacy_id', [$owner, $delegate])
                ->pluck('department_legacy_id', 'user_legacy_id');
            foreach ([$owner, $delegate] as $id) {
                abort_unless($this->access->activeSales($id, true), 422, '请假销售和代班销售均须为有效销售人员。');
                abort_unless($departments === null || in_array((int) $staffDepartments->get($id), $departments, true), 403, '只能为管理范围内的销售安排代班。');
            }
            $overlap = DB::table(self::TABLE)->where('owner_legacy_id', $owner)->where('delegate_legacy_id', $delegate)
                ->whereNull('ended_at')->where('effective_from', '<=', $data['effective_to'])->where('effective_to', '>=', $data['effective_from'])
                ->lockForUpdate()->first(['id']);
            abort_if($overlap, 422, '这两位销售在所选日期已有代班安排。');
            return DB::table(self::TABLE)->insertGetId($data + [
                'owner_name' => $this->staff->name($owner), 'delegate_name' => $this->staff->name($delegate),
                'owner_department_id' => (int) $staffDepartments[$owner], 'delegate_department_id' => (int) $staffDepartments[$delegate],
                'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $actor->nickname ?: $actor->username,
            ]);
        }, 3);
    }

    public function end(object $actor, int $id, string $reason, bool $self = false): void
    {
        $changed = $this->records($actor, $self)->where('id', $id)->whereNull('ended_at')->where('effective_to', '>=', today()->toDateString())
            ->update(['ended_at' => now(), 'ended_by_legacy_id' => $actor->legacy_id,
                'ended_by_name' => $actor->nickname ?: $actor->username, 'end_reason' => $reason]);
        abort_unless($changed, 422, '该代班已结束、已到期或不在管理范围内。');
    }
}
