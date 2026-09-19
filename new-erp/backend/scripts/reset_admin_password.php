<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

$db = config('database.connections.mysql.database');
if ($db !== 'erp_sdjiantan') {
    fwrite(STDERR, "Refusing DB {$db}\n");
    exit(1);
}

if (!Schema::hasTable('erp_legacy_admin_users')) {
    fwrite(STDERR, "missing erp_legacy_admin_users\n");
    exit(1);
}

$users = DB::table('erp_legacy_admin_users')->where('username', 'admin')->get();
$out = ['db' => $db, 'found' => $users->count(), 'actions' => []];
$cols = Schema::getColumnListing('erp_legacy_admin_users');

if ($users->isEmpty()) {
    $legacyId = 1;
    while (DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->exists()) {
        $legacyId++;
    }
    $row = [
        'legacy_id' => $legacyId,
        'username' => 'admin',
        'nickname' => '管理员',
        'email' => 'admin@local',
        'password_hash' => Hash::make('123456'),
        'status' => 'normal',
        'auth_group_names' => json_encode(['Admin group'], JSON_UNESCAPED_UNICODE),
        'legacy_payload' => json_encode(['is_super_admin' => true], JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $row = array_intersect_key($row, array_flip($cols));
    DB::table('erp_legacy_admin_users')->insert($row);
    $out['actions'][] = 'created admin legacy_id=' . $legacyId;
} else {
    foreach ($users as $u) {
        $update = [
            'password_hash' => Hash::make('123456'),
            'updated_at' => now(),
        ];
        if (in_array(($u->status ?? ''), ['hidden', 'disabled'], true) && in_array('status', $cols, true)) {
            $update['status'] = 'normal';
        }
        $update = array_intersect_key($update, array_flip($cols));
        DB::table('erp_legacy_admin_users')->where('legacy_id', $u->legacy_id)->update($update);
        $out['actions'][] = 'reset password for legacy_id=' . $u->legacy_id . ' prev_status=' . ($u->status ?? '');
        $roleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
        if ($roleId) {
            DB::table('erp_rbac_user_roles')->updateOrInsert(
                ['user_legacy_id' => $u->legacy_id, 'role_id' => $roleId],
                []
            );
            $out['actions'][] = 'ensured admin role';
        }
    }
}

$admin = DB::table('erp_legacy_admin_users')->where('username', 'admin')->first();
$out['verify_password'] = $admin ? Hash::check('123456', $admin->password_hash) : false;
$out['status'] = $admin->status ?? null;
$out['legacy_id'] = $admin->legacy_id ?? null;
$out['has_password_hash'] = !empty($admin->password_hash ?? null);
$out['columns'] = $cols;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
