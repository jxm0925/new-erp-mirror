<?php

namespace App\Services\Erp;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * 业务员档案：账号的业务身份（能被选成谁），与角色权限（能做什么）、数据范围（能看多少）分离。
 * 订单业务员候选、按业务员看单都以“启用 + 生效期内 + 账号有效”的档案为准；
 * erp_legacy_admin_users.is_sales 只是兼容投影（SSO 默认角色推导等旧口径使用）。
 */
final class BusinessStaffService
{
    public const TABLE = 'erp_business_staff';

    public const TYPES = [
        'sales' => '销售员',
        'production_manager' => '生产负责人',
        'production_worker' => '生产工人',
        'warehouse' => '仓管员',
        'purchase' => '采购员',
        'quality' => '质检员',
    ];

    /**
     * 首次启用某类档案时按旧口径一次性登记，保证升级后各选择器候选不丢：
     * 权限来源排除系统管理员角色；已在单据上任职的人员一律登记。
     */
    private const BACKFILL_PERMISSIONS = [
        'production_manager' => ['production.work_order.edit', 'production.work_order.publish'],
        'production_worker' => ['production.task.execute', 'sales_order.shipment.packing.execute'],
    ];

    private const BACKFILL_ROLES = [
        'warehouse' => ['warehouse_manager'],
        'production_manager' => ['production_manager'],
        'production_worker' => ['production_operator'],
    ];

