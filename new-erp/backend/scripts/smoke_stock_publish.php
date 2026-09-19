<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Erp\CuttingRecordService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Support\Facades\DB;

$db = config('database.connections.mysql.database');
if ($db !== 'erp_sdjiantan') { fwrite(STDERR, "refuse $db\n"); exit(1); }

app(RbacBootstrapService::class)->bootstrap();
$admin = DB::table('erp_legacy_admin_users')->where('username', 'admin')->first();
$user = (object) ['legacy_id' => $admin->legacy_id, 'username' => 'admin'];
$itemId = DB::table('erp_items')->where('status', 'enabled')->whereIn('item_type', ['semi_finished', 'finished', 'product'])->orderByDesc('id')->value('id');
if (! $itemId) $itemId = DB::table('erp_items')->where('status', 'enabled')->orderByDesc('id')->value('id');

$svc = app(CuttingRecordService::class);
// permissions: give all permission codes from admin role
$roleId = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
$permIds = DB::table('erp_rbac_role_permissions')->where('role_id', $roleId)->pluck('permission_id');
$permissions = DB::table('erp_rbac_permissions')->whereIn('id', $permIds)->pluck('code')->all();

try {
  $result = $svc->publish([
    'client_command_id' => 'smoke-stock-' . uniqid(),
    'expected_version' => 0,
    'purpose' => 'STOCK',
    'stock_outputs' => [['item_id' => (int) $itemId]],
  ], $user, $permissions, true);
  echo json_encode(['ok' => true, 'item_id' => $itemId, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
  exit(1);
}
