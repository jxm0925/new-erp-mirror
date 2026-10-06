<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** One transaction and replay record for commands spanning several formal documents. */
final class ProductionMaterialCommandService
{
    public function run(string $type, array $payload, object $actor, callable $action, callable $restore): mixed
    {
        $key = trim((string) ($payload['client_command_id'] ?? ''));
        if ($key === '' || strlen($key) > 120) throw new WorkOrderDomainException('validation_error', '提交标识不能为空且不能超过120字。', 422);
        $hash = hash('sha256', json_encode($this->sort($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $actorId = (int) ($actor->legacy_id ?? $actor->id);
        $replay = function ($record) use ($type, $hash, $actorId, $restore) {
            if ($record->command_type !== $type || $record->request_hash !== $hash || (int) $record->initiated_by_legacy_id !== $actorId)
                throw new WorkOrderDomainException('idempotency_hash_conflict', '提交标识已用于其他操作。', 409);
            if ($record->status !== 'succeeded') throw new WorkOrderDomainException('command_processing', '该操作正在处理中，请稍后重试。', 409);
            return $restore((int) $record->result_id);
        };
        try {
            return DB::transaction(function () use ($key, $type, $hash, $actorId, $action, $replay) {
                $record = DB::table('erp_production_material_commands')->where('client_command_id', $key)->lockForUpdate()->first();
                if ($record) return $replay($record);
                DB::table('erp_production_material_commands')->insert([
                    'client_command_id' => $key, 'command_type' => $type, 'aggregate_type' => $type,
                    'request_hash' => $hash, 'status' => 'processing', 'initiated_by_legacy_id' => $actorId,
                    'processing_started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $result = $action();
                $id = is_array($result) ? $result['id'] : $result->id;
                DB::table('erp_production_material_commands')->where('client_command_id', $key)->update([
                    'aggregate_id' => $id, 'result_type' => $type, 'result_id' => $id, 'status' => 'succeeded',
                    'processing_finished_at' => now(), 'updated_at' => now(),
                ]);
                return $result;
            }, 5);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) throw $exception;
            $record = DB::table('erp_production_material_commands')->where('client_command_id', $key)->first();
            if (! $record) throw $exception;
            return $replay($record);
        }
    }

    private function sort(array $payload): array
    {
        foreach ($payload as &$value) if (is_array($value)) $value = $this->sort($value);
        unset($value);
        ksort($payload);
        return $payload;
    }
}