    /** 限定账号查询为某类有效业务员；$userColumn 为外层账号主键列。 */
    public function applyActive(QueryBuilder $users, string $type, string $userColumn = 'erp_legacy_admin_users.legacy_id'): void
    {
        if (! $this->ready()) {
            // 迁移未执行时退回旧投影，避免业务员候选整体变空。
            if ($type === 'sales') $users->where(str_replace('legacy_id', 'is_sales', $userColumn), true);
            return;
        }
        $today = now()->toDateString();
        $users->whereExists(function ($staff) use ($type, $userColumn, $today): void {
            $staff->selectRaw('1')->from(self::TABLE.' as bs')
                ->whereColumn('bs.user_legacy_id', $userColumn)
                ->where('bs.staff_type', $type)->where('bs.status', 'enabled')
                ->where(fn ($q) => $q->whereNull('bs.effective_from')->orWhere('bs.effective_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('bs.effective_to')->orWhere('bs.effective_to', '>=', $today));
        });
    }

    /** 账号是否为某类有效业务员（账号在职 + 档案启用 + 生效期内）。 */
    public function isActive(int $userId, string $type): bool
    {
        if (! $this->ready()) return true;
        $this->ensureBackfilled();
        $users = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
        $this->applyActive($users, $type);

        return $users->exists();
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public function paginate(array $filters)
    {
        $this->ensureBackfilled();
        $query = DB::table(self::TABLE.' as bs')
            ->leftJoin('erp_legacy_admin_users as u', 'u.legacy_id', '=', 'bs.user_legacy_id')
            ->leftJoin('erp_departments as d', 'd.legacy_id', '=', 'bs.department_legacy_id')
            ->when(! empty($filters['staff_type']), fn ($q) => $q->where('bs.staff_type', $filters['staff_type']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('bs.status', $filters['status']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('bs.department_legacy_id', (int) $filters['department_id']))
            ->when(! empty($filters['keyword']), function ($q) use ($filters): void {
                $keyword = trim((string) $filters['keyword']);
                $q->where(fn ($sub) => $sub->where('u.nickname', 'like', "%{$keyword}%")
                    ->orWhere('u.username', 'like', "%{$keyword}%")->orWhere('u.mobile', 'like', "%{$keyword}%"));
            })
            ->orderBy('bs.staff_type')->orderByDesc('bs.id')
            ->select([
                'bs.*', 'u.username', 'u.nickname', 'u.mobile', 'u.status as account_status', 'u.deleted_at as account_deleted_at',
                'd.name as department_name',
            ]);
        $paginator = $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 10))));
        $paginator->through(fn (object $row): object => $this->present($row));

        return $paginator;
    }

    /** 各类型有效人数，用于页面概览。 */
    public function summary(): array
    {
        $this->ensureBackfilled();
        $counts = DB::table(self::TABLE)->select('staff_type', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('staff_type', 'status')->get();
        return collect(self::TYPES)->map(fn ($label, $type) => [
            'staff_type' => $type, 'label' => $label,
            'enabled' => (int) $counts->where('staff_type', $type)->where('status', 'enabled')->sum('total'),
            'disabled' => (int) $counts->where('staff_type', $type)->where('status', 'disabled')->sum('total'),
        ])->values()->all();
    }

    /** 表单选项：可登记的有效账号（按关键字）与有效部门。 */
    public function options(array $filters): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $users = DB::table('erp_legacy_admin_users')->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])
            ->when($keyword !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('nickname', 'like', "%{$keyword}%")
                ->orWhere('username', 'like', "%{$keyword}%")->orWhere('mobile', 'like', "%{$keyword}%")))
            ->orderByDesc('sort')->orderBy('legacy_id')->limit(50)
            ->get(['legacy_id as id', 'username', 'nickname', 'department_names']);
        $departments = DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')
            ->orderBy('parent_legacy_id')->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id as id', 'name']);

        return [
            'types' => collect(self::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'users' => $users,
            'departments' => $departments,
        ];
    }

    public function save(?int $id, array $payload, object $actor): object
    {
        $data = Validator::make($payload, [
            'user_legacy_id' => ($id ? 'prohibited' : 'required').'|integer|min:1',
            'staff_type' => ($id ? 'prohibited' : 'required').'|string|in:'.implode(',', array_keys(self::TYPES)),
            'department_legacy_id' => 'nullable|integer|min:1',
            'status' => 'required|in:enabled,disabled',
            'effective_from' => 'nullable|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'remark' => 'nullable|string|max:500',
            'expected_version' => ($id ? 'required' : 'nullable').'|integer|min:1',
        ], [
            'user_legacy_id.prohibited' => '已登记的业务员不能更换账号，请新增一条档案。',
            'staff_type.prohibited' => '已登记的业务员不能更换类型，请新增一条档案。',
            'effective_to.after_or_equal' => '失效日期不能早于生效日期。',
        ], [
            'user_legacy_id' => '员工账号', 'staff_type' => '业务员类型', 'department_legacy_id' => '所属部门',
            'status' => '状态', 'effective_from' => '生效日期', 'effective_to' => '失效日期', 'remark' => '备注',
        ])->validate();

        return DB::transaction(function () use ($id, $data, $actor): object {
            $existing = null;
            if ($id) {
                $existing = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404, '业务员档案不存在。');
                abort_unless((int) $existing->business_version === (int) $data['expected_version'], 409, '档案已被其他操作修改，请刷新后重试。');
            } else {
                $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $data['user_legacy_id'])->whereNull('deleted_at')->first();
                abort_unless($user, 422, '员工账号不存在或已删除。');
                abort_unless(in_array(strtolower(trim((string) $user->status)), ['normal', 'active'], true), 422, '停用账号不能登记为业务员。');
                abort_if(DB::table(self::TABLE)->where('user_legacy_id', $data['user_legacy_id'])->where('staff_type', $data['staff_type'])->exists(),
                    422, '该员工已登记为'.self::TYPES[$data['staff_type']].'，请直接编辑原档案。');
            }
            if (! empty($data['department_legacy_id'])) {
                abort_unless(DB::table('erp_departments')->where('legacy_id', $data['department_legacy_id'])->whereNull('deleted_at')->exists(),
                    422, '所属部门不存在或已删除。');
            }
            $values = [
                'department_legacy_id' => $data['department_legacy_id'] ?? null,
                'status' => $data['status'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'remark' => $data['remark'] ?? null,
                'updated_by_legacy_id' => $actor->legacy_id ?? null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table(self::TABLE)->where('id', $id)->update([...$values, 'business_version' => (int) $existing->business_version + 1]);
                $userId = (int) $existing->user_legacy_id;
            } else {
                $id = (int) DB::table(self::TABLE)->insertGetId([
                    ...$values, 'user_legacy_id' => $data['user_legacy_id'], 'staff_type' => $data['staff_type'],
                    'source' => 'manual', 'business_version' => 1, 'created_by_legacy_id' => $actor->legacy_id ?? null, 'created_at' => now(),
                ]);
                $userId = (int) $data['user_legacy_id'];
            }
            $this->refreshProjection($userId);
            $after = $this->find($id);
            $this->audit($id, $existing ? 'update' : 'create', $existing ? (array) $existing : null, (array) $after, $actor);

            return $after;
        });
    }

    public function delete(int $id, array $payload, object $actor): void
    {
        DB::transaction(function () use ($id, $payload, $actor): void {
            $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404, '业务员档案不存在。');
            if (isset($payload['expected_version'])) {
                abort_unless((int) $row->business_version === (int) $payload['expected_version'], 409, '档案已被其他操作修改，请刷新后重试。');
            }
            // 已有业务单据归属的档案只能停用或设置失效日期，保证历史订单与提成可追溯。
            $references = [
                'sales' => [['erp_sales_orders', 'sales_user_legacy_id', '销售订单']],
                'production_manager' => [['erp_mfg_work_orders', 'owner_legacy_id', '生产工单']],
                'production_worker' => [['erp_mfg_task_participants', 'employee_legacy_id', '生产任务']],
                'warehouse' => [['erp_warehouses', 'manager_user_id', '仓库负责人记录']],
            ][$row->staff_type] ?? [];
            foreach ($references as [$table, $column, $label]) {
                abort_if(Schema::hasTable($table) && DB::table($table)->where($column, $row->user_legacy_id)->exists(),
                    422, '该'.self::label($row->staff_type).'已有'.$label.'，不能删除档案，请改为停用或设置失效日期。');
            }
            DB::table(self::TABLE)->where('id', $id)->delete();
            $this->refreshProjection((int) $row->user_legacy_id);
            $this->audit($id, 'delete', (array) $row, null, $actor);
        });
    }

    /** SSO 来源声明为销售时补登记档案；不覆盖本地已停用或已维护的档案。 */
    public function syncSourceSalesFlag(int $userId, bool $isSales): void
    {
        if (! $this->ready()) return;
        if ($isSales) {
            DB::table(self::TABLE)->insertOrIgnore([
                'user_legacy_id' => $userId, 'staff_type' => 'sales', 'department_legacy_id' => $this->primaryDepartment($userId),
                'status' => 'enabled', 'source' => 'sso', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->refreshProjection($userId);
    }

    /** 兼容投影：账号是否为有效销售员。 */
    public function refreshProjection(int $userId): void
    {
        if (! $this->ready()) return;
        $active = DB::table('erp_legacy_admin_users')->where('legacy_id', $userId);
        $this->applyActive($active, 'sales');
        DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->update(['is_sales' => $active->exists()]);
    }

    /**
     * 每类档案只回填一次（以操作日志 backfill:{类型} 为标记，删除档案后不会被重新补回）：
     * 销售员取原 is_sales 标记；生产负责人/生产工人/仓管员取对应内置角色、对应权限持有人及已在单据上任职的人。
     */
    public function ensureBackfilled(): void
    {
        static $done = false;
        if ($done || ! $this->ready()) return;
        $markers = DB::table('erp_operation_logs')->where('module', 'system_management')->where('target_type', 'business_staff_backfill')
            ->pluck('action')->all();
        $pending = array_values(array_diff(array_map(fn ($type) => 'backfill:'.$type, ['sales', 'production_manager', 'production_worker', 'warehouse']), $markers));
        if (! $pending) { $done = true; return; }
        DB::transaction(function () use ($pending): void {
            foreach ($pending as $marker) {
                $type = substr($marker, strlen('backfill:'));
                if (DB::table('erp_operation_logs')->where('module', 'system_management')->where('target_type', 'business_staff_backfill')
                    ->where('action', $marker)->lockForUpdate()->exists()) continue;
                $userIds = $this->backfillCandidates($type);
                foreach ($userIds as $userId) {
                    DB::table(self::TABLE)->insertOrIgnore([
                        'user_legacy_id' => $userId, 'staff_type' => $type, 'department_legacy_id' => $this->primaryDepartment($userId),
                        'status' => 'enabled', 'source' => 'legacy_flag', 'remark' => '升级时按原有角色/任职自动登记',
                        'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('erp_operation_logs')->insert([
                    'module' => 'system_management', 'action' => $marker, 'target_type' => 'business_staff_backfill', 'target_id' => null,
                    'new_snapshot' => json_encode(['staff_type' => $type, 'user_ids' => $userIds]), 'operator_name' => 'system', 'created_at' => now(),
                ]);
            }
        });
        $done = true;
    }

    /** @return array<int, int> */
    private function backfillCandidates(string $type): array
    {
        $ids = collect();
        if ($type === 'sales') {
            $ids = $ids->merge(DB::table('erp_legacy_admin_users')->where('is_sales', true)->pluck('legacy_id'));
        }
        if ($roles = self::BACKFILL_ROLES[$type] ?? null) {
            $ids = $ids->merge(DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                ->whereIn('r.code', $roles)->pluck('ur.user_legacy_id'));
        }
        if ($permissions = self::BACKFILL_PERMISSIONS[$type] ?? null) {
            $ids = $ids->merge(DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('r.enabled', true)->where('p.enabled', true)->where('r.code', '<>', 'admin')->whereIn('p.code', $permissions)
                ->pluck('ur.user_legacy_id'));
        }
        $references = [
            'warehouse' => [['erp_warehouses', 'manager_user_id']],
            'production_manager' => [['erp_mfg_work_orders', 'owner_legacy_id']],
            'production_worker' => [['erp_production_operation_workers', 'employee_legacy_id'], ['erp_mfg_task_participants', 'employee_legacy_id']],
        ][$type] ?? [];
        foreach ($references as [$table, $column]) {
            if (Schema::hasTable($table)) $ids = $ids->merge(DB::table($table)->whereNotNull($column)->distinct()->pluck($column));
        }
        $ids = $ids->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        return $ids ? DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereNull('deleted_at')
            ->orderBy('legacy_id')->pluck('legacy_id')->map(fn ($id) => (int) $id)->all() : [];
    }

    private function find(int $id): object
    {
        $row = DB::table(self::TABLE.' as bs')
            ->leftJoin('erp_legacy_admin_users as u', 'u.legacy_id', '=', 'bs.user_legacy_id')
            ->leftJoin('erp_departments as d', 'd.legacy_id', '=', 'bs.department_legacy_id')
            ->where('bs.id', $id)
            ->first(['bs.*', 'u.username', 'u.nickname', 'u.mobile', 'u.status as account_status', 'u.deleted_at as account_deleted_at', 'd.name as department_name']);

        return $this->present($row);
    }

    private function present(object $row): object
    {
        $today = now()->toDateString();
        $accountActive = $row->account_deleted_at === null && in_array(strtolower(trim((string) $row->account_status)), ['normal', 'active'], true);
        $row->staff_type_label = self::TYPES[$row->staff_type] ?? $row->staff_type;
        $row->is_effective = $accountActive && $row->status === 'enabled'
            && ($row->effective_from === null || $row->effective_from <= $today)
            && ($row->effective_to === null || $row->effective_to >= $today);
        $row->inactive_reason = match (true) {
            ! $accountActive => '账号已停用或删除',
            $row->status !== 'enabled' => '档案已停用',
            $row->effective_from !== null && $row->effective_from > $today => '未到生效日期',
            $row->effective_to !== null && $row->effective_to < $today => '已过失效日期',
            default => null,
        };
        unset($row->account_deleted_at);

        return $row;
    }

    private function primaryDepartment(int $userId): ?int
    {
        $id = DB::table('erp_department_users as du')->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
            ->where('du.user_legacy_id', $userId)->whereNull('d.deleted_at')->orderBy('du.id')->value('d.legacy_id');

        return $id ? (int) $id : null;
    }

    private function audit(int $id, string $action, ?array $before, ?array $after, object $actor): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'system_management', 'action' => $action, 'target_type' => 'business_staff', 'target_id' => $id,
            'old_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'operator_id' => $actor->legacy_id ?? null, 'operator_name' => $actor->nickname ?? $actor->username ?? null,
            'created_at' => now(),
        ]);
    }

    private function ready(): bool
    {
        static $ready = null;
        return $ready ??= Schema::hasTable(self::TABLE);
    }
}
