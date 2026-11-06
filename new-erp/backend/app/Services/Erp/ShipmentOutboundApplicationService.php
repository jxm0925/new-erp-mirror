<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\ShipmentOutboundApplication;
use App\Models\Erp\ShipmentPackingOperation;
use App\Models\Erp\Unit;
use App\Services\Erp\Inventory\InventoryTransferService;
use App\Services\Erp\Inventory\SalesInventoryPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ShipmentOutboundApplicationService
{
    private const REVIEW_FIELDS = ['id', 'kind', 'status', 'shipment_no', 'operation_name', 'applicant_name', 'package_nos',
        'delivery_mode', 'carrier_name', 'tracking_no', 'reviewer_name'];

    public function __construct(
        private readonly ShipmentCommandService $commands,
        private readonly SalesInventoryPosting $inventory,
    ) {}

    public function requestMaterials(SalesShipment $shipment, ShipmentPackingOperation $op, array $rows, object $actor): ShipmentOutboundApplication
    {
        if (! app(ShipmentPackingKittingService::class)->canReceive($op)) $this->fail('请在本工序备料或执行期间申请包装材料。');
        $this->assertNoPendingMaterials($op);
        if ($rows === []) $this->fail('请至少选择一项实际用料及其库存来源。');
        $usage = app(ShipmentPackingMaterialUsagePolicy::class);
        $lines = $this->materialBalances($op, $rows)->zip($rows)->map(function (Collection $pair) use ($usage): array {
            [$balance, $row] = $pair->all();
            $quantity = CuttingDecimal::value($row['base_qty'] ?? '', 8);
            $unit = app(UnitConversionDomainService::class)->canonicalUnit($balance->unit);
            $details = $usage->snapshot($row);
            if ($area = $usage->areaUsage($row, $quantity, $unit)) {
                $details = array_merge($details ?? ['dimensions' => null, 'remark' => null], ['area_usage' => $area]);
            }
            $serialIds = array_values(array_map('intval', $row['inventory_serial_ids'] ?? []));
            $physicalIds = array_values(array_map('intval', $row['physical_material_ids'] ?? []));
            $serialNos = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')
                ->whereIn('id', $serialIds)->orderBy('id')->pluck('serial_no')->all();
            $physicalNos = DB::table('erp_material_physicals as physical')->join('erp_material_holdings as holding', 'holding.id', '=', 'physical.current_holding_id')
                ->where('holding.inventory_balance_id', $balance->id)->where('holding.position_type', 'WAREHOUSE')
                ->where('holding.status', 'ACTIVE')->where('physical.status', 'AVAILABLE')->whereIn('physical.id', $physicalIds)
                ->orderBy('physical.id')->pluck('physical.physical_no')->all();
            if (count($serialNos) !== count($serialIds) || count($physicalNos) !== count($physicalIds)) {
                $this->fail('所选序列号或板料实物已不在该库存来源的可用库存中。');
            }

            return $this->stockSource($balance) + [
                'inventory_balance_id' => (int) $balance->id, 'base_qty' => $quantity,
                'name' => $balance->item->item_name, 'quantity' => $quantity, 'unit_name' => $unit?->unit_name,
                'warehouse_name' => $balance->warehouse?->warehouse_name, 'location_name' => $balance->location?->location_name,
                'serial_nos' => $serialNos, 'physical_nos' => $physicalNos, 'usage_snapshot' => $details,
                'serial_snapshot' => ['inventory_serial_ids' => $serialIds, 'physical_material_ids' => $physicalIds],
            ];
        })->all();

        return $this->create($shipment, $op, $actor, 'materials', [(int) $op->package_id], [$op->package->package_no], $lines);
    }

    public function assertNoPendingMaterials(ShipmentPackingOperation $op): void
    {
        if ($this->hasPendingMaterials($op)) {
            $this->fail('包装材料出库申请正在审核，请等待审核或先撤回申请。');
        }
    }

    public function hasPendingMaterials(ShipmentPackingOperation $op): bool
    {
        return ShipmentOutboundApplication::query()->where('operation_id', $op->id)->where('kind', 'materials')->open()->exists();
    }

    public function requestDispatch(SalesShipment $shipment, ShipmentPackingOperation $op, array $payload, object $actor): ShipmentOutboundApplication
    {
        $this->fail('发货前不再提交仓库审核，请更新页面，在打包前完成齐套核对，货物交付后直接确认发货。');
    }

    public function dispatchNow(SalesShipment $shipment, ShipmentPackingOperation $op, array $payload, object $actor): void
    {
        if (! $this->terminalOperation($op, (int) $actor->legacy_id)) $this->fail('请由已完成末道工序的接单人确认发货。');
        $packageIds = array_key_exists('package_ids', $payload)
            ? array_values(array_unique(array_map('intval', $payload['package_ids'])))
            : $this->linkedPackageIds($shipment, (int) $op->package_id);
        if (array_key_exists('package_ids', $payload) && (int) ($payload['expected_shipment_version'] ?? 0) !== (int) $shipment->business_version)
            $this->fail('发货单已变化，请重新打开设备清单后选择。');
        $this->assertDispatchPackagesOwned($shipment, $packageIds, (int) $actor->legacy_id);
        app(ShipmentPackingKittingService::class)->assertDispatchReady($shipment, $packageIds);
        app(SalesShipmentApplicationService::class)->dispatchFromPacking($shipment, array_replace($payload, ['package_ids' => $packageIds]), $actor);
    }

    /** 仅提供当前发货单的物品和就绪状态，不暴露订单金额等销售资料。 */
    public function dispatchChoices(ShipmentPackingOperation $op, object $actor, int $page): array
    {
        if (! $this->terminalOperation($op, (int) $actor->legacy_id)) $this->fail('请由已完成末道工序的接单人选择本次发货设备。');
        $shipment = $op->shipment;
        $packages = collect(app(ShipmentPackingApplicationService::class)->dispatchablePackages($shipment))
            ->where('package_status', '!=', 'shipped')->keyBy('id');
        $seen = []; $rows = [];
        foreach ($packages as $package) {
            if (isset($seen[$package['id']])) continue;
            // 同一发货明细分装的包裹必须一起选，避免只发一部分却扣整行库存。
            $ids = $this->linkedPackageIds($shipment, (int) $package['id']);
            foreach ($ids as $id) $seen[$id] = true;
            $members = $packages->only($ids);
            $reason = null;
            try {
                app(SalesShipmentApplicationService::class)->dispatchSelection($shipment, $ids, false);
                $this->assertDispatchPackagesOwned($shipment, $ids, (int) $actor->legacy_id);
            } catch (ValidationException $exception) { $reason = collect($exception->errors())->flatten()->first(); }
            $rows[] = ['id' => implode('-', $ids), 'package_ids' => $ids, 'package_nos' => $members->pluck('package_no')->values()->all(),
                'contents_text' => $members->pluck('contents_text')->filter()->implode('；'), 'ready' => $reason === null,
                'blocked_reason' => $reason, 'is_current' => in_array((int) $op->package_id, $ids, true)];
        }
        $page = max(1, $page);
        return ['data' => array_slice($rows, ($page - 1) * 10, 10), 'current_page' => $page,
            'last_page' => max(1, (int) ceil(count($rows) / 10)), 'total' => count($rows),
            'shipment_no' => $shipment->shipment_no, 'shipment_version' => (int) $shipment->business_version];
    }

    private function assertDispatchPackagesOwned(SalesShipment $shipment, array $packageIds, int $actorId): void
    {
        $ids = array_values(array_unique(array_map('intval', $packageIds)));
        if ($ids === [] || $shipment->packages()->whereIn('id', $ids)->whereNotIn('package_status', ['shipped', 'cancelled'])->count() !== count($ids))
            $this->fail('请选择本发货单尚未发出的设备包裹。');
        $owned = ShipmentPackingOperation::query()->where('shipment_id', $shipment->id)->whereIn('package_id', $ids);
        $this->includeOutboundWork($owned, $actorId);
        if (array_diff($ids, $owned->pluck('package_id')->map('intval')->unique()->all()) !== [])
            $this->fail('只能确认本人已完成末道作业的包裹，请由对应接单人办理发货。');
    }

    public function requestProducts(SalesShipment $shipment, ShipmentPackingOperation $op, array $rows, object $actor): ShipmentOutboundApplication
    {
        if (! app(ShipmentPackingKittingService::class)->applies($op)
            || ! app(ShipmentPackingKittingService::class)->canReceive($op)) $this->fail('请在打包齐套核对时申请缺少的整机或随货配件。');
        if (ShipmentOutboundApplication::query()->where('shipment_id', $shipment->id)->where('kind', 'products')
            ->whereJsonContains('package_ids', (int) $op->package_id)->open()->exists()) $this->fail('本包裹已有待处理的产品领用申请。');
        $contents = $shipment->packingContents()->current()->where('package_id', $op->package_id)->with('shipmentLine')->get()->keyBy('id');
        $lines = [];
        $seen = [];
        foreach ($rows as $row) {
            $content = $contents->get((int) $row['content_id']);
            $line = $content?->shipmentLine;
            if (! $line || isset($seen[$content->id]) || ! in_array($line->line_status, ['pending_outbound', 'prepared'], true)) $this->fail('请选择当前包裹尚未发出的产品。');
            $qty = CuttingDecimal::value($row['base_qty'], 8, true);
            if (bccomp($qty, (string) $content->base_qty, 8) > 0) $this->fail('缺少数量不能超过本包裹需要的数量。');
            $unit = app(UnitConversionDomainService::class)->canonicalUnit(Unit::find($line->unit_id));
            if (! $unit || $unit->status !== 'enabled' || ! is_numeric($unit->decimal_places)) $this->fail('产品库存单位或计量精度未维护。');
            $places = $unit->allow_decimal ? (int) $unit->decimal_places : 0;
            if ($places < 0 || $places > 8 || bccomp($qty, bcadd($qty, '0', $places), 8) !== 0) $this->fail('缺少数量不符合产品库存单位的计量精度。');
            if (! empty($content->serial_snapshot['inventory_serial_ids']) && bccomp($qty, (string) (int) $qty, 8) !== 0) $this->fail('带设备编号的产品只能按整台领用。');
            $seen[$content->id] = true;
            $detail = $this->dispatchLines(collect([$line]))[0];
            $lines[] = array_merge($detail, ['content_id' => (int) $content->id, 'content_qty' => (string) $content->base_qty,
                'was_prepared' => $line->line_status === 'prepared', 'quantity' => $qty,
                'preparation_note' => $line->line_status === 'pending_outbound' && bccomp($qty, (string) $line->base_qty, 8) !== 0
                    ? '本次仅领缺项；该发货明细尚未整体备货，请先按原发货安排移至待发区，再确认本次缺项已交付。' : null]);
        }
        if ($lines === []) $this->fail('请选择缺少的产品及数量。');

        return $this->create($shipment, $op, $actor, 'products', [(int) $op->package_id], [$op->package->package_no], $lines);
    }

    private function approveProducts(ShipmentOutboundApplication $application, ShipmentPackingOperation $op, object $actor): array
    {
        if (! app(ShipmentPackingKittingService::class)->canReceive($op) || (int) $op->owner_legacy_id !== (int) $application->applicant_legacy_id) $this->fail('打包任务或接单人已变化，请驳回申请。');
        $shipment = $op->shipment;
        $lines = [];
        $warehouses = InventoryTransferService::warehouseIds($actor);
        foreach ($application->lines_snapshot as $row) {
            $content = $shipment->packingContents()->current()->where('package_id', $op->package_id)->find($row['content_id']);
            $line = $content ? $shipment->lines()->lockForUpdate()->find($content->shipment_line_id) : null;
            if (! $line || ! in_array($line->line_status, ['pending_outbound', 'prepared'], true)) $this->fail('领用产品已变化，请驳回后重新申请。');
            $expected = $row['source_identity'];
            ksort($expected);
            $current = $this->dispatchIdentity($line);
            // 允许仓库先按原安排备货，数量、编号、批次和预留仍须完全一致。
            if (! $row['was_prepared'] && $line->line_status === 'prepared'
                && (int) ($line->preparation_snapshot['staging_location_id'] ?? 0) === (int) $line->location_id) {
                $current['warehouse_id'] = (int) ($line->preparation_snapshot['warehouse_id'] ?? 0);
                $current['location_id'] = (int) ($line->preparation_snapshot['location_id'] ?? 0);
            }
            if ($expected !== $current || (string) $row['content_qty'] !== (string) $content->base_qty) $this->fail('产品来源、数量或设备编号已变化，请重新核对。');
            if ($warehouses !== null && ! in_array((int) $line->warehouse_id, array_map('intval', $warehouses), true)) $this->fail('当前产品仓库不在你的管理范围内。');
            if ($line->line_status === 'pending_outbound' && bccomp((string) $row['quantity'], (string) $line->base_qty, 8) !== 0) $this->fail('本次只申请了部分缺项；请先按原发货安排将该明细移至待发区，再确认缺项交付，不能把部分领用记成整行移库。');
            $lines[$line->id] = $line;
        }
        // 全部来源校验通过再移动，避免同一发货明细在多个清单行中重复移库。
        foreach ($lines as $line) {
            if ($line->line_status === 'pending_outbound') {
                $location = \App\Models\Erp\Location::query()->where('warehouse_id', $line->warehouse_id)
                    ->where('location_purpose', 'shipping_staging')->whereIn('status', ['enabled', 'active'])->orderBy('id')->first();
                if (! $location) $this->fail('产品来源仓库没有启用的待发库位，请先维护。');
                $this->inventory->moveSalesShipmentLine($line, (int) $location->id, 'stage', $actor);
                $line->update(['line_status' => 'prepared', 'prepared_at' => now()]);
                app(ShipmentPackingApplicationService::class)->resetSerialRound($line);
            }
            // 已直接交接到待发区的产品只确认实物交付，不重复入库、领用或扣减。
        }
        $shipment->update(['shipment_status' => $shipment->lines()->where('line_status', 'shipped')->exists() ? 'partially_shipped' : 'prepared',
            'staged_at' => $shipment->staged_at ?: now(), 'business_version' => (int) $shipment->business_version + 1]);

        return ['status' => 'posted', 'posted_at' => now()];
    }

    public function confirmDispatch(SalesShipment $shipment, ShipmentPackingOperation $op, int $id, object $actor): void
    {
        $application = $this->ownApplication($op, $id, (int) $actor->legacy_id);
        if ($application->kind !== 'dispatch' || $application->status !== 'approved'
            || ! $this->terminalOperation($op, (int) $actor->legacy_id)) $this->fail('只有出库审核通过的发货申请可以由申请人确认发货。');
        $dispatch = app(SalesShipmentApplicationService::class)->dispatchApproved($shipment, $application, $actor);
        $application->update(['status' => 'posted', 'dispatch_id' => $dispatch->id, 'inventory_transaction_id' => $dispatch->inventory_transaction_id,
            'posted_at' => $dispatch->dispatched_at]);
    }

    public function withdraw(ShipmentPackingOperation $op, int $id, object $actor): void
    {
        $application = $this->ownApplication($op, $id, (int) $actor->legacy_id);
        if (! in_array($application->status, ShipmentOutboundApplication::OPEN, true)) $this->fail('只有待审核或已通过但尚未出库的申请可以撤回。');
        $application->update($this->withdrawal((int) $actor->legacy_id, '申请人撤回出库申请。'));
    }

    public function withdrawForShipmentChange(SalesShipment $shipment, object $actor, string $reason, ?array $packageIds = null, ?array $operationIds = null): void
    {
        $applications = ShipmentOutboundApplication::query()->where('shipment_id', $shipment->id)->open()->get();
        foreach ($applications as $application) {
            $affected = $application->kind === 'materials'
                ? $operationIds === null || in_array((int) $application->operation_id, $operationIds, true)
                : $packageIds === null || array_intersect($packageIds, $application->package_ids) !== [];
            if ($affected) {
                $application->update($this->withdrawal((int) $actor->legacy_id, $reason));
            }
        }
    }

    public function openDispatchPackageIds(int $shipmentId, ?int $exceptId = null): array
    {
        return ShipmentOutboundApplication::query()->where('shipment_id', $shipmentId)->where('kind', 'dispatch')->open()
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->get(['package_ids'])
            ->flatMap(fn ($application) => $application->package_ids)->unique()->values()->all();
    }

    public function withdrawLegacyDispatch(SalesShipment $shipment, array $packageIds, object $actor): void
    {
        foreach (ShipmentOutboundApplication::query()->where('shipment_id', $shipment->id)->where('kind', 'dispatch')->open()->get() as $application) {
            if (array_intersect($packageIds, $application->package_ids) !== []) {
                $application->update($this->withdrawal((int) $actor->legacy_id, '打包齐套已确认，直接办理实际发货，不再重复仓库审核。'));
            }
        }
    }

    public function includeOutboundWork($query, int $actorId): void
    {
        $table = 'erp_shipment_packing_operations';
        $query->where('owner_legacy_id', $actorId)->where('status', 'COMPLETED')
            ->whereHas('package', fn ($q) => $q->whereNotIn('package_status', ['shipped', 'cancelled'])
                ->whereHas('packingContents', fn ($content) => $content->current()
                    ->whereRaw("JSON_CONTAINS({$table}.packing_content_ids, CAST(erp_shipment_packing_contents.id AS JSON))")))
            ->whereNotExists(fn ($later) => $later->selectRaw('1')->from($table.' as later')
                ->whereColumn('later.package_id', $table.'.package_id')->whereColumn('later.chain_key', $table.'.chain_key')
                ->whereColumn('later.sequence', '>', $table.'.sequence')->where('later.status', '!=', 'CANCELLED'));
    }

    public function operationStatus(ShipmentPackingOperation $op, int $actorId): array
    {
        $query = ShipmentOutboundApplication::query()->where('shipment_id', $op->shipment_id);
        $materials = (clone $query)->where('operation_id', $op->id)->where('kind', 'materials')->latest('id')->first();
        $products = (clone $query)->where('kind', 'products')->whereJsonContains('package_ids', (int) $op->package_id)->latest('id')->first();
        $dispatch = $query->where('kind', 'dispatch')->whereJsonContains('package_ids', (int) $op->package_id)->latest('id')->first();
        $terminal = $this->terminalOperation($op, $actorId);
        $blocked = null;
        $canRequest = false;
        $packageNos = [];
        if ($terminal) {
            try {
                $packageIds = $this->linkedPackageIds($op->shipment, (int) $op->package_id);
                [$packages] = app(SalesShipmentApplicationService::class)->dispatchSelection($op->shipment, $packageIds, false);
                $packageNos = $packages->pluck('package_no')->all();
                $canRequest = true;
            } catch (ValidationException $exception) {
                $blocked = collect($exception->errors())->flatten()->first();
            }
        }

        return ['materials' => $this->operationApplication($materials, $op, $actorId, false),
            'products' => $this->operationApplication($products, $op, $actorId, false),
            'dispatch' => $this->operationApplication($dispatch, $op, $actorId, $terminal),
            'can_request_dispatch' => false, 'can_dispatch' => $canRequest, 'dispatch_block_reason' => $blocked, 'dispatch_package_nos' => $packageNos];
    }

    public function reviewList(array $filters, object $actor): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $status = $filters['status'] ?? 'pending';
        $query = $this->reviewQuery($actor);
        if ($status === 'processed') $query->where('status', '!=', 'pending');
        elseif ($status !== 'all') $query->where('status', $status);
        $page = $query
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($match) => $match->where('shipment_no', 'like', '%'.$keyword.'%')
                ->orWhere('applicant_name', 'like', '%'.$keyword.'%')->orWhere('operation_name', 'like', '%'.$keyword.'%')
                ->orWhereRaw('JSON_SEARCH(package_nos, \'one\', ?) IS NOT NULL', ['%'.$keyword.'%'])))
            ->latest('id')->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 10))),
                array_merge(self::REVIEW_FIELDS, ['created_at', 'reviewed_at', 'review_reason', 'withdrawal_reason']), 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(fn ($application) => $this->reviewProjection($application, false)));

        return $page->toArray();
    }

    public function reviewDetail(int $id, object $actor): array
    {
        return $this->reviewProjection($this->reviewQuery($actor)->findOrFail($id));
    }

    public function review(int $id, array $payload, object $actor): array
    {
        $shipmentId = (int) $this->reviewQuery($actor)->findOrFail($id)->shipment_id;

        return $this->commands->run('review_outbound_application', $shipmentId, $payload + ['application_id' => $id], $actor,
            function (SalesShipment $shipment) use ($id, $payload, $actor): array {
                $application = $this->reviewQuery($actor)->where('shipment_id', $shipment->id)->lockForUpdate()->findOrFail($id);
                if ($application->status !== 'pending') $this->fail('该出库申请已处理，请刷新后查看。');
                $reason = trim((string) ($payload['reason'] ?? ''));
                $decision = $payload['decision'] ?? '';
                if (! in_array($decision, ['approve', 'reject'], true) || ($decision === 'reject' && $reason === '')) {
                    $this->fail('请选择审核结果，驳回时请填写原因。');
                }
                $attributes = ['reviewed_at' => now(), 'review_reason' => $reason ?: null,
                    'reviewer_legacy_id' => (int) $actor->legacy_id, 'reviewer_name' => $this->actorName($actor)];
                if ($decision === 'reject') {
                    $attributes['status'] = 'rejected';
                } else {
                    if (! in_array($shipment->shipment_status, ['pending_outbound', 'prepared', 'partially_shipped', 'outbound_posted'], true)
                        || $shipment->order?->order_status === 'cancelled') $this->fail('订单或发货单已结束，不能继续领用，请驳回申请。');
                    $op = $shipment->packingOperations()->lockForUpdate()->findOrFail($application->operation_id);
                    if ($application->kind === 'materials') {
                        $attributes += $this->approveMaterials($application, $op, $actor);
                    } elseif ($application->kind === 'products') {
                        $attributes += $this->approveProducts($application, $op, $actor);
                    } else {
                        if (! $this->terminalOperation($op, (int) $application->applicant_legacy_id)) $this->fail('发货工序或接单人已变化，请驳回后重新申请。');
                        [, $lines] = app(SalesShipmentApplicationService::class)->dispatchSelection($shipment, $application->package_ids);
                        $this->assertDispatchSnapshot($application, $lines);
                        $attributes['status'] = 'approved';
                    }
                }
                $application->update($attributes);
                $shipment->packingOperations()->whereKey($application->operation_id)->increment('business_version');

                return $this->reviewProjection($application);
            });
    }

    public function assertDispatchSnapshot(ShipmentOutboundApplication $application, Collection $lines): void
    {
        $current = $lines->map(fn ($line) => $this->dispatchIdentity($line))->all();
        $requested = array_map(function ($line): array {
            $identity = $line['source_identity'];
            ksort($identity);

            return $identity;
        }, $application->lines_snapshot);
        // 库位、数量、序列号或试水轮次变了就重新申请，避免沿用旧审核发出另一批货。
        if ($current !== $requested) $this->fail('发货产品、数量、库存来源或试水轮次已变化，请撤回后重新申请。');
    }

    private function approveMaterials(ShipmentOutboundApplication $application, ShipmentPackingOperation $op, object $actor): array
    {
        if (! app(ShipmentPackingKittingService::class)->canReceive($op) || (int) $op->owner_legacy_id !== (int) $application->applicant_legacy_id) {
            $this->fail('包装工序或接单人已变化，请驳回后重新申请。');
        }
        $rows = $application->lines_snapshot;
        $balances = $this->materialBalances($op, $rows);
        $materials = [];
        foreach ($rows as $index => $row) {
            $source = $this->stockSource($balances[$index]);
            if (collect($source)->contains(fn ($value, $key) => ($row[$key] ?? null) !== $value)) {
                $this->fail('包装材料库存来源已变化，请驳回后重新申请。');
            }
            $materials[] = $op->materials()->create(collect($row)->only(['inventory_balance_id', 'item_id', 'unit_id', 'base_qty', 'usage_snapshot', 'serial_snapshot'])
                ->all() + ['posted_by_legacy_id' => (int) $actor->legacy_id]);
        }
        // 与申请审核共用事务，库存校验或过账失败时仍保留待审核。
        $transaction = $this->inventory->postShipmentPackingMaterials($op, $materials, $actor);

        return ['status' => 'posted', 'posted_at' => now(), 'inventory_transaction_id' => $transaction->id];
    }

    private function materialBalances(ShipmentPackingOperation $op, array $rows): Collection
    {
        $allowed = collect($op->packaging_materials_snapshot ?? [])->pluck('component_item_id');
        $balances = InventoryBalance::query()->with(['unit.standardUnit', 'item', 'warehouse', 'location'])
            ->whereIn('item_id', $allowed)->whereIn('id', array_column($rows, 'inventory_balance_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $totals = [];

        return collect($rows)->map(function (array $row) use ($balances, &$totals): InventoryBalance {
            $balance = $balances->get((int) ($row['inventory_balance_id'] ?? 0));
            if (! $balance || $balance->item?->status !== 'enabled'
                || ! in_array($balance->warehouse?->status, ['enabled', 'active'], true)
                || ! in_array($balance->location?->status, ['enabled', 'active'], true)
                || (int) $balance->location->warehouse_id !== (int) $balance->warehouse_id) $this->fail('请选择本工序包装材料的有效库存来源。');
            app(ItemManagementScopeService::class)->assertProductionAllowed($balance->item, 'materials');
            $quantity = CuttingDecimal::value($row['base_qty'] ?? '', 8);
            $unit = app(UnitConversionDomainService::class)->canonicalUnit($balance->unit);
            $usage = app(ShipmentPackingMaterialUsagePolicy::class);
            $usage->assertQuantity($quantity, (string) $balance->quantity_available, $unit);
            $totals[$balance->id] = bcadd($totals[$balance->id] ?? '0', $quantity, 8);
            $usage->assertQuantity($totals[$balance->id], (string) $balance->quantity_available, $unit);

            return $balance;
        });
    }

    private function create(SalesShipment $shipment, ShipmentPackingOperation $op, object $actor, string $kind, array $packageIds, array $packageNos, array $lines, array $details = []): ShipmentOutboundApplication
    {
        return ShipmentOutboundApplication::create($details + [
            'shipment_id' => $shipment->id, 'operation_id' => $op->id, 'shipment_no' => $shipment->shipment_no,
            'operation_name' => $op->operation_name_snapshot, 'kind' => $kind, 'status' => 'pending',
            'package_ids' => $packageIds, 'package_nos' => $packageNos, 'lines_snapshot' => $lines,
            'warehouse_ids' => array_values(array_unique(array_column($lines, 'warehouse_id'))),
            'applicant_legacy_id' => (int) $actor->legacy_id, 'applicant_name' => $this->actorName($actor),
        ]);
    }

    private function dispatchLines(Collection $lines): array
    {
        $warehouses = DB::table('erp_warehouses')->whereIn('id', $lines->pluck('warehouse_id'))->pluck('warehouse_name', 'id');
        $locations = DB::table('erp_locations')->whereIn('id', $lines->pluck('location_id'))->pluck('location_name', 'id');
        $units = Unit::query()->whereIn('id', $lines->pluck('unit_id'))->get(['id', 'unit_name'])->keyBy('id');
        $serialNos = InventorySerial::query()->whereIn('id', $lines->flatMap(fn ($line) => $line->serial_snapshot['inventory_serial_ids'] ?? []))
            ->pluck('serial_no', 'id');
        $lines->load('orderLine:id,product_name,item_name');

        return $lines->map(fn ($line) => $this->stockSource($line) + ['source_identity' => $this->dispatchIdentity($line),
            'name' => $line->orderLine?->product_name ?: $line->orderLine?->item_name,
            'quantity' => (string) $line->base_qty, 'unit_name' => $units->get($line->unit_id)?->unit_name,
            'warehouse_name' => $warehouses[$line->warehouse_id] ?? null, 'location_name' => $locations[$line->location_id] ?? null,
            'serial_nos' => collect($line->serial_snapshot['inventory_serial_ids'] ?? [])->map(fn ($id) => $serialNos[$id] ?? null)->filter()->values()->all(),
        ])->all();
    }

    private function dispatchIdentity($line): array
    {
        $serials = array_map('intval', $line->serial_snapshot['inventory_serial_ids'] ?? []);
        sort($serials);

        $identity = $this->stockSource($line) + ['id' => (int) $line->id, 'reservation_id' => (int) $line->inventory_reservation_id,
            'base_qty' => (string) $line->base_qty,
            'quality_round' => (int) $line->quality_round, 'serial_ids' => $serials];
        ksort($identity);

        return $identity;
    }

    private function stockSource($row): array
    {
        return ['item_id' => (int) $row->item_id, 'unit_id' => (int) $row->unit_id, 'warehouse_id' => (int) $row->warehouse_id,
            'location_id' => (int) $row->location_id, 'batch_no' => (string) $row->batch_no];
    }

    private function linkedPackageIds(SalesShipment $shipment, int $packageId): array
    {
        $contents = $shipment->packingContents()->current()->get(['package_id', 'shipment_line_id']);
        $byPackage = $contents->groupBy('package_id');
        $byLine = $contents->groupBy('shipment_line_id');
        $ids = [$packageId => true];
        $queue = [$packageId];
        // 同一笔发货明细可能拆在不同包裹中，连带的包裹一并审核和过账。
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($byPackage->get($queue[$i], []) as $content) {
                foreach ($byLine->get($content->shipment_line_id) as $linked) {
                    $id = (int) $linked->package_id;
                    if (isset($ids[$id])) continue;
                    $ids[$id] = true;
                    $queue[] = $id;
                }
            }
        }
        sort($queue);

        return $queue;
    }

    private function terminalOperation(ShipmentPackingOperation $op, int $actorId): bool
    {
        if ($op->status !== 'COMPLETED' || (int) $op->owner_legacy_id !== $actorId) return false;
        $query = ShipmentPackingOperation::query()->whereKey($op->id);
        $this->includeOutboundWork($query, $actorId);

        return $query->exists();
    }

    private function ownApplication(ShipmentPackingOperation $op, int $id, int $actorId): ShipmentOutboundApplication
    {
        return ShipmentOutboundApplication::query()->where('operation_id', $op->id)->where('shipment_id', $op->shipment_id)
            ->where('applicant_legacy_id', $actorId)->lockForUpdate()->findOrFail($id);
    }

    private function withdrawal(int $actorId, string $reason): array
    {
        return ['status' => 'withdrawn', 'withdrawn_by_legacy_id' => $actorId, 'withdrawn_at' => now(), 'withdrawal_reason' => $reason];
    }

    private function reviewQuery(object $actor)
    {
        $auth = app(AuthContextService::class);
        abort_unless($auth->isSuperAdmin($actor) || in_array('sales_order.shipment.post', $auth->permissionCodes($actor), true), 403, '没有出库审核权限。');
        $warehouses = InventoryTransferService::warehouseIds($actor);

        // 一张申请可能涉及多个来源仓库，查看和审核都须覆盖整张申请的数据范围。
        return ShipmentOutboundApplication::query()->when($warehouses !== null,
            fn ($q) => $q->whereRaw('JSON_CONTAINS(?, warehouse_ids) = 1', [json_encode(array_values($warehouses))]));
    }

    private function reviewProjection(ShipmentOutboundApplication $application, bool $includeLines = true): array
    {
        $data = $application->only(self::REVIEW_FIELDS) + ['package_no' => implode('、', $application->package_nos),
            'review_reason' => $application->withdrawal_reason ?? $application->review_reason,
            'created_at' => $application->created_at?->toDateTimeString(), 'reviewed_at' => $application->reviewed_at?->toDateTimeString(),
            'can_review' => $application->status === 'pending'];
        if ($includeLines) {
            $data['lines'] = array_map(fn ($line) => collect($line)->only(['name', 'quantity', 'unit_name', 'warehouse_name', 'location_name', 'batch_no', 'serial_nos', 'physical_nos', 'preparation_note'])
                ->all() + ['serial_nos' => [], 'physical_nos' => []], $application->lines_snapshot);
        }

        return $data;
    }

    private function operationApplication(?ShipmentOutboundApplication $application, ShipmentPackingOperation $op, int $actorId, bool $terminal): ?array
    {
        if (! $application) return null;
        $own = (int) $application->applicant_legacy_id === $actorId && (int) $application->operation_id === (int) $op->id;

        return $application->only(['id', 'status', 'package_nos', 'delivery_mode', 'carrier_name', 'tracking_no'])
            + ['review_reason' => $application->withdrawal_reason ?? $application->review_reason,
                'can_withdraw' => $own && in_array($application->status, ShipmentOutboundApplication::OPEN, true),
                'can_confirm' => $own && $terminal && $application->status === 'approved'];
    }

    private function actorName(object $actor): string
    {
        return (string) (($actor->nickname ?? '') ?: $actor->username);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['outbound' => $message]);
    }
}
