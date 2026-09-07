<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class RbacUserRoleOwnershipService
{
    public const SOURCE_SSO = 'sso';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DEPARTMENT = 'department';

    /**
     * Replace the role owned by the legacy SSO projection while preserving every
     * role that has an independent local/manual owner. The effective pivot stays
     * as the union of all ownership rows because existing authorization queries
     * intentionally read erp_rbac_user_roles.
     */
    public function syncSsoRole(int $legacyId, string $desiredRoleCode, ?string $previousRoleCandidate = null): void
    {
        $desiredRoleId = (int) DB::table('erp_rbac_roles')->where('code', $desiredRoleCode)->value('id');
        if ($desiredRoleId <= 0) {
            throw new \RuntimeException("SSO projected role [{$desiredRoleCode}] is not bootstrapped.");
        }

        $owned = DB::table('erp_rbac_user_role_sources')
            ->where('user_legacy_id', $legacyId)
            ->where('assignment_source', self::SOURCE_SSO)
            ->lockForUpdate()
            ->get();

        // Deployments that already consumed SSO tickets predate ownership rows.
        // Adopt only the single role deterministically inferred from the previous
        // signed identity; unrelated local roles remain untouched.
        if ($owned->isEmpty() && $previousRoleCandidate) {
            $previousRoleId = (int) DB::table('erp_rbac_roles')->where('code', $previousRoleCandidate)->value('id');
            if ($previousRoleId > 0 && DB::table('erp_rbac_user_roles')->where('user_legacy_id', $legacyId)->where('role_id', $previousRoleId)->exists()) {
                $this->addSource($legacyId, $previousRoleId, self::SOURCE_SSO);
                $owned = collect([(object) ['role_id' => $previousRoleId]]);
            }
        }

        foreach ($owned as $assignment) {
            $roleId = (int) $assignment->role_id;
            if ($roleId === $desiredRoleId) continue;
            $this->removeSource($legacyId, $roleId, self::SOURCE_SSO);
        }

        $this->addSource($legacyId, $desiredRoleId, self::SOURCE_SSO);
    }

    public function addManualRole(int $legacyId, int $roleId): void
    {
        $this->addSource($legacyId, $roleId, self::SOURCE_MANUAL);
    }

    public function removeManualRole(int $legacyId, int $roleId): void
    {
        $this->removeSource($legacyId, $roleId, self::SOURCE_MANUAL);
    }

    public function addDepartmentRole(int $legacyId, int $roleId): void
    {
        $this->addSource($legacyId, $roleId, self::SOURCE_DEPARTMENT);
    }

    public function removeDepartmentRole(int $legacyId, int $roleId): void
    {
        $this->removeSource($legacyId, $roleId, self::SOURCE_DEPARTMENT);
    }

    private function addSource(int $legacyId, int $roleId, string $source): void
    {
        DB::table('erp_rbac_user_role_sources')->insertOrIgnore([
            'user_legacy_id' => $legacyId,
            'role_id' => $roleId,
            'assignment_source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_rbac_user_roles')->insertOrIgnore([
            'user_legacy_id' => $legacyId,
            'role_id' => $roleId,
        ]);
    }

    private function removeSource(int $legacyId, int $roleId, string $source): void
    {
        DB::table('erp_rbac_user_role_sources')
            ->where('user_legacy_id', $legacyId)
            ->where('role_id', $roleId)
            ->where('assignment_source', $source)
            ->delete();

        if (! DB::table('erp_rbac_user_role_sources')->where('user_legacy_id', $legacyId)->where('role_id', $roleId)->exists()) {
            DB::table('erp_rbac_user_roles')->where('user_legacy_id', $legacyId)->where('role_id', $roleId)->delete();
        }
    }
}
