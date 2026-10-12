<?php

namespace Tests\Feature\Erp;

use Database\Seeders\ErpAdministratorSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpAdministratorSeederTest extends TestCase
{
    use DatabaseTransactions;

    /** Requires a fully migrated test database; partial schemas must fail the strict guard. */
    public function test_admin_seeder_preserves_all_accounts_and_authorizations_even_with_password_env(): void
    {
        $tables = ['erp_legacy_admin_users', 'erp_rbac_roles', 'erp_rbac_permissions',
            'erp_rbac_user_roles', 'erp_rbac_user_role_sources', 'erp_rbac_role_permissions'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->get()->toJson();
        $old = getenv('ERP_SEED_ADMIN_PASSWORD');
        $oldEnv = $_ENV['ERP_SEED_ADMIN_PASSWORD'] ?? null;
        $oldServer = $_SERVER['ERP_SEED_ADMIN_PASSWORD'] ?? null;
        try {
            putenv('ERP_SEED_ADMIN_PASSWORD=test-only-must-not-create');
            $_ENV['ERP_SEED_ADMIN_PASSWORD'] = $_SERVER['ERP_SEED_ADMIN_PASSWORD'] = 'test-only-must-not-create';
            (new ErpAdministratorSeeder())->run();
            foreach ($tables as $table) $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
        } finally {
            $old === false ? putenv('ERP_SEED_ADMIN_PASSWORD') : putenv('ERP_SEED_ADMIN_PASSWORD='.$old);
            if ($oldEnv === null) unset($_ENV['ERP_SEED_ADMIN_PASSWORD']); else $_ENV['ERP_SEED_ADMIN_PASSWORD'] = $oldEnv;
            if ($oldServer === null) unset($_SERVER['ERP_SEED_ADMIN_PASSWORD']); else $_SERVER['ERP_SEED_ADMIN_PASSWORD'] = $oldServer;
        }
    }
}
