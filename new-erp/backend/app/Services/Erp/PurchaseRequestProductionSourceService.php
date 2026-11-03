<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, MfgPurchaseDemandLink, MfgWorkOrder, PurchaseLog, PurchaseRequest, SalesOrder};
use App\Services\Erp\Manufacturing\ShortageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class PurchaseRequestProductionSourceService
{
    public function eligibleWorkOrders(object $actor): Builder
    {
        $query = MfgWorkOrder::whereIn('status', ShortageService::WORK_ORDER_STATUSES)->whereNull('cancelled_at');
        $auth = app(AuthContextService::class);
        if ($auth->isSuperAdmin($actor) || $auth->dataScope($actor) === 'all') return $query;
        $sales = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($sales, $actor);
        $ids = $auth->dataScope($actor) === 'department' ? $auth->departmentUserIds($actor) : [(int) $actor->legacy_id];
        return $query->where(fn ($scope) => $scope->whereIn('sales_order_id', $sales->select('erp_sales_orders.id'))
            ->orWhere(fn ($stock) => $stock->whereNull('sales_order_id')->whereIn('created_by_legacy_id', $ids)));
    }

    /** 在旧明细删除前重算；仅加回本需求实际抵给本工单的数量，库存新到后不会沿用过大的旧额度。 */
    public function prepare(PurchaseRequest $request, array $items, ?object $actor): ?array
    {
        if ($request->source_type !== 'production') return null;
        abort_unless($actor, 401, '请登录后选择生产工单。');
        if (!ctype_digit((string) $request->source_id)) $this->fail('source_id', '请选择已有生产工单。');
        $workOrder = $this->eligibleWorkOrders($actor)->whereKey((int) $request->source_id)->lockForUpdate()->first();
        if (!$workOrder) $this->fail('source_id', '生产工单已结束、取消或超出可操作范围，请重新选择。');
        $ids = collect($items)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($ids->count() !== count($items)) $this->fail('items', '生产来源采购需求中同一物料只能填写一行。');
        $materials = Item::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $workOrder->materials()->whereIn('component_item_id', $ids)->lockForUpdate()->get();
        $rows = app(ShortageService::class)->compute($ids->all());
        $gaps = [];
        foreach ($ids as $id) {
            $material = $materials->get($id);
            $demand = collect($rows[$id]['work_orders'] ?? [])->firstWhere('id', (int) $workOrder->id);
            if (!$material || !ShortageService::purchasable($material) || !$demand) {
                $this->fail('items', '所选物料不是该工单仍需采购的外购物料。');
            }
            $own = collect($demand['in_transit_detail']['documents'] ?? [])->filter(fn ($document) => (int) ($document['request_id'] ?? 0) === (int) $request->id
                || ($document['stage'] === 'request' && $document['document_no'] === $request->request_no))->sum('qty');
            $gaps[$id] = round((float) $demand['shortage_qty'] + $own, 4);
        }
        return ['work_order' => $workOrder, 'gaps' => $gaps];
    }

    public function replace(PurchaseRequest $request, ?array $prepared, ?object $actor): void
    {
        if (!$prepared) return;
        $lines = $request->items()->get();
        foreach ($lines as $line) {
            if ((float) $line->request_qty > ($prepared['gaps'][$line->item_id] ?? 0) + 0.00000001) {
                $this->fail('items', $line->item_name.' 的采购需求数量超过所选工单当前缺口，请刷新后核对。');
            }
        }
        $before = MfgPurchaseDemandLink::where('purchase_request_id', $request->id)->lockForUpdate()->get()->toArray();
        MfgPurchaseDemandLink::where('purchase_request_id', $request->id)->delete();
        $name = (string) (($actor->nickname ?? null) ?: ($actor->username ?? '系统'));
        foreach ($lines as $line) {
            MfgPurchaseDemandLink::create(['purchase_request_id' => $request->id, 'purchase_request_item_id' => $line->id,
                'work_order_id' => $prepared['work_order']->id, 'item_id' => $line->item_id, 'qty' => $line->request_qty,
                'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $name]);
        }
        $request->update(['source_no' => $prepared['work_order']->work_order_no]);
        PurchaseLog::create(['target_type' => 'purchase_request', 'target_id' => $request->id, 'action' => 'production_source_allocate',
            'operator' => $name, 'content' => json_encode(['before' => $before, 'work_order_id' => $prepared['work_order']->id,
                'work_order_no' => $prepared['work_order']->work_order_no, 'items' => $lines->map->only(['id', 'item_id', 'request_qty'])->all()], JSON_UNESCAPED_UNICODE)]);
    }

    public function assertCanAdvance(PurchaseRequest $request, ?object $actor): void
    {
        if ($request->source_type !== 'production') return;
        $prepared = $this->prepare($request, $request->items->toArray(), $actor);
        foreach ($request->items as $line) {
            $links = MfgPurchaseDemandLink::where('purchase_request_id', $request->id)->where('purchase_request_item_id', $line->id)
                ->where('work_order_id', (int) $request->source_id)->where('item_id', $line->item_id)->lockForUpdate()->get();
            if (bccomp((string) $links->sum('qty'), (string) $line->request_qty, 4) !== 0) {
                $this->fail('items', '生产来源采购需求缺少有效工单数量分配，请先编辑并核对。');
            }
            if ((float) $line->request_qty > ($prepared['gaps'][$line->item_id] ?? 0) + 0.00000001) {
                $this->fail('items', '生产工单缺口已变化，请重新核对采购需求数量。');
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
