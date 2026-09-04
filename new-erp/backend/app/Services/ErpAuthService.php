<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/** Local authentication for the new ERP. No external ERP/database dependency. */
class ErpAuthService
{
    public function authenticate(string $username, string $password): ?array
    {
        if (!Schema::hasTable('erp_legacy_admin_users') || !Schema::hasColumn('erp_legacy_admin_users', 'password_hash')) return null;

        $admin = DB::table('erp_legacy_admin_users')
            ->where('username', trim($username))
            ->whereNotIn('status', ['hidden', 'disabled'])
            ->first();

        if (!$admin || empty($admin->password_hash) || !Hash::check($password, $admin->password_hash)) return null;

        return ['id' => (int) $admin->legacy_id, 'username' => $admin->username, 'nickname' => $admin->nickname, 'email' => $admin->email, 'status' => $admin->status];
    }
}
