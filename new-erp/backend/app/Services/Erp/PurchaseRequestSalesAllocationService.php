<?php

namespace App\Services\Erp;

use App\Models\Erp\{PurchaseLog, PurchaseRequest, PurchaseRequestItem, PurchaseRequestSalesLink, SalesOrder, SalesOrderLine};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PurchaseRequestSalesAllocationService
{
    public function available(): bool
    {
        return Schema::hasTable('erp_purchase_request_sales_links');
    }

    public function eligibleOrders(object $actor): Builder
    {
        $orders = SalesOrder::query()->where('order_status', 'confirmed')->whereNull('cancelled_at');
        app(SalesOrderVisibilityService::class)->apply($orders, $actor);
        return $orders;
    }

    /** 保留旧关联快照；删除重建需求明细时由 FK 清空旧行引用，再在同一事务重绑。 */
    public function existing(PurchaseRequest $request): Collection
    {
        return $this->available() ? PurchaseRequestSalesLink::where('purchase_request_id', $request->id)
            ->where('status', 'active')->orderBy('id')->lockForUpdate()->get() : collect();
    }

    public function prepareHeader(PurchaseRequest $request, ?object $actor, ?Collection $existing = null): void
    {
        if ($request->source_type !== 'sales') return;
        // 旧逐行归属在用户明确选择整单订单前保持原义，不能猜选一个订单覆盖历史。
        if (!$request->source_id && $existing?->isNotEmpty()) return;
        $order = $this->headerOrder($request, $actor);
        $request->update(['source_id' => (string) $order->id, 'source_no' => $order->sales_order_no]);
    }

    public function preserveLegacyLine(PurchaseRequest $request, PurchaseRequestItem $line,
        ?PurchaseRequestItem $prior, Collection $existing, ?object $actor): array
    {
        if ($request->source_type !== 'sales' || $request->source_id) return [];
        abort_unless($actor, 401, '请登录后保存销售采购关联。');
        if (!$prior || (int) $prior->item_id !== (int) $line->item_id
            || bccomp((string) $prior->request_qty, (string) $line->request_qty, 8) !== 0) {
            $this->fail('source_id', '修改旧销售需求的物料或数量前，请先选择本单关联的销售订单。');
        }
        $links = $existing->where('purchase_request_item_id', $prior->id);
        if ($links->isEmpty()) $this->fail('source_id', '请选择本单关联的销售订单。');
        foreach ($links as $link) {
            $orders = SalesOrder::query()->whereKey($link->sales_order_id);
            app(SalesOrderVisibilityService::class)->apply($orders, $actor);
            if (!$orders->lockForUpdate()->first()) $this->fail('source_id', '关联销售订单不存在或超出可操作的数据范围。');
            $link->update(['purchase_request_item_id' => $line->id]);
        }
        return $links->pluck('id')->all();
    }

    public function finishReplacement(PurchaseRequest $request, Collection $existing, array $kept, ?object $actor): void
    {
        foreach ($existing as $link) {
            if (in_array($link->id, $kept, true)) continue;
            if ($request->source_type === 'sales' && !$request->source_id) {
                $this->fail('source_id', '修改旧销售需求的物料前，请先选择本单关联的销售订单。');
            }
            $link->update(['status' => 'reversed', 'purchase_request_item_id' => null,
                'reversed_by' => ($actor->nickname ?? null) ?: ($actor->username ?? $request->created_by), 'reversed_at' => now()]);
            $this->log($request, $actor, 'sales_allocation_reverse', $link);
        }
    }

    public function assertCanAdvance(PurchaseRequest $request, ?object $actor): void
    {
        if ($request->source_type !== 'sales') return;
        abort_unless($actor, 401, '请登录后办理销售来源采购需求。');
        if ($request->items->isEmpty()) $this->fail('items', '销售来源采购需求必须有物料明细。');
        if ($request->source_id) {
            $this->headerOrder($request, $actor);
            return;
        }
        if (!$this->available()) $this->fail('source_id', '请选择本单关联的销售订单。');
        foreach ($request->items as $line) {
            $links = PurchaseRequestSalesLink::where('purchase_request_item_id', $line->id)->where('status', 'active')->lockForUpdate()->get();
            $sum = '0';
            foreach ($links as $link) {
                $order = $this->eligibleOrders($actor)->whereKey($link->sales_order_id)->lockForUpdate()->first();
                $salesLine = SalesOrderLine::where('sales_order_id', $link->sales_order_id)->whereKey($link->sales_order_item_id)->lockForUpdate()->first();
                if (!$order || !$salesLine || in_array($salesLine->line_status, ['removed', 'cancelled', 'replaced'], true)) {
                    $this->fail('sales_allocations', '关联销售订单已失效、取消、关闭或超出可操作范围，请先核对采购需求。');
                }
                $sum = bcadd($sum, $link->qty, 8);
            }
            if (bccomp($sum, sprintf('%.8F', (float) $line->request_qty), 8) !== 0) {
                $this->fail('source_id', '旧销售采购需求缺少完整关联，请编辑并选择本单关联的销售订单。');
            }
        }
    }

    public function assertDocumentSources(iterable $lines, ?object $actor): void
    {
        $lines = collect($lines);
        $ids = $lines->pluck('request_id')->merge(PurchaseRequestItem::whereIn('id', $lines->pluck('request_item_id')->filter())
            ->pluck('request_id'))->filter()->unique()->sort();
        foreach ($ids as $id) {
            $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanAdvance($request, $actor);
            app(PurchaseRequestProductionSourceService::class)->assertCanAdvance($request, $actor);
        }
    }

    private function log(PurchaseRequest $request, ?object $actor, string $action, PurchaseRequestSalesLink $link): void
    {
        PurchaseLog::create(['target_type' => 'purchase_request', 'target_id' => $request->id,
            'action' => $action, 'operator' => ($actor->nickname ?? null) ?: ($actor->username ?? $request->created_by),
            'content' => json_encode(['link_id' => $link->id, 'sales_order_id' => $link->sales_order_id,
                'sales_order_item_id' => $link->sales_order_item_id, 'qty' => $link->qty, 'snapshot' => $link->source_snapshot], JSON_UNESCAPED_UNICODE)]);
    }

    private function headerOrder(PurchaseRequest $request, ?object $actor): SalesOrder
    {
        abort_unless($actor, 401, '请登录后选择销售订单。');
        if (!ctype_digit((string) $request->source_id)) $this->fail('source_id', '请选择已有销售订单。');
        $order = $this->eligibleOrders($actor)->whereKey((int) $request->source_id)->lockForUpdate()->first();
        if (!$order) $this->fail('source_id', '销售订单已取消、关闭、尚未确认或超出可操作范围，请重新选择。');
        return $order;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
