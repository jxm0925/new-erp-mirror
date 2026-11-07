<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\ShipmentPackingContent;
use App\Models\Erp\ShipmentPackingOperation;
use App\Models\Erp\ShipmentPackingParticipant;
use App\Models\Erp\ShipmentSerialResult;
use App\Services\Erp\SalesOrderPriorityService;
use Illuminate\Support\Collection;

final class ShipmentBoardQuery
{
    private const LANES = ['water_test' => '试水', 'packing' => '打包', 'loading' => '装车'];

    public function forBoard(array $filters): array
    {
        $openLine = fn ($q) => $q->current()->where('line_status', '!=', 'shipped');
        $openContent = fn ($q) => $q->whereHas('shipmentLine', $openLine)
            ->whereRaw('JSON_CONTAINS(erp_shipment_packing_operations.packing_content_ids, CAST(erp_shipment_packing_contents.id AS JSON))');
        $query = ShipmentPackingOperation::query()
            ->whereIn('status', ['WAITING', 'READY', 'IN_PROGRESS', 'WAIT_QUALITY'])
            ->whereIn('shipment_operation_type_snapshot', array_keys(self::LANES))
            ->whereHas('shipment', fn ($q) => $q->whereIn('shipment_status', ['draft', 'pending_outbound', 'prepared', 'partially_shipped', 'outbound_posted']))
            ->whereHas('package', fn ($q) => $q->whereNotIn('package_status', ['shipped', 'cancelled'])->whereHas('packingContents', $openContent));
        $owners = ShipmentPackingParticipant::query()->whereIn('operation_id', (clone $query)->select('id'))
            ->where('role', 'OWNER')->where('is_active', true)->distinct()->get(['employee_legacy_id', 'employee_name_snapshot'])
            ->map(fn ($owner) => ['id' => (int) $owner->employee_legacy_id, 'name' => $owner->employee_name_snapshot]);

        if ($owner = (int) ($filters['owner_legacy_id'] ?? 0)) $query->where('owner_legacy_id', $owner);
        if (! empty($filters['urgent_only'])) $query->whereHas('shipment.order', fn ($q) => $q->where('priority', '<=', SalesOrderPriorityService::URGENT));
        if (! empty($filters['custom_only'])) $query->where(fn ($q) => $q->whereHas('shipment.order', fn ($order) => $order->where('is_customized', true)->orWhere('is_special_customized', true))
            ->orWhereHas('package.packingContents', fn ($content) => $openContent($content)
                ->whereHas('shipmentLine.orderLine', fn ($line) => $line->where('is_customized', true)->orWhere('is_special_customized', true))));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%'.$keyword.'%';
            $matchSerial = fn ($serial) => $serial->selectRaw('1')->from('erp_inventory_serials as sn')
                ->whereRaw("JSON_CONTAINS(erp_shipment_packing_contents.serial_snapshot, CAST(sn.id AS JSON), '$.inventory_serial_ids')")
                ->where(fn ($match) => $match->where('sn.serial_no', 'like', $like)->orWhereExists(fn ($work) => $work->selectRaw('1')
                    ->from('erp_mfg_work_order_units as unit')->join('erp_mfg_work_orders as work_order', 'work_order.id', '=', 'unit.work_order_id')
                    ->whereColumn('unit.inventory_serial_id', 'sn.id')->where('work_order.work_order_no', 'like', $like)));
            $query->where(fn ($q) => $q->where('operation_name_snapshot', 'like', $like)
                ->orWhereHas('shipment', fn ($shipment) => $shipment->where('shipment_no', 'like', $like))
                ->orWhereHas('shipment.order', fn ($order) => $order->where('sales_order_no', 'like', $like))
                ->orWhereHas('package', fn ($package) => $package->where('package_no', 'like', $like))
                ->orWhereHas('package.packingContents', fn ($content) => $openContent($content)->where(fn ($match) => $match
                    ->whereHas('shipmentLine.orderLine', fn ($line) => $line->where('product_name', 'like', $like)->orWhere('item_name', 'like', $like)
                        ->orWhere('sku_name', 'like', $like)->orWhere('sku_code', 'like', $like))
                    ->orWhereExists($matchSerial))));
        }

        // shortcut: 每次刷新最多展示 300 项，超出时按单号或负责人筛选；有大批待处理时再给泳道加分页。
        $operations = $query->with(['shipment:id,shipment_no,sales_order_id', 'shipment.order:id,sales_order_no,priority,is_customized,is_special_customized', 'package:id,package_no',
            'participants' => fn ($q) => $q->where('is_active', true), 'laborSessions' => fn ($q) => $q->where('status', 'ACTIVE')])
            ->orderByRaw("CASE WHEN status = 'WAITING' THEN 1 ELSE 0 END")->orderBy('id')->limit(300)->get();
        if ($operations->isEmpty()) return ['columns' => [], 'owners' => $owners];

