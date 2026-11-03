<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, PurchaseRequest, PurchaseRequestItem};
use Illuminate\Support\Facades\DB;

final class PurchaseRequestCreationApplicationService
{
    public function create(array $data, array $items, ?object $actor = null): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $items, $actor) {
            $scopes = app(PurchaseManagementScopeService::class);
            $data['management_scope'] = $scopes->resolveDocumentScope($items, $data,
                requiredScope: $scopes->requiredScopeForSource($data['source_type'] ?? null));
            $record = PurchaseRequest::create([...$data, 'request_status' => 'draft', 'status' => 'draft',
                'item_id' => $items[0]['item_id'], 'request_qty' => 0, 'planned_qty' => 0]);
            app(PurchaseRequestSalesAllocationService::class)->prepareHeader($record, $actor);
            $production = app(PurchaseRequestProductionSourceService::class)->prepare($record, $items, $actor);
            $this->saveItems($record, $items, actor: $actor, production: $production);
            return $record->fresh('items');
        }, 5);
    }

    public function saveItems(PurchaseRequest $request, array $items, $priorItems = null, ?object $actor = null, $existingLinks = null, ?array $production = null): void
    {
        $totalQty = 0;
        $allocations = app(PurchaseRequestSalesAllocationService::class);
        $existingLinks ??= collect();
        $kept = [];
        foreach ($items as $line) {
            $item = app(PurchaseManagementScopeService::class)->assertItemScope((int) $line['item_id'], $request->management_scope)->load('unit.standardUnit');
            $line['management_scope'] = $request->management_scope;
            if (!empty($line['warehouse_id'])) app(WarehouseManagementScopeService::class)
                ->assertWarehouseItem($item, (int) $line['warehouse_id'], 'warehouse_id', $request->management_scope);
            $prior = $priorItems?->get($line['id'] ?? 0);
            abort_if(! empty($line['id']) && ! $prior, 422, '采购需求明细不属于当前需求');
            $planning = app(PurchasePlanningConversionService::class);
            $snapshot = isset($line['purchase_quantity']) ? $planning->fromPurchaseQuantity($line, $prior?->purchase_conversion_snapshot)
                : $planning->calculate([...$line, 'required_qty' => $line['request_qty']], $prior?->purchase_conversion_snapshot);
            $qty = (float) $snapshot['required_base_qty'];
            $specModel = isset($line['spec_model']) && trim((string) $line['spec_model']) !== '' ? trim((string) $line['spec_model']) : ($item->spec ?: ($item->model ?: null));
            $created = PurchaseRequestItem::create([
                'request_id' => $request->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
                'spec_model' => $specModel, 'unit_id' => $snapshot['base_unit_id'], 'request_qty' => $qty,
                'purchase_conversion_snapshot' => $snapshot, 'converted_qty' => 0, 'remaining_qty' => $qty,
                'expected_date' => $line['expected_date'] ?? null, 'warehouse_id' => $line['warehouse_id'] ?? null,
                'priority' => $line['priority'] ?? 'normal', 'line_status' => 'open', 'remark' => $line['remark'] ?? null,
                'data_source' => 'manual', ...app(MaterialPolicySnapshotService::class)->fromItem($item),
            ]);
            $kept = array_merge($kept, $allocations->preserveLegacyLine($request, $created, $prior, $existingLinks, $actor));
            $totalQty += $qty;
        }
        $allocations->finishReplacement($request, $existingLinks, $kept, $actor);
        app(PurchaseRequestProductionSourceService::class)->replace($request, $production, $actor);
        $request->update(['item_id' => $items[0]['item_id'] ?? null, 'request_qty' => $totalQty, 'planned_qty' => 0, 'required_date' => $items[0]['expected_date'] ?? null]);
    }
}
