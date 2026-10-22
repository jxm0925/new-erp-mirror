<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 发货与作业共用命令账本；锁序先订单再发货单，重放不重复库存或工时。 */
final class ShipmentCommandService
{
    public function run(string $action, int $shipmentId, array $payload, object $actor, \Closure $mutation): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '' || strlen($commandId) > 100) throw ValidationException::withMessages(['command' => '缺少有效的操作编号，请刷新后重试。']);
        $hash = hash('sha256', json_encode([$action, $shipmentId, $payload], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $actorId = (int) $actor->legacy_id;
        $replay = function ($row) use ($hash): array {
            if (! hash_equals($row->request_hash, $hash)) throw ValidationException::withMessages(['command' => '同一操作编号不能用于不同内容。']);
            return json_decode($row->response, true, 512, JSON_THROW_ON_ERROR);
        };
        try {
            return DB::transaction(function () use ($action, $shipmentId, $actorId, $commandId, $hash, $mutation, $replay): array {
                $orderId = $action === 'create_shipment' ? $shipmentId : SalesShipment::query()->whereKey($shipmentId)->value('sales_order_id');
                SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $shipment = $action === 'create_shipment' ? null : SalesShipment::query()->lockForUpdate()->findOrFail($shipmentId);
                $existing = DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $actorId)->where('client_command_id', $commandId)->first();
                if ($existing) return $replay($existing);
                $response = $mutation($shipment);
                DB::table('erp_shipment_packing_commands')->insert(['operator_legacy_id' => $actorId, 'client_command_id' => $commandId,
                    'request_hash' => $hash, 'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $exception) {
            $existing = DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $actorId)->where('client_command_id', $commandId)->first();
            if ($existing) return $replay($existing);
            throw $exception;
        }
    }
}
