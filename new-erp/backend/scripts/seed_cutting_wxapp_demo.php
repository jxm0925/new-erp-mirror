<?php
/**
 * Commit a real cutting demo chain into erp_sdjiantan for wxapp操作验收.
 * Usage: php scripts/seed_cutting_wxapp_demo.php
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Support/CuttingTestFixtures.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Erp\CuttingTaskExecutionService;

final class CuttingWxappDemoSeeder
{
    use Tests\Support\CuttingTestFixtures;

    public function run(): array
    {
        $db = config('database.connections.mysql.database');
        if ($db !== 'erp_sdjiantan') {
            throw new RuntimeException("Refusing to seed unexpected database [{$db}]");
        }

        $fixture = $this->fixture();
        $issued = $this->issue($fixture);

        $orderId = (int) $fixture['order'];
        $task = DB::table('erp_cutting_tasks')->where('cutting_order_id', $orderId)->orderBy('id')->first();
        $batchId = (int) ($issued['settlement_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $batchId = (int) DB::table('erp_cutting_settlement_batches')->where('cutting_order_id', $orderId)->orderByDesc('id')->value('id');
        }

        // Mint a long-lived demo token for wxapp / API probing.
        $token = $this->demoToken($fixture['user']);

        $marker = [
            'seeded_at' => now()->toDateTimeString(),
            'database' => $db,
            'cutting_order_id' => $orderId,
            'cutting_order_no' => DB::table('erp_cutting_orders')->where('id', $orderId)->value('cutting_order_no'),
            'cutting_task_id' => $task?->id,
            'cutting_task_no' => $task?->task_no,
            'settlement_batch_id' => $batchId,
            'allowed_output_id' => $fixture['allowed'],
            'user_legacy_id' => $fixture['user']->legacy_id,
            'username' => $fixture['user']->username,
            'token' => $token,
            'work_order_id' => $fixture['wo']->id,
            'work_order_no' => $fixture['wo']->work_order_no,
            'consumer_work_order_id' => $fixture['consumerWo']->id,
            'physical_ids' => $fixture['physicals'],
        ];

        $out = storage_path('app/cutting_wxapp_demo.json');
        file_put_contents($out, json_encode($marker, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $marker;
    }

    private function demoToken(object $user): string
    {
        $role = DB::table('erp_rbac_roles')->insertGetId([
            'code' => 'cut-demo-' . Str::uuid(),
            'name' => '下料演示角色',
            'data_scope' => 'all',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (self::PERMISSIONS as $code) {
            $id = DB::table('erp_rbac_permissions')->where('code', $code)->value('id');
            if (! $id) {
                $id = DB::table('erp_rbac_permissions')->insertGetId([
                    'code' => $code,
                    'name' => $code,
                    'type' => 'button',
                    'enabled' => true,
                    'sort' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $user->legacy_id, 'role_id' => $role]);

        $token = 'cutdemo_' . Str::random(48);
        DB::table('erp_auth_tokens')->insert([
            'user_legacy_id' => $user->legacy_id,
            'token_hash' => hash('sha256', $token),
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        return $token;
    }
}

try {
    $marker = (new CuttingWxappDemoSeeder())->run();
    echo json_encode(['ok' => true, 'marker' => $marker], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
