<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesShipment;
use App\Models\Erp\ShipmentOutboundApplication;
use App\Models\Erp\ShipmentPackingContent;
use App\Models\Erp\ShipmentPackingOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 打包前核对实物；确认依据随包裹/数量/设备/试水轮次/实际领料变化自动失效。 */
final class ShipmentPackingKittingService
{
    /** 未移待发区的首道打包任务也可先接单备料；不能越过未完成的前序。 */
    public function preparationCandidates($query)
    {
        $table = 'erp_shipment_packing_operations';
        return $query->where(function ($eligible) use ($table) {
            $eligible->where('status', 'READY')->orWhere(function ($waiting) use ($table) {
                $waiting->where('status', 'WAITING')->where(function ($type) use ($table) {
                    $type->where('shipment_operation_type_snapshot', 'packing')->orWhere(fn ($loading) => $loading
                        ->where('shipment_operation_type_snapshot', 'loading')
                        ->whereNotExists(fn ($packing) => $packing->selectRaw('1')->from($table.' as packing')
                            ->whereColumn('packing.package_id', $table.'.package_id')->whereColumn('packing.chain_key', $table.'.chain_key')
                            ->where('packing.shipment_operation_type_snapshot', 'packing')->where('packing.status', '!=', 'CANCELLED')));
                })
                    ->whereNotExists(fn ($prior) => $prior->selectRaw('1')->from($table.' as prior')
                        ->whereColumn('prior.package_id', $table.'.package_id')->whereColumn('prior.chain_key', $table.'.chain_key')
                        ->whereColumn('prior.sequence', '<', $table.'.sequence')->whereNotIn('prior.status', ['COMPLETED', 'CANCELLED']))
                    ->whereHas('package', fn ($package) => $package->whereNotIn('package_status', ['shipped', 'cancelled'])->whereHas('packingContents', fn ($content) => $content->current()));
            });
        });
    }

    public function canPrepare(ShipmentPackingOperation $op): bool
    {
        return $op->status === 'READY' || ($op->status === 'WAITING' && $this->applies($op)
            && $this->preparationCandidates(ShipmentPackingOperation::query()->whereKey($op->id))->exists());
    }

    public function canReceive(ShipmentPackingOperation $op): bool
    {
        return ! in_array($op->package?->package_status, ['shipped', 'cancelled'], true)
            && ($this->canPrepare($op) || $op->status === 'IN_PROGRESS' || ($op->status === 'COMPLETED' && $this->applies($op)))
            && $op->contents()->current()->exists();
    }

    public function applies(ShipmentPackingOperation $op): bool
    {
        if ($op->shipment_operation_type_snapshot === 'packing') return true;
        // 没有打包节点的直发商品，在末道装车前完成同样的核对。
        return $op->shipment_operation_type_snapshot === 'loading'
            && ! ShipmentPackingOperation::query()->where('package_id', $op->package_id)->where('chain_key', $op->chain_key)
                ->where('shipment_operation_type_snapshot', 'packing')->where('status', '!=', 'CANCELLED')->exists();
    }

    public function projection(ShipmentPackingOperation $op, int $actorId): ?array
    {
        if (! $this->applies($op)) return $this->completedPackageProjection($op, $actorId);
        if ($op->status === 'CANCELLED') return null;
        $snapshot = $this->snapshot($op);
        $last = DB::table('erp_shipment_packing_logs')->where('operation_id', $op->id)->where('action', 'packing_kitting')->latest('id')->first();
        $confirmation = $last ? json_decode($last->payload, true) : null;
        $confirmed = $snapshot['ready'] && ($confirmation['fingerprint'] ?? null) === $snapshot['fingerprint'];
        $owner = (int) $op->owner_legacy_id === $actorId;

        return $snapshot + ['confirmed' => $confirmed, 'confirmed_at' => $confirmed ? $last->created_at : null,
            'confirmed_by' => $confirmed ? ($confirmation['operator_name'] ?? null) : null,
            'can_confirm' => $owner && $this->canReceive($op) && $snapshot['ready'] && ! $confirmed,
            'can_request_products' => $owner && ! $snapshot['products_pending'] && $this->canReceive($op)];
    }

