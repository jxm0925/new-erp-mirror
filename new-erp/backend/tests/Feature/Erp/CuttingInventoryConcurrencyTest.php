<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\{CuttingInventoryReservationService, ProductionInternalIssueService};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\{InputStream, Process};
use Tests\Support\{CommittedInsertTracker, CuttingTestFixtures};
use Tests\TestCase;

class CuttingInventoryConcurrencyTest extends TestCase
{
    use CuttingTestFixtures;

    private array $workerRows = [];

    public static function races(): array
    {
        return ['issue-before-release' => ['createIssue', 'release'], 'release-before-issue' => ['release', 'createIssue'],
            'two-issues' => ['createIssue', 'createIssue'], 'cancel-before-dispatch' => ['cancelIssue', 'dispatch'],
            'dispatch-before-cancel' => ['dispatch', 'cancelIssue'], 'two-receives' => ['receive', 'receive']];
    }

    #[DataProvider('races')]
    public function test_competing_inventory_commands_recheck_the_locked_current_version(string $firstAction, string $secondAction): void
    {
        $tracker = new CommittedInsertTracker(); $tracker->listen();
        try {
            $f = $this->fixture();
            $receiver = $this->employee('cut-race-receiver-');
            $this->consumerTask($f, $receiver);
            $posted = $this->warehouseStock($f, '10');
            $reservationId = $posted['reservation_ids'][0];
            $service = app(CuttingInventoryReservationService::class);
            $id = $reservationId; $version = 1;
            $lockTable = $secondAction === 'createIssue' && $firstAction === 'createIssue'
                ? 'erp_production_target_material_requirements' : 'erp_cutting_inventory_reservations';
            $internal = in_array($firstAction, ['cancelIssue', 'dispatch', 'receive'], true);
            if ($internal) {
                $created = $service->createIssue($id, $this->payload(1) + ['quantity' => '10'], $f['user'], self::PERMISSIONS, true);
                $id = $created['internal_issue_task_id'];
                $lockTable = 'erp_production_internal_issue_tasks';
                if ($firstAction === 'receive') {
                    app(ProductionInternalIssueService::class)->dispatch($id, $this->payload(1), $f['user'], self::PERMISSIONS, true);
                    $version = 2;
                }
            }
            $args = fn (string $action): array => ['id' => $id, 'action' => $action, 'lock_table' => $lockTable,
                'user' => (array) ($action === 'receive' ? $receiver : $f['user']), 'permissions' => self::PERMISSIONS,
                'payload' => $this->payload($version) + (in_array($action, ['createIssue', 'release'], true) ? ['quantity' => '10'] : [])
                    + ($action === 'release' ? ['reason' => '并发释放原需求'] : [])];
            [$first, $second] = $this->race($args($firstAction), $args($secondAction));
            $this->assertSame(200, $first['status'], json_encode($first, JSON_UNESCAPED_UNICODE));
            $this->assertSame(409, $second['status'], json_encode($second));
            $reservation = DB::table('erp_cutting_inventory_reservations')->find($reservationId);
            $balance = DB::table('erp_inventory_balances')->find($reservation->inventory_balance_id);
            $issues = DB::table('erp_production_internal_issue_tasks')->where('work_order_id', $f['consumerWo']->id);
            $this->assertSame($firstAction === 'release' ? 0 : 1, (clone $issues)->count());
            $received = $firstAction === 'receive';
            $this->assertSame($received ? '10.00000000' : '0.00000000', $reservation->issued_qty);
            $this->assertSame($received ? '3000.0000' : '0.0000', $reservation->issued_total_cost);
            $this->assertSame(0, bccomp((string) $balance->quantity_on_hand, $received ? '0' : '10', 8));
            $this->assertSame(0, bccomp((string) $balance->quantity_locked, $received || $firstAction === 'release' ? '0' : '10', 8));
            $this->assertSame($received ? 1 : 0, DB::table('erp_inventory_transactions')->where('source_type', 'production_internal_issue')->where('source_id', $id)->count());
            if ($internal) $this->assertSame(match ($firstAction) { 'receive' => 'RECEIVED', 'dispatch' => 'ISSUED', default => 'CANCELLED' }, $issues->value('status'));
            $this->assertSame($received ? '10.00000000' : '0.00000000', DB::table('erp_production_target_material_requirements')->where('id', $f['targetRequirement'])->value('satisfied_base_qty'));
        } finally {
            $tracker->cleanup($this->workerRows);
            if (isset($f)) {
                $this->assertDatabaseMissing('erp_cutting_orders', ['id' => $f['order']]);
                $this->assertDatabaseMissing('erp_work_orders', ['id' => $f['consumerWo']->id]);
                $this->assertDatabaseMissing('erp_items', ['id' => $f['raw']->id]);
            }
        }
    }

    private function race(array $firstArgs, array $secondArgs): array
    {
        $database = (string) config('database.connections.mysql.database');
        $this->assertSame('erp_sdjiantan', strtolower($database));
        $environment = array_merge($_ENV, ['ERP_CUTTING_RACE_DATABASE' => $database, 'APP_ENV' => 'testing', 'DB_DATABASE' => $database]);
        $input = new InputStream();
        $make = fn (array $args, bool $hold) => new Process([PHP_BINARY, base_path('tests/Support/cutting_inventory_race_worker.php'),
            base64_encode(json_encode($args + ['hold' => $hold], JSON_THROW_ON_ERROR))], base_path(), $environment, $hold ? $input : null, 25);
        $first = $make($firstArgs, true); $second = $make($secondArgs, false);
        try {
            $first->start(); $locked = $this->waitEvent($first, 'locked');
            $this->assertGreaterThan(0, $locked['transaction_level']);
            $second->start(); $attempt = $this->waitEvent($second, 'lock_attempt');
            $this->assertNotSame($locked['connection_id'], $attempt['connection_id']);
            usleep(200000);
            $this->assertTrue($second->isRunning(), $second->getOutput().$second->getErrorOutput());
            $this->assertNull($this->event($second, 'result'));
            $input->write("continue\n"); $input->close();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput().$first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput());
            $this->assertNotNull($this->event($second, 'locked'));
            return [$this->event($first, 'result'), $this->event($second, 'result')];
        } finally {
            $input->close();
            if ($first->isRunning()) $first->stop();
            if ($second->isRunning()) $second->stop();
            foreach ([$first, $second] as $process) foreach ($this->events($process) as $event) {
                if ($event['event'] === 'inserted') $this->workerRows[] = ['table' => $event['table'], 'ids' => $event['ids']];
            }
        }
    }

    private function waitEvent(Process $process, string $name): array
    {
        $deadline = microtime(true) + 10;
        do {
            if ($event = $this->event($process, $name)) return $event;
            if (! $process->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('未取得实际锁事件 '.$name.': '.$process->getOutput().$process->getErrorOutput());
    }

    private function event(Process $process, string $name): ?array
    {
        foreach ($this->events($process) as $event) if (($event['event'] ?? '') === $name) return $event;
        return null;
    }

    private function events(Process $process): array
    {
        return array_values(array_filter(array_map(fn ($line) => json_decode($line, true), explode("\n", trim($process->getOutput()))), 'is_array'));
    }
}
