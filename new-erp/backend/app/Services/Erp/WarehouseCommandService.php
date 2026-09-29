<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mobile network recovery encloses the original use case in one atomic command ledger. */
final class WarehouseCommandService
{
    public function __construct(private readonly WarehouseActionService $actions) {}

    public function run(string $action, int $id, string $commandId, array $payload, object $user, array $permissions, bool $super): array
    {
        $this->actions->authorize($action, $id, $payload, $user, $permissions, $super);
        // Validation failures also need a durable answer: the phone may lose that response
        // just as it can lose a successful response. Authorization remains outside the ledger.
        $validationFailure = null;
        try { $payload = $this->actions->validate($action, $payload); }
        catch (ValidationException $exception) { $validationFailure = $exception; }
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $hash = hash('sha256', json_encode($this->canonical([$action, $id, $actor, $payload]), JSON_THROW_ON_ERROR));
        $recover = function ($row) use ($hash, $actor): array {
            if ((int) $row->actor_legacy_id !== $actor || ! hash_equals($row->request_hash, $hash))
                throw new WorkOrderDomainException('idempotency_hash_conflict', '请求标识已用于其他操作，请先核对原结果。', 409);
            if ($row->status === 'FAILED') return ['failure' => json_decode($row->response, true, 512, JSON_THROW_ON_ERROR)];
            if ($row->status !== 'SUCCEEDED') throw new WorkOrderDomainException('command_processing', '操作正在处理，请稍后核对结果。', 409);
            return json_decode($row->response, true, 512, JSON_THROW_ON_ERROR);
        };
        try {
            $response = DB::transaction(function () use ($action, $id, $commandId, $payload, $user, $permissions, $super, $actor, $hash, $recover, $validationFailure) {
                if ($row = DB::table('erp_warehouse_commands')->where('client_command_id', $commandId)->first()) return $recover($row);
                // The unique key serializes duplicate requests. A savepoint rolls back the
                // domain write while retaining its definitive rejection for lost-response recovery.
                $ledgerId = DB::table('erp_warehouse_commands')->insertGetId(['client_command_id' => $commandId, 'action' => $action,
                    'aggregate_id' => $id, 'actor_legacy_id' => $actor, 'request_hash' => $hash,
                    'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now()]);
                try {
                    if ($validationFailure) throw $validationFailure;
                    $result = DB::transaction(fn () => $this->actions->execute($action, $id, $payload, $commandId, $user, $permissions, $super));
                } catch (WorkOrderDomainException|ValidationException $exception) {
                    $failure = ['error_code' => $exception instanceof WorkOrderDomainException ? $exception->errorCode : 'validation_error',
                        'message' => $exception->getMessage(), 'status' => $exception->status,
                        'details' => $exception instanceof WorkOrderDomainException ? $exception->details : ['errors' => $exception->errors()]];
                    DB::table('erp_warehouse_commands')->where('id', $ledgerId)->update(['status' => 'FAILED',
                        'response' => json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                    return ['failure' => $failure];
                }
                $response = ['action' => $action, 'aggregate_id' => $id, 'result' => json_decode(json_encode($result, JSON_THROW_ON_ERROR), true)];
                DB::table('erp_warehouse_commands')->where('id', $ledgerId)->update(['status' => 'SUCCEEDED',
                    'response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000' || ! ($row = DB::table('erp_warehouse_commands')->where('client_command_id', $commandId)->first())) throw $exception;
            $response = $recover($row);
        }
        if (isset($response['failure'])) {
            $failure = $response['failure'];
            throw new WorkOrderDomainException($failure['error_code'], $failure['message'], $failure['status'], $failure['details']);
        }
        return $this->present($response, $action, $permissions, $super);
    }

    public function result(string $commandId, object $user, array $permissions, bool $super): array
    {
        $row = DB::table('erp_warehouse_commands')->where('client_command_id', $commandId)->where('actor_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0))->first();
        if (! $row) return ['status' => 'NOT_FOUND'];
        // Recheck current permission and data scope even for a successful historical response.
        $this->actions->authorize($row->action, $row->aggregate_id, json_decode($row->request_payload, true), $user, $permissions, $super);
        return ['status' => $row->status, 'response' => in_array($row->status, ['SUCCEEDED', 'FAILED'], true)
            ? $this->present(json_decode($row->response, true), $row->action, $permissions, $super) : null];
    }

    private function present(array $response, string $action, array $permissions, bool $super): array
    {
        return str_starts_with($action, 'sales_') && ! $super && ! in_array('sales_order.amount.view', $permissions, true)
            ? app(SalesAmountVisibilityService::class)->redact($response) : $response;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as &$entry) if (is_array($entry)) $entry = $this->canonical($entry);
        return $value;
    }
}