        $contents = ShipmentPackingContent::query()->whereIn('id', $operations->flatMap(fn ($op) => $op->packing_content_ids ?? [])->unique())
            ->with(['shipmentLine' => fn ($q) => $openLine($q)->select('id', 'sales_order_line_id', 'line_status', 'serial_snapshot', 'base_qty'),
                'shipmentLine.orderLine:id,product_name,item_name,sku_name,is_customized,is_special_customized'])
            ->get()->filter(fn ($row) => $row->shipmentLine !== null)->keyBy('id');
        $serialNos = InventorySerial::query()->whereIn('id', $contents->flatMap(fn ($content) => $content->serial_snapshot['inventory_serial_ids'] ?? [])->unique())
            ->pluck('serial_no', 'id');
        $chainKey = fn ($op) => $op->package_id.':'.$op->chain_key;
        // 逐台当前轮次可能一部分已合格、一部分待检。前道按同包裹、同冻结路线计算，不能用整张作业的状态替代。
        $chains = ShipmentPackingOperation::query()->whereIn('package_id', $operations->pluck('package_id')->unique())
            ->get(['id', 'package_id', 'chain_key', 'sequence'])->groupBy($chainKey);
        $results = ShipmentSerialResult::query()->join('erp_sales_shipment_lines as line', 'line.id', '=', 'erp_shipment_serial_results.shipment_line_id')
            ->whereColumn('erp_shipment_serial_results.round', 'line.quality_round')
            ->whereIn('operation_id', $chains->flatten(1)->pluck('id'))->whereIn('shipment_line_id', $contents->pluck('shipment_line_id')->unique())
            ->get(['operation_id', 'shipment_line_id', 'inventory_serial_id', 'erp_shipment_serial_results.status'])->groupBy('operation_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->shipment_line_id.':'.$row->inventory_serial_id => $row->status]));

        $columns = [];
        foreach (self::LANES as $type => $title) {
            $lane = $operations->where('shipment_operation_type_snapshot', $type);
            $cards = $lane->map(function ($op) use ($contents, $serialNos, $chains, $chainKey, $results) {
                $rows = collect($op->packing_content_ids)->map(fn ($id) => $contents->get($id))->filter();
                if ($rows->isEmpty()) return null;
                $previous = $chains->get($chainKey($op), collect())->where('sequence', '<', $op->sequence);
                $order = $op->shipment?->order;
                $waitingStaging = $rows->every(fn ($row) => in_array($row->shipmentLine->line_status, ['draft', 'pending_outbound'], true));
                $units = $rows->flatMap(function ($row) use ($op, $serialNos, $previous, $results) {
                    $line = $row->shipmentLine;
                    $perSerial = $line->line_status !== 'outbound_posted' && count($line->serial_snapshot['inventory_serial_ids'] ?? []) === 1
                        && bccomp((string) $line->base_qty, '1', 8) === 0;
                    return collect($row->serial_snapshot['inventory_serial_ids'] ?? [])->filter(fn ($id) => isset($serialNos[$id]))
                        ->map(fn ($id) => ['id' => (int) $id, 'serial_no' => $serialNos[$id], 'short' => substr($serialNos[$id], -4),
                            'state' => $this->serialState($op->status, $line->line_status, $perSerial,
                                $previous, $results, $op->id, $line->id.':'.$id)]);
                })->unique('id')->values();
                $state = match (true) {
                    $waitingStaging => 'waiting_staging',
                    $op->status === 'WAIT_QUALITY' => 'wait_quality',
                    $op->status === 'IN_PROGRESS' => 'working',
                    $op->status === 'READY' && $op->quality_result === 'failed' => 'rework',
                    $op->status === 'READY' => $op->owner_legacy_id ? 'claimed' : 'ready',
                    $units->contains('state', 'rework') => 'rework',
                    default => 'waiting_previous',
                };
                $names = $rows->map(function ($row) {
                    $line = $row->shipmentLine->orderLine;
                    return implode(' · ', array_filter(array_unique([$line?->product_name ?: $line?->item_name, $line?->sku_name])));
                })->filter()->unique();
                return ['id' => (int) $op->id, 'operation_name' => $op->operation_name_snapshot, 'state' => $state,
                    'shipment_id' => (int) $op->shipment_id, 'shipment_no' => $op->shipment?->shipment_no, 'package_no' => $op->package?->package_no,
                    'sales_order_no' => $order?->sales_order_no,
                    'product_name' => $names->implode('、'), 'planned_qty' => $rows->sum('base_qty'), 'units' => $units->all(),
                    'priority' => SalesOrderPriorityService::normalize($order?->priority),
                    'is_custom' => $order?->is_customized || $order?->is_special_customized
                        || $rows->contains(fn ($row) => $row->shipmentLine->orderLine?->is_customized || $row->shipmentLine->orderLine?->is_special_customized),
                    'owner_name' => $op->participants->firstWhere('employee_legacy_id', $op->owner_legacy_id)?->employee_name_snapshot,
                    'collaborator_names' => $op->participants->where('is_active', true)->where('role', 'COLLABORATOR')->pluck('employee_name_snapshot')->values()->all(),
                    'timing_count' => $op->laborSessions->count()];
            })->filter()->sortBy('priority')->values();
            if ($cards->isEmpty()) continue;
            $columns[] = ['key' => 'shipment-'.$type, 'title' => $title, 'cards' => $cards->all(),
                'people_count' => $lane->flatMap(fn ($op) => $op->laborSessions->pluck('employee_legacy_id'))->unique()->count()];
        }
        return ['columns' => $columns, 'owners' => $owners];
    }

    private function serialState(string $operationStatus, string $lineStatus, bool $perSerial, Collection $previous, Collection $results, int $operationId, string $serialKey): string
    {
        $result = $results->get($operationId)?->get($serialKey);
        return match (true) {
            $result === 'failed' => 'rework',
            $result === 'completed' => 'completed',
            $result === 'wait_quality' => 'wait_quality',
            in_array($lineStatus, ['draft', 'pending_outbound'], true) => 'waiting_staging',
            ! $perSerial => match ($operationStatus) {
                'READY' => 'ready', 'IN_PROGRESS' => 'working', 'WAIT_QUALITY' => 'wait_quality', default => 'waiting_previous',
            },
            $lineStatus !== 'prepared' || $result !== 'waiting'
                || $previous->contains(fn ($op) => $results->get($op->id)?->get($serialKey) !== 'completed') => 'waiting_previous',
            $operationStatus === 'IN_PROGRESS' => 'working',
            default => 'ready',
        };
    }
}
