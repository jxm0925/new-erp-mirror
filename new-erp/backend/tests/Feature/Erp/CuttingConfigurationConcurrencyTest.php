<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Unit;
use App\Services\Erp\CuttingConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedInsertTracker;
use Tests\TestCase;

class CuttingConfigurationConcurrencyTest extends TestCase
{
    private array $workerRows = [];

    public function test_two_real_mysql_processes_create_only_one_next_version_draft(): void
    {
        $tracker = new CommittedInsertTracker;
        $tracker->listen();
        try {
            $tag = 'CFG-RACE-'.strtoupper(substr((string) Str::ulid(), -8));
            $user = (object) ['legacy_id' => random_int(100000000, 999999999), 'username' => strtolower($tag)];
            $permissions = ['production.cutting.material_manage'];
            $unit = Unit::create(['unit_code' => $tag.'-U', 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
            $item = Item::create(['item_code' => $tag.'-I', 'item_name' => '并发配置产品', 'item_type' => 'semi_finished', 'unit_id' => $unit->id,
                'is_stock_item' => true, 'is_production_item' => true, 'is_custom_item' => true, 'status' => 'enabled']);
            $service = app(CuttingConfigurationService::class);
            $draft = $service->createDraft(['client_command_id' => $tag.'-CREATE', 'expected_version' => 0, 'item_id' => $item->id,
                'dimensions' => ['length_mm' => '100', 'width_mm' => '50'], 'drawing_reference' => $tag.'-DRAWING',
                'scope_mode' => 'PUBLIC', 'scope_work_order_ids' => []], $user, $permissions, true);
            $published = $service->publish($draft['id'], ['client_command_id' => $tag.'-PUBLISH', 'expected_version' => 1], $user, $permissions, true);

            $args = fn (string $suffix): array => ['id' => $published['id'], 'user' => (array) $user, 'permissions' => $permissions,
                'payload' => ['client_command_id' => $tag.'-'.$suffix, 'expected_version' => 2]];
            [$first,$second] = $this->race($args('VERSION-A'), $args('VERSION-B'));
            $this->assertSame(201, $first['status'], json_encode($first, JSON_UNESCAPED_UNICODE));
            $this->assertSame(409, $second['status'], json_encode($second, JSON_UNESCAPED_UNICODE));
            $this->assertSame('configuration_draft_exists', $second['code']);
            $rows = DB::table('erp_custom_configurations')->where('configuration_no', $published['configuration_no'])->orderBy('version_no')->get();
            $this->assertCount(2, $rows);
            $this->assertSame([1, 2], $rows->pluck('version_no')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(['PUBLISHED', 'DRAFT'], $rows->pluck('status')->all());
            $this->assertSame(1, DB::table('erp_custom_configurations')->where('configuration_no', $published['configuration_no'])->where('version_no', 2)->count());
        } finally {
            $tracker->cleanup($this->workerRows);
            if (isset($item)) {
                $this->assertDatabaseMissing('erp_items', ['id' => $item->id]);
            }
        }
    }

    private function race(array $firstArgs, array $secondArgs): array
    {
        $database = (string) config('database.connections.mysql.database');
        $this->assertSame('erp_sdjiantan', strtolower($database));
        $environment = array_merge($_ENV, ['ERP_CUTTING_CONFIGURATION_RACE_DATABASE' => $database, 'APP_ENV' => 'testing', 'DB_DATABASE' => $database]);
        $input = new InputStream;
        $make = fn (array $args, bool $hold) => new Process([PHP_BINARY, base_path('tests/Support/cutting_configuration_race_worker.php'),
            base64_encode(json_encode($args + ['hold' => $hold], JSON_THROW_ON_ERROR))], base_path(), $environment, $hold ? $input : null, 25);
        $first = $make($firstArgs, true);
        $second = $make($secondArgs, false);
        try {
            $first->start();
            $locked = $this->waitEvent($first, 'locked');
            $this->assertGreaterThan(0, $locked['transaction_level']);
            $second->start();
            $attempt = $this->waitEvent($second, 'lock_attempt');
            $this->assertNotSame($locked['connection_id'], $attempt['connection_id']);
            usleep(200000);
            $this->assertTrue($second->isRunning(), $second->getOutput().$second->getErrorOutput());
            $this->assertNull($this->event($second, 'result'));
            $input->write("continue\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput().$first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput());
            $this->assertNotNull($this->event($second, 'locked'));

            return [$this->event($first, 'result'), $this->event($second, 'result')];
        } finally {
            $input->close();
            if ($first->isRunning()) {
                $first->stop();
            }
            if ($second->isRunning()) {
                $second->stop();
            }
            foreach ([$first, $second] as $process) {
                foreach ($this->events($process) as $event) {
                    if (($event['event'] ?? '') === 'inserted') {
                        $this->workerRows[] = ['table' => $event['table'], 'ids' => $event['ids']];
                    }
                }
            }
        }
    }

    private function waitEvent(Process $process, string $name): array
    {
        $deadline = microtime(true) + 10;
        do {
            if ($event = $this->event($process, $name)) {
                return $event;
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('未取得配置并发锁事件 '.$name.': '.$process->getOutput().$process->getErrorOutput());
    }

    private function event(Process $process, string $name): ?array
    {
        foreach ($this->events($process) as $event) {
            if (($event['event'] ?? '') === $name) {
                return $event;
            }
        }

return null;
    }

    private function events(Process $process): array
    {
        return array_values(array_filter(array_map(fn ($line) => json_decode($line,true),explode("\n",trim($process->getOutput()))),'is_array'));
    }
}
