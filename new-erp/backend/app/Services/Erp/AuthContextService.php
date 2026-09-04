<?php

namespace App\Services\Erp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthContextService
{
    public function currentLegacyId(Request $request): ?int
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $hash = hash('sha256', $bearer);
            $row = DB::table('erp_auth_tokens')
                ->where('token_hash', $hash)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();
            if ($row) {
                DB::table('erp_auth_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
                return (int) $row->user_legacy_id;
            }
        }

        $header = $request->header('X-Erp-User-Id');
        if ($header && ctype_digit((string) $header) && $this->isTrustedInternalHeader($request, (string) $header)) {
            return (int) $header;
        }

        $user = $request->user();
        if ($user) {
            foreach (['source_user_id', 'legacy_admin_id', 'id'] as $field) {
                $value = $user->{$field} ?? null;
                if ($value && is_numeric($value)) return (int) $value;
            }
        }

        return null;
    }

    private function isTrustedInternalHeader(Request $request, string $legacyId): bool
    {
        $secret = (string) config('app.erp_internal_auth_secret', env('ERP_INTERNAL_AUTH_SECRET', ''));
        if ($secret === '') return false;

        $timestamp = (string) $request->header('X-Erp-Internal-Timestamp', '');
        $signature = (string) $request->header('X-Erp-Internal-Signature', '');
        if ($timestamp === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) return false;

        $expected = hash_hmac('sha256', $legacyId.'|'.$timestamp, $secret);
        return hash_equals($expected, $signature);
    }

    public function currentUser(Request $request): ?object
    {
        $legacyId = $this->currentLegacyId($request);
        return $legacyId ? DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first() : null;
    }

    public function isSuperAdmin(object $user): bool
    {
        $groups = json_decode(($user->auth_group_names ?? null) ?: '[]', true) ?: [];
        if (($user->username ?? '') === 'admin' || in_array('Admin group', $groups, true)) return true;
        return DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.code', 'admin')
            ->where('r.enabled', true)
            ->exists();
    }

    public function isDepartmentPrincipal(object $user): bool
    {
        return DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)
            ->where('is_principal', true)
            ->exists();
    }

    public function dataScope(object $user): string
    {
        if ($this->isSuperAdmin($user)) return 'all';
        $roleScope = DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_roles as r', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('r.enabled', true)
            ->orderByRaw("FIELD(r.data_scope, 'all', 'department', 'self')")
            ->value('r.data_scope');
        if ($roleScope) return $roleScope;
        if ($this->isDepartmentPrincipal($user)) return 'department';
        return 'self';
    }

    public function departmentUserIds(object $user): array
    {
        $departmentIds = DB::table('erp_department_users')
            ->where('user_legacy_id', $user->legacy_id)
            ->pluck('department_legacy_id')
            ->all();
        if (!$departmentIds) return [(int) $user->legacy_id];

        return DB::table('erp_department_users')
            ->whereIn('department_legacy_id', $departmentIds)
            ->pluck('user_legacy_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function permissionCodes(object $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return DB::table('erp_rbac_permissions')->where('enabled', true)->pluck('code')->all();
        }

        return DB::table('erp_rbac_user_roles as ur')
            ->join('erp_rbac_role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('erp_rbac_permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_legacy_id', $user->legacy_id)
            ->where('p.enabled', true)
            ->pluck('p.code')
            ->unique()
            ->values()
            ->all();
    }
}
