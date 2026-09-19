<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$db = config('database.connections.mysql.database');
if ($db !== 'erp_sdjiantan') { fwrite(STDERR, "refuse $db\n"); exit(1); }

$admin = DB::table('erp_legacy_admin_users')->where('username', 'admin')->first();
if (!$admin) { fwrite(STDERR, "no admin\n"); exit(1); }

$permCode = 'production.cutting.plan';
$permId = DB::table('erp_rbac_permissions')->where('code', $permCode)->value('id');
if (!$permId) {
  $permId = DB::table('erp_rbac_permissions')->insertGetId([
    'code' => $permCode, 'name' => '下料计划/开单', 'type' => 'button', 'enabled' => true, 'sort' => 1,
    'created_at' => now(), 'updated_at' => now(),
  ]);
}
$roleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
if ($roleId) {
  DB::table('erp_rbac_role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permId], []);
  DB::table('erp_rbac_user_roles')->updateOrInsert(['user_legacy_id' => $admin->legacy_id, 'role_id' => $roleId], []);
}
// also ensure claim/start/execution related perms exist on admin role if listed
foreach ([
  'production.cutting.plan',
  'production.cutting.execute',
  'production.cutting.view',
  'production.cutting.issue',
] as $code) {
  $id = DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
  if ($id && $roleId) DB::table('erp_rbac_role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], []);
}

echo json_encode(['ok' => true, 'admin_legacy_id' => $admin->legacy_id, 'perm_id' => $permId, 'role_id' => $roleId], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
