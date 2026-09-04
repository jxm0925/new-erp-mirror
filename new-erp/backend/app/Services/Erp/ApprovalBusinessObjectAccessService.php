<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessObject;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single runtime access gate for registered approval business objects.
 *
 * Button permissions answer "may the user use this approval entry".  This
 * gate additionally answers "may the user see/use this source record" and is
 * deliberately shared by source browsing, manual launch and public submit.
 */
class ApprovalBusinessObjectAccessService
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function canBrowse(ApprovalBusinessObject $object, object $user, array $permissionCodes, bool $isSuperAdmin): bool
    {
        if (!$this->hasViewPermission($object, $permissionCodes, $isSuperAdmin)) return false;
        if ($isSuperAdmin || $this->auth->dataScope($user) === 'all') return true;
        return $this->scopeResolver($object) !== null;
    }

    public function assertCanBrowse(ApprovalBusinessObject $object, object $user, array $permissionCodes, bool $isSuperAdmin): void
    {
        if (!$this->hasViewPermission($object, $permissionCodes, $isSuperAdmin)) {
            throw ValidationException::withMessages(['business_object' => '无权查看该审核来源业务对象。']);
        }
        if (!$isSuperAdmin && $this->auth->dataScope($user) !== 'all' && $this->scopeResolver($object) === null) {
            throw ValidationException::withMessages(['business_object' => '该通用业务对象没有可靠的数据范围解析器，普通用户默认禁止访问。']);
        }
    }

    public function applyVisibleScope(Builder $query, ApprovalBusinessObject $object, object $user, array $permissionCodes, bool $isSuperAdmin): Builder
    {
        $this->assertCanBrowse($object, $user, $permissionCodes, $isSuperAdmin);
        if ($isSuperAdmin || $this->auth->dataScope($user) === 'all') return $query;

        $resolver = $this->scopeResolver($object);
        if (!$resolver) return $query->whereRaw('1 = 0');
        [$field, $valueType] = $resolver;
        $scope = $this->auth->dataScope($user);
        if ($valueType === 'user_id') {
            $ids = $scope === 'department' ? $this->auth->departmentUserIds($user) : [(int) $user->legacy_id];
            return $query->whereIn($field, $ids);
        }
        if ($valueType === 'username') {
            if ($scope === 'self') return $query->where($field, (string) $user->username);
            $names = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $this->auth->departmentUserIds($user))->pluck('username')->filter()->all();
            return $query->whereIn($field, $names ?: ['__DENY__']);
        }
        if ($valueType === 'department_id') {
            $departmentIds = DB::table('erp_department_users')->where('user_legacy_id', $user->legacy_id)->pluck('department_legacy_id')->all();
            return $query->whereIn($field, $departmentIds ?: [-1]);
        }
        return $query->whereRaw('1 = 0');
    }

    public function assertCanAccessRecord(ApprovalBusinessObject $object, int $businessId, object $user, array $permissionCodes, bool $isSuperAdmin): void
    {
        $query = DB::table($object->source_table)->where($object->primary_key, $businessId);
        $this->applyVisibleScope($query, $object, $user, $permissionCodes, $isSuperAdmin);
        if (!$query->exists()) {
            throw ValidationException::withMessages(['business_id' => '业务记录不存在，或当前账号无权访问该记录。']);
        }
    }

    public function assertRecordExists(ApprovalBusinessObject $object, int $businessId): void
    {
        if (!DB::table($object->source_table)->where($object->primary_key, $businessId)->exists()) {
            throw ValidationException::withMessages(['business_id' => '在流程配置的业务数据表中找不到该业务记录。']);
        }
    }

    private function hasViewPermission(ApprovalBusinessObject $object, array $permissionCodes, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) return true;
        $permission = trim((string) $object->view_permission_code);
        return $permission !== '' && in_array($permission, $permissionCodes, true);
    }

    /** @return array{0:string,1:string}|null */
    private function scopeResolver(ApprovalBusinessObject $object): ?array
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $object->source_table)
            ->get(['COLUMN_NAME', 'DATA_TYPE'])->keyBy('COLUMN_NAME');

        foreach (['owner_legacy_id', 'sales_user_legacy_id', 'created_by_legacy_id', 'user_legacy_id', 'submitted_by_legacy_id'] as $field) {
            if ($columns->has($field)) return [$field, 'user_id'];
        }
        foreach (['owner_department_legacy_id', 'department_legacy_id', 'department_id'] as $field) {
            if ($columns->has($field)) return [$field, 'department_id'];
        }
        foreach (['submitted_by', 'created_by'] as $field) {
            if (!$columns->has($field)) continue;
            $type = strtolower((string) data_get($columns->get($field), 'DATA_TYPE'));
            return [$field, in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) ? 'user_id' : 'username'];
        }
        if ($columns->has('owner_username')) return ['owner_username', 'username'];
        return null;
    }
}