    public function confirm(ShipmentPackingOperation $op, array $payload, object $actor): void
    {
        if ((int) $op->owner_legacy_id !== (int) $actor->legacy_id) $this->fail('请由打包接单人核对本包裹实物。');
        if (! $this->applies($op)) {
            $state = $this->completedPackageProjection($op, (int) $actor->legacy_id);
            if (! $state || ! $state['can_confirm'] || ! hash_equals($state['fingerprint'], (string) ($payload['kitting_fingerprint'] ?? ''))) $this->fail('请刷新包裹并核对齐套状态。');
            foreach ($this->completedPackingOperations($op) as $packing) $this->record($packing, $this->snapshot($packing), $actor);
            return;
        }
        if (! $this->canReceive($op)) $this->fail('本工序尚未轮到打包核对，或包裹已经结束。');
        $snapshot = $this->snapshot($op);
        if (! $snapshot['ready']) $this->fail('整机、随货配件或包装材料尚未齐套，请先处理清单中的缺项。');
        if (! hash_equals($snapshot['fingerprint'], (string) ($payload['kitting_fingerprint'] ?? ''))) $this->fail('包裹或领料记录已变化，请刷新并重新核对。');
        $this->record($op, $snapshot, $actor);
    }

    private function record(ShipmentPackingOperation $op, array $snapshot, object $actor): void
    {
        DB::table('erp_shipment_packing_logs')->insert(['shipment_id' => $op->shipment_id, 'operation_id' => $op->id,
            'action' => 'packing_kitting', 'operator_legacy_id' => (int) $actor->legacy_id,
            'payload' => json_encode($snapshot + ['operator_name' => $actor->nickname ?: $actor->username], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function completedPackingOperations(ShipmentPackingOperation $op)
    {
        return ShipmentPackingOperation::query()->where('package_id', $op->package_id)->where('status', 'COMPLETED')
            ->where('shipment_operation_type_snapshot', 'packing')->orderBy('id')->get();
    }

    /** 旧单已经打包完的，在末道负责人手中补一次实物核对，不重做工序或用料。 */
    private function completedPackageProjection(ShipmentPackingOperation $op, int $actorId): ?array
    {
        if ($op->status !== 'COMPLETED' || $op->shipment_operation_type_snapshot !== 'loading') return null;
        $rows = $this->completedPackingOperations($op)->map(fn ($packing) => $this->projection($packing, $actorId));
        if ($rows->isEmpty()) return null;
        $ready = $rows->every(fn ($row) => $row['ready']);
        $confirmed = $rows->every(fn ($row) => $row['confirmed']);

        return ['products' => $rows->first()['products'], 'materials' => $rows->flatMap(fn ($row) => $row['materials'])->values()->all(),
            'products_pending' => $rows->contains(fn ($row) => $row['products_pending']),
            'materials_pending' => $rows->contains(fn ($row) => $row['materials_pending']),
            'ready' => $ready, 'confirmed' => $confirmed, 'can_confirm' => $ready && ! $confirmed && (int) $op->owner_legacy_id === $actorId,
            'can_request_products' => false, 'fingerprint' => hash('sha256', json_encode($rows->pluck('fingerprint')->all()))];
    }

    public function assertConfirmed(ShipmentPackingOperation $op): void
    {
        if (! $this->applies($op)) return;
        $state = $this->projection($op, (int) $op->owner_legacy_id);
        if (! $state['confirmed']) $this->fail('请先完成打包前齐套核对；缺少的整机、配件或包装材料先向仓库领用。');
    }

    public function assertDispatchReady(SalesShipment $shipment, array $packageIds): void
    {
        $operations = $shipment->packingOperations()->whereIn('package_id', $packageIds)->where('status', '!=', 'CANCELLED')->get();
        foreach ($packageIds as $packageId) {
            if (ShipmentOutboundApplication::query()->where('shipment_id', $shipment->id)->where('kind', '!=', 'dispatch')
                ->whereJsonContains('package_ids', (int) $packageId)->open()->exists()) $this->fail('包裹仍有待处理的缺项或包装用料申请，请先处理完成。');
            $checks = $operations->where('package_id', $packageId)->filter(fn ($op) => $this->applies($op));
            if ($checks->isEmpty()) $this->fail('包裹缺少齐套核对节点，请先配置打包或装车作业。');
            foreach ($checks as $op) $this->assertConfirmed($op);
        }
    }

    private function snapshot(ShipmentPackingOperation $op): array
    {
        $contents = ShipmentPackingContent::query()->where('package_id', $op->package_id)->current()
            ->with('shipmentLine.orderLine')->orderBy('id')->get();
        $products = [];
        $identities = [];
        $ready = $contents->isNotEmpty();
        foreach ($contents as $content) {
            $line = $content->shipmentLine;
            $prepared = $line && $line->line_status === 'prepared';
            $ready = $ready && $prepared;
            $serials = array_map('intval', $content->serial_snapshot['inventory_serial_ids'] ?? []);
            sort($serials);
            $products[] = ['content_id' => (int) $content->id, 'shipment_line_id' => (int) $content->shipment_line_id,
                'item_name' => $line?->orderLine?->product_name ?: $line?->orderLine?->item_name,
                'required_qty' => (string) $content->base_qty, 'prepared' => $prepared,
                'unit_name' => DB::table('erp_units')->where('id', $line?->unit_id)->value('unit_name'),
                'serial_nos' => DB::table('erp_inventory_serials')->whereIn('id', $serials)->orderBy('id')->pluck('serial_no')->all()];
            $identities[] = ['content_id' => (int) $content->id, 'item_id' => (int) $content->item_id, 'qty' => (string) $content->base_qty,
                'line_id' => (int) $content->shipment_line_id, 'line_qty' => (string) $line?->base_qty, 'serials' => $serials,
                'reservation_id' => (int) $line?->inventory_reservation_id, 'warehouse_id' => (int) $line?->warehouse_id,
                'location_id' => (int) $line?->location_id, 'batch_no' => $line?->batch_no, 'round' => (int) $line?->quality_round];
        }
        $ownQty = $op->contents()->current()->get()->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->base_qty, 8), '0');
        $required = [];
        foreach ($op->packaging_materials_snapshot ?? [] as $row) {
            $id = (int) $row['component_item_id'];
            $required[$id] = bcadd($required[$id] ?? '0', bcmul((string) $row['base_qty_per_output_unit'], $ownQty, 8), 8);
        }
        ksort($required);
        $posted = $op->materials()->whereNotNull('posted_at')->orderBy('id')->get();
        $materials = [];
        foreach ($required as $itemId => $qty) {
            $received = $posted->where('item_id', $itemId)->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->base_qty, 8), '0');
            $short = bccomp($qty, $received, 8) > 0 ? bcsub($qty, $received, 8) : '0.00000000';
            $ready = $ready && bccomp($short, '0', 8) === 0;
            $materials[] = ['key' => $op->id.':'.$itemId, 'item_id' => $itemId, 'item_name' => DB::table('erp_items')->where('id', $itemId)->value('item_name'),
                'unit_name' => DB::table('erp_items as item')->join('erp_units as unit', 'unit.id', '=', 'item.unit_id')->where('item.id', $itemId)->value('unit.unit_name'),
                'required_qty' => $qty, 'received_qty' => $received, 'shortage_qty' => $short];
        }
        $productsPending = ShipmentOutboundApplication::query()->where('shipment_id', $op->shipment_id)->where('kind', 'products')
            ->whereJsonContains('package_ids', (int) $op->package_id)->open()->exists();
        $productRequests = ShipmentOutboundApplication::query()->where('shipment_id', $op->shipment_id)->where('kind', 'products')
            ->whereJsonContains('package_ids', (int) $op->package_id)->orderBy('id')->get(['id', 'status']);
        $materialsPending = ShipmentOutboundApplication::query()->where('operation_id', $op->id)->where('kind', 'materials')->open()->exists();
        $fingerprint = hash('sha256', json_encode(['operation_id' => $op->id, 'route' => $op->routing_operation_id,
            'products' => $identities, 'requirements' => $required,
            'product_requests' => $productRequests->map(fn ($row) => [$row->id, $row->status])->all(),
            'posted' => $posted->map(fn ($row) => [$row->id, $row->item_id, (string) $row->base_qty, $row->inventory_balance_id])->all(),
        ], JSON_THROW_ON_ERROR));

        return ['products' => $products, 'materials' => $materials, 'products_pending' => $productsPending,
            'materials_pending' => $materialsPending, 'ready' => $ready && ! $productsPending && ! $materialsPending,
            'fingerprint' => $fingerprint];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['kitting' => $message]);
    }
}
