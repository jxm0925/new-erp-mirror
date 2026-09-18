<?php

namespace Tests\Feature\Erp;

use App\Services\Erp\CuttingDemandService;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedInsertTracker;
use Tests\Support\CuttingTestFixtures;
use Tests\TestCase;

class CuttingDemandConcurrencyTest extends TestCase
{
    use CuttingTestFixtures;

    private array $workerRows = [];

    public function test_two_real_mysql_processes_generate_one_formal_demand(): void
    {
        $tracker = new CommittedInsertTracker;
        $tracker->listen();
        try {
            $fixture = $this->fixture('none', '10', '10', false, false);
            $tag = 'DEMAND-RACE-'.strtoupper(substr((string) Str::ulid(), -8));
            $args = fn (string $suffix): array => [
                'user' => (array) $fixture['user'],
                'permissions' => ['production.cutting.plan'],
                'payload' => [
                    'client_command_id' => $tag.'-'.$suffix,
                    'expected_version' => 0,
                    'source_requirement_id' => $fixture['targetRequirement'],
                    'producer_work_order_id' => $fixture['wo']->id,
                    'producer_stage_id' => $fixture['stage'],
                ],
            ];

            [$first, $second] = $this->race($args('A'), $args('B'));
            $this->assertSame(201, $first['status'], json_encode($first, JSON_UNESCAPED_UNICODE));
            $this->assertSame(201, $second['status'], json_encode($second, JSON_UNESCAPED_UNICODE));
            $this->assertTrue($first['response']['generated_now']);
            $this->assertFalse($second['response']['generated_now']);
            $this->assertSame($first['response']['id'], $second['response']['id']);
            $this->assertSame(1, DB::table('erp_cutting_demands')
                ->where('source_requirement_id', $fixture['targetRequirement'])->count());
            $this->assertSame(1, DB::table('erp_cutting_events')->where('aggregate_type', 'demand')
                ->where('aggregate_id', $first['response']['id'])->where('action', 'generate')->count());
        } finally {
            $tracker->cleanup($this->workerRows);
            if (isset($fixture)) {
                $this->assertDatabaseMissing('erp_items', ['id' => $fixture['output']->id]);
            }
        }
    }

    public function test_two_real_mysql_processes_cannot_overplan_one_demand(): void
    {
        $tracker = new CommittedInsertTracker;
        $tracker->listen();
        try {
            $fixture = $this->fixture('none', '10', '6', false, false);
            $tag = 'PLAN-RACE-'.strtoupper(substr((string) Str::ulid(), -8));
            app(CuttingDemandService::class)->generate([
                'client_command_id' => $tag.'-GENERATE',
                'expected_version' => 0,
                'source_requirement_id' => $fixture['targetRequirement'],
                'producer_work_order_id' => $fixture['wo']->id,
                'producer_stage_id' => $fixture['stage'],
            ], $fixture['user'], ['production.cutting.plan'], true);
            // Ensure both workers contend on an existing numbering row rather
            // than racing to create the first counter for this document type.
            app(DocumentNumberService::class)->next('cutting_order', 'CUT');

            $secondWorkOrder = $fixture['wo']->replicate();
            $secondWorkOrder->work_order_no = $tag.'-WO';
            $secondWorkOrder->save();
            $secondInput = $fixture['inputRequirement']->replicate();
            $secondInput->work_order_id = $secondWorkOrder->id;
            $secondInput->save();
            $secondOperationData = (array) DB::table('erp_production_quantity_operations')
                ->where('id', $fixture['producerOperation'])->first();
            unset($secondOperationData['id']);
            $secondOperationData['work_order_id'] = $secondWorkOrder->id;
            $secondOperation = DB::table('erp_production_quantity_operations')->insertGetId($secondOperationData);
            $secondSupplyData = (array) DB::table('erp_work_order_material_supply_rules')
                ->where('work_order_id', $fixture['wo']->id)
                ->where('material_requirement_id', $fixture['inputRequirement']->id)->sole();
            unset($secondSupplyData['id']);
            $secondSupplyData['work_order_id'] = $secondWorkOrder->id;
            $secondSupplyData['material_requirement_id'] = $secondInput->id;
            $secondSupply = DB::table('erp_work_order_material_supply_rules')->insertGetId($secondSupplyData);
            $secondTargetData = (array) DB::table('erp_production_target_material_requirements')
                ->where('work_order_id', $fixture['wo']->id)
                ->where('material_requirement_id', $fixture['inputRequirement']->id)->sole();
            unset($secondTargetData['id']);
            $secondTargetData['work_order_id'] = $secondWorkOrder->id;
            $secondTargetData['target_id'] = $secondOperation;
            $secondTargetData['material_requirement_id'] = $secondInput->id;
            $secondTargetData['material_supply_rule_snapshot_id'] = $secondSupply;
            DB::table('erp_production_target_material_requirements')->insert($secondTargetData);

            $firstPlan = $fixture['planPayload'];
            $secondPlan = $firstPlan;
            $secondPlan['work_order_id'] = $secondWorkOrder->id;
            $secondPlan['input_material_requirement_id'] = $secondInput->id;
            $args = fn (string $suffix, array $plan): array => [
                'operation' => 'publish',
                'user' => (array) $fixture['user'],
                'permissions' => ['production.cutting.plan'],
                'payload' => [
                    'client_command_id' => $tag.'-'.$suffix,
                    'expected_version' => 0,
                    'plans' => [$plan],
                ],
            ];

            [$first, $second] = $this->race($args('PLAN-A', $firstPlan), $args('PLAN-B', $secondPlan));
            $this->assertSame(201, $first['status'], json_encode($first, JSON_UNESCAPED_UNICODE));
            $this->assertSame(422, $second['status'], json_encode($second, JSON_UNESCAPED_UNICODE));
            $this->assertSame('plan_exceeds_source', $second['code']);
            $demandId = (int) DB::table('erp_cutting_demands')
                ->where('source_requirement_id', $fixture['targetRequirement'])->value('id');
            $this->assertSame('6.00000000', (string) DB::table('erp_cutting_plan_allocations')
                ->where('demand_id', $demandId)->sum('planned_qty'));
            $this->assertSame(1, DB::table('erp_cutting_plan_allocations')->where('demand_id', $demandId)->count());
        } finally {
            $tracker->cleanup($this->workerRows);
            if (isset($fixture)) {
                $this->assertDatabaseMissing('erp_items', ['id' => $fixture['output']->id]);
            }
        }
    }

    private function race(array $firstArgs, array $secondArgs): array
    {
        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_test', $database);
        $environment = array_merge($_ENV, [
            'ERP_CUTTING_DEMAND_RACE_DATABASE' => $database,
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
        ]);
        $input = new InputStream;
        $make = fn (array $args, bool $hold) => new Process([
            PHP_BINARY,
            base_path('tests/Support/cutting_demand_race_worker.php'),
            base64_encode(json_encode($args + ['hold' => $hold], JSON_THROW_ON_ERROR)),
        ], base_path(), $environment, $hold ? $input : null, 25);
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
        $this->fail('未取得需求并发锁事件 '.$name.': '.$process->getOutput().$process->getErrorOutput());
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
        return array_values(array_filter(
            array_map(fn ($line) => json_decode($line, true), explode("\n", trim($process->getOutput()))),
            'is_array'
        ));
    }
}
