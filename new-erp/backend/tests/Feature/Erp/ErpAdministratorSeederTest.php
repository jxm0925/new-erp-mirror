<?php

namespace Tests\Feature\Erp;

use Database\Seeders\ErpAdministratorSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpAdministratorSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeded_administrator_can_log_in_without_the_legacy_database(): void
    {
        $adminIds = DB::table('erp_legacy_admin_users')->where('username', 'admin')->pluck('legacy_id');
        DB::table('erp_rbac_user_roles')->whereIn('user_legacy_id', $adminIds)->delete();
        DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $adminIds)->delete();

        (new ErpAdministratorSeeder())->run();

        $admin = DB::table('erp_legacy_admin_users')->where('username', 'admin')->firstOrFail();
        $this->assertSame('normal', $admin->status);
        $this->assertNotEmpty($admin->password_hash);
        $this->assertTrue(DB::table('erp_rbac_user_roles')
            ->join('erp_rbac_roles', 'erp_rbac_user_roles.role_id', '=', 'erp_rbac_roles.id')
            ->where('erp_rbac_user_roles.user_legacy_id', $admin->legacy_id)
            ->where('erp_rbac_roles.code', 'admin')
            ->exists());

        Config::set('database.connections.fastadmin.host', '127.0.0.1');
        Config::set('database.connections.fastadmin.port', 1);
        DB::purge('fastadmin');

        $this->postJson('/api/v1/erp/auth/login', [
            'username' => 'admin',
            'password' => '123456',
        ])->assertOk()
            ->assertJsonPath('user.username', 'admin')
            ->assertJsonStructure(['token', 'permissions']);
    }
}
