<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\{Bom, Item, PurchaseOrder, PurchasePlan, PurchaseReceipt, PurchaseRequest, Sku};
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Module-wide operational facts; personal approval assignments remain in approvals/tasks. */
class ConsoleDashboardQueryService
{
    public function dashboard(array $filters, array $codes, bool $super = false): array
    {
        // One read snapshot prevents a concurrent submit/post from producing
        // mismatched counts and rows in this response. No business writes occur.
        return DB::transaction(fn () => $this->projection($filters, $codes, $super));
    }

    private function projection(array $filters, array $codes, bool $super): array
    {
        $can = fn (string $code): bool => $super || in_array($code, $codes, true);
        $now = CarbonImmutable::now(config('app.timezone'));
        $queries = $this->todoQueries($can);
        $counts = array_map(fn ($q) => $q?->count(), $queries);
        $todo = $this->todos($queries, $filters);
        $master = $this->master($can, $now);
        $purchase = $this->purchase($can, $now, $counts);
        $inventory = $this->inventory($can, $now, $counts);
        $bom = $this->bom($can, $now, $counts);
        return [
            'as_of' => $now->toIso8601String(), 'timezone' => $now->timezoneName,
            'counts' => $counts, 'total_todo' => array_sum($counts), 'todos' => $todo,
            'master' => $master, 'purchase' => $purchase, 'inventory' => $inventory, 'bom' => $bom,
            'warnings' => $this->warnings($can, $master), 'warning_available' => $can('sku_item_relation.view') || $can('purchase.quality.view') || $can('inventory.alert.view'),
            'recent' => $this->recent($can),
            'trends' => [
                'purchase' => $can('purchase.order.view') ? $this->trend(PurchaseOrder::query()->where('audit_status', 'approved')->where('purchase_status', '<>', 'cancelled')->toBase(), 'created_at', $now) : null,
                'inventory' => $can('inventory.transaction.view') ? $this->trend(DB::table('erp_inventory_transactions')->where('posting_status', 'posted'), 'posted_at', $now) : null,
            ],
        ];
    }

    private function todoQueries(callable $can): array
    {
        // A draft's default audit_status=pending is not a submitted approval.
        // The same builders feed counts and paginated details to prevent drift.
        return [
            'requests' => $can('purchase.request.view') ? PurchaseRequest::query()->where('request_status', 'draft')->toBase() : null,
            'plans' => $can('purchase.plan.view') ? PurchasePlan::query()->where('plan_status', 'submitted')->where('audit_status', 'pending')->toBase() : null,
            'orders' => $can('purchase.order.view') ? PurchaseOrder::query()->where('purchase_status', 'submitted')->where('audit_status', 'pending')->toBase() : null,
            'receipts' => $can('inventory.post.view') ? PurchaseReceipt::query()->where('stock_post_status', 'pending')->where('receipt_status', 'confirmed')->where('confirm_status', 'confirmed')
                ->whereHas('items', fn ($q) => $q->where('is_stock_item_snapshot', true)->where('final_stockable_base_qty', '>', 0))->toBase() : null,
            'adjustments' => $can('inventory.adjustment.view') ? DB::table('erp_inventory_adjustments')->where('adjustment_status', 'submitted') : null,
            'boms' => $can('bom.manage.view') ? Bom::query()->where(fn ($q) => $q->where(fn ($q) => $q->where('audit_status', 'pending')->whereNotNull('submitted_at'))
                ->orWhere(fn ($q) => $q->where('audit_status', 'approved')->whereNotIn('status', ['active', 'enabled', 'published', 'archived'])))->toBase() : null,
        ];
    }

    private function todos(array $queries, array $filters): array
    {
        $meta = [
            'requests' => ['erp_purchase_requests', 'request_no', 'created_by', 'created_at', '采购需求', '待确认', '去确认', '新增采购需求待确认'],
            'plans' => ['erp_purchase_plans', 'plan_no', 'created_by', 'updated_at', '采购计划', '待审核', '去审核', '采购计划等待审核'],
            'orders' => ['erp_purchase_orders', 'purchase_order_no', null, 'updated_at', '采购订单', '待审核', '查看审核', '采购订单等待审核'],
            'receipts' => ['erp_purchase_receipts', 'receipt_no', null, 'updated_at', '采购到货', '待过账', '去过账', '物料等待库存过账'],
            'adjustments' => ['erp_inventory_adjustments', 'adjustment_no', 'submitted_by', 'submitted_at', '库存调整', '待处理', '去处理', '库存调整等待确认过账'],
            'boms' => ['erp_boms', 'bom_no', 'updated_by', 'submitted_at', 'BOM', '待处理', '查看BOM', 'BOM等待审核或启用'],
        ];
        $union = null;
        foreach ($queries as $type => $query) {
            if (!$query || (!empty($filters['type']) && $filters['type'] !== $type)) continue;
            if (($filters['priority'] ?? '') === 'high') {
                if ($type !== 'requests') continue;
                $query = (clone $query)->whereIn('priority', ['high', 'urgent']);
            }
            [$table, $no, $owner, $time] = $meta[$type];
            $q = (clone $query)->select("{$table}.id", "{$table}.{$no} as no")
                ->selectRaw('? AS kind', [$type])->selectRaw("COALESCE({$table}.{$time}, {$table}.created_at) AS happened_at");
            $owner ? $q->selectRaw("{$table}.{$owner} AS owner") : $q->selectRaw('NULL AS owner');
            $type === 'requests' ? $q->addSelect("{$table}.priority as priority_value") : $q->selectRaw('NULL AS priority_value');
            if (in_array($type, ['plans', 'orders', 'receipts'], true)) {
                $target = ['plans' => 'purchase_plan', 'orders' => 'purchase_order', 'receipts' => 'purchase_receipt'][$type];
                $action = $type === 'receipts' ? 'confirm' : 'submit';
                $log = DB::table('erp_purchase_logs')->whereColumn('target_id', "{$table}.id")->where('target_type', $target)->where('action', $action)->orderByDesc('id')->limit(1);
                $q->selectSub((clone $log)->select('created_at'), 'state_time')->selectSub((clone $log)->select('operator'), 'state_owner');
            } else $q->selectRaw('NULL AS state_time, NULL AS state_owner');
            $type === 'receipts'
                ? $q->selectSub(DB::table('erp_purchase_receipt_items')->whereColumn('receipt_id', "{$table}.id")->selectRaw('COUNT(DISTINCT item_id)'), 'item_count')
                : $q->selectRaw('0 AS item_count');
            $union ? $union->unionAll($q) : $union = $q;
        }
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 8);
        if (!$union) return ['data' => [], 'total' => 0, 'current_page' => $page, 'per_page' => $perPage];
        $result = DB::query()->fromSub($union, 'todos')->orderByRaw('COALESCE(state_time, happened_at) ASC')->orderBy('kind')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $result->setCollection($result->getCollection()->map(function ($r) use ($meta) {
            [, , , , $type, $status, $action, $summary] = $meta[$r->kind];
            $priority = match ($r->priority_value) { 'urgent' => '紧急', 'high' => '高', 'normal' => '中', 'low' => '低', default => '未设置' };
            $to = match ($r->kind) {
                'receipts' => ['path' => '/inventory/posting', 'query' => ['keyword' => $r->no, 'receipt_id' => $r->id]],
                'adjustments' => ['path' => '/inventory/adjustments', 'query' => ['adjustment_no' => $r->no]],
                'boms' => ['path' => "/bom/{$r->id}/detail"],
                default => ['path' => "/purchase/{$r->kind}/{$r->id}/detail"],
            };
            return ['id' => "{$r->kind}-{$r->id}", 'kind' => $r->kind, 'type' => $type, 'no' => $r->no,
                'priority' => $priority, 'statusText' => $status, 'action' => $action, 'to' => $to,
                'summary' => $r->kind === 'receipts' ? "{$r->item_count}种物料等待库存过账" : $summary,
                'owner' => $this->actor($r->state_owner ?: $r->owner), 'time' => $this->iso($r->state_time ?: $r->happened_at)];
        }));
        return $result->toArray();
    }

    private function master(callable $can, CarbonImmutable $now): array
    {
        $physical = Sku::query()->where('order_line_type', 'physical');
        $primary = fn ($q) => $q->where('status', 'active')->where('is_primary', true);
        $normal = (clone $physical)->has('itemRelations', '=', 1, 'and', $primary)
            ->whereHas('itemRelations', fn ($q) => $primary($q)->whereHas('item', fn ($i) => $i->where('status', 'enabled')));
        $relation = $can('sku_item_relation.view');
        $physicalCount = $relation ? $physical->count() : null;
        $configured = $relation ? $normal->count() : null;
        return [
            'products' => $can('master.product.view') ? DB::table('erp_products')->count() : null,
            'skus' => $can('master.sku.view') ? Sku::query()->count() : null,
            'items' => $can('master.item.view') ? Item::query()->count() : null,
            'disabled_items' => $can('master.item.view') ? Item::query()->whereIn('status', ['disabled', 'inactive'])->count() : null,
            'physical_skus' => $physicalCount, 'configured_skus' => $configured,
            'missing_skus' => $relation ? (clone $physical)->whereDoesntHave('itemRelations', $primary)->count() : null,
            'abnormal_skus' => $relation ? $physicalCount - $configured : null,
            'relation_rate' => $relation ? ($physicalCount ? round($configured / $physicalCount * 100, 1).'%' : '0%') : null,
        ];
    }

    private function purchase(callable $can, CarbonImmutable $now, array $counts): array
    {
        $period = fn ($q) => $q->where('created_at', '>=', $now->startOfMonth())->where('created_at', '<', $now->startOfMonth()->addMonth());
        $orders = $can('purchase.order.view');
        $money = $orders ? $period(PurchaseOrder::query())->where('audit_status', 'approved')->where('purchase_status', '<>', 'cancelled')
            ->select('currency')->selectRaw('SUM(total_amount) AS amount')->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'amount' => Money::normalize((string) $r->amount)])->all() : null;
        return [
            'requests_month' => $can('purchase.request.view') ? $period(PurchaseRequest::query())->count() : null,
            'orders_month' => $orders ? $period(PurchaseOrder::query())->count() : null,
            'awaiting_delivery' => $orders ? PurchaseOrder::query()->where('audit_status', 'approved')->whereNotIn('purchase_status', ['draft', 'submitted', 'closed', 'cancelled'])->whereIn('receipt_status', ['not_received', 'partial'])->count() : null,
            'receipts_to_post' => $counts['receipts'], 'approved_amounts_month' => $money,
            'defects' => $can('purchase.quality.view') ? DB::table('erp_purchase_defect_handlings')->whereNotIn('handling_status', ['completed', 'cancelled'])->count() : null,
        ];
    }

    private function inventory(callable $can, CarbonImmutable $now, array $counts): array
    {
        $balances = $can('inventory.balance.view');
        $units = $balances ? DB::table('erp_inventory_balances as b')->leftJoin('erp_units as u', 'u.id', '=', 'b.unit_id')
            ->select('b.unit_id', 'u.unit_name')->selectRaw('SUM(b.quantity_on_hand) AS quantity')->groupBy('b.unit_id', 'u.unit_name')->get()->all() : null;
        $today = DB::table('erp_inventory_transactions')->where('posting_status', 'posted')->where('posted_at', '>=', $now->startOfDay())->where('posted_at', '<', $now->startOfDay()->addDay());
        return [
            'stock_items' => $balances ? DB::table('erp_inventory_balances')->where('quantity_on_hand', '>', 0)->distinct()->count('item_id') : null,
            'quantity_by_unit' => $units,
            'today_in' => $can('inventory.transaction.view') ? (clone $today)->where('transaction_type', 'purchase_receipt_posting')->count() : null,
            'today_transactions' => $can('inventory.transaction.view') ? $today->count() : null,
            'adjustments' => $counts['adjustments'],
            'alerts' => $can('inventory.alert.view') ? DB::table('erp_inventory_alerts')->where('is_active', true)->count() : null,
        ];
    }

    private function bom(callable $can, CarbonImmutable $now, array $counts): ?array
    {
        if (!$can('bom.manage.view')) return null;
        $eligible = fn () => app(BomMatcher::class)->eligibleQuery($now->toDateString());
        // Match the same output Item and exact Product/SKU or Item-only scope
        // accepted by BomMatcher. A valid Item-only default also serves its SKU.
        $needsDefault = Sku::query()->where('is_need_bom', true)->where('status', 'enabled')
            ->whereDoesntHave('itemRelations', function ($relations) use ($eligible) {
                $relations->where('status', 'active')->where('is_primary', true)->whereHas('item', fn ($q) => $q->where('status', 'enabled'))
                    ->whereExists($eligible()->where('is_default', true)->whereColumn('output_item_id', 'erp_sku_item_relations.item_id')
                        ->where(fn ($q) => $q->where(fn ($q) => $q->whereColumn('product_id', 'erp_skus.product_id')->whereColumn('sku_id', 'erp_skus.id'))
                            ->orWhere(fn ($q) => $q->whereNull('product_id')->whereNull('sku_id')))->toBase());
            });
        return [
            'total' => Bom::query()->count(), 'active' => $eligible()->count(),
            'pending' => Bom::query()->where('audit_status', 'pending')->whereNotNull('submitted_at')->count(),
            'defaults' => $eligible()->where('is_default', true)->count(),
            'expiring' => $eligible()->whereBetween('expire_date', [$now->toDateString(), $now->addDays(7)->toDateString()])->count(),
            'missing_defaults' => $can('master.sku.view') ? $needsDefault->count() : null,
        ];
    }

    private function warnings(callable $can, array $master): array
    {
        $rows = [];
        // Waiting is already represented as a todo. Without an SLA there is no
        // factual basis for declaring the first waiting receipt "serious".
        if (($master['abnormal_skus'] ?? 0) > 0) $rows[] = ['id' => 'sku-relation', 'level' => '提醒', 'module' => '主数据', 'title' => 'SKU默认Item关系异常', 'object' => $master['abnormal_skus'].'个实物SKU', 'desc' => '缺少、重复或停用的默认Item关系需核对。', 'time' => null, 'action' => '去关联', 'to' => '/master/sku-item-relations'];
        if ($can('purchase.quality.view')) {
            $count = DB::table('erp_purchase_defect_handlings')->whereNotIn('handling_status', ['completed', 'cancelled'])->count();
            if ($count) $rows[] = ['id' => 'defects', 'level' => '警告', 'module' => '采购', 'title' => '不合格到货待处理', 'object' => $count.'项处理单', 'desc' => '存在尚未完成的不合格品处理。', 'time' => null, 'action' => '去处理', 'to' => '/purchase/defects'];
        }
        if ($can('inventory.alert.view')) {
            $count = DB::table('erp_inventory_alerts')->where('is_active', true)->count();
            if ($count) $rows[] = ['id' => 'inventory-alerts', 'level' => '警告', 'module' => '库存', 'title' => '库存预警', 'object' => $count.'项有效预警', 'desc' => '依据正式库存预警规则触发。', 'time' => null, 'action' => '查看预警', 'to' => '/inventory/alerts'];
        }
        return $rows;
    }

    private function trend(Builder $query, string $field, CarbonImmutable $now): array
    {
        $daily = $query->where($field, '>=', $now->startOfDay()->subDays(29))->where($field, '<', $now->startOfDay()->addDay())
            ->selectRaw("DATE({$field}) AS day, COUNT(*) AS count")->groupByRaw("DATE({$field})")->pluck('count', 'day');
        $rows = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = $now->subDays($i)->toDateString();
            $rows[] = ['date' => $day, 'count' => (int) ($daily[$day] ?? 0)];
        }
        return $rows;
    }

    private function recent(callable $can): array
    {
        $rows = collect();
        foreach (['purchase_request' => ['purchase.request.view', 'erp_purchase_requests', 'request_no'], 'purchase_plan' => ['purchase.plan.view', 'erp_purchase_plans', 'plan_no'],
            'purchase_order' => ['purchase.order.view', 'erp_purchase_orders', 'purchase_order_no'], 'purchase_receipt' => ['purchase.receipt.view', 'erp_purchase_receipts', 'receipt_no']] as $type => [$permission, $table, $no]) {
            if (!$can($permission)) continue;
            $q = DB::table('erp_purchase_logs as l')->join("{$table} as d", 'd.id', '=', 'l.target_id')->where('l.target_type', $type);
            if ($type === 'purchase_request') $q->whereNull('d.deleted_at');
            $rows = $rows->concat($q->orderByDesc('l.created_at')->orderByDesc('l.id')->limit(10)->get(['l.created_at as time', 'l.operator', 'l.content', "d.{$no} as object"])->map(fn ($r) => [...(array) $r, 'module' => '采购']));
        }
        if ($can('bom.manage.view')) $rows = $rows->concat(DB::table('erp_bom_logs as l')->join('erp_boms as b', 'b.id', '=', 'l.bom_id')->orderByDesc('l.created_at')->orderByDesc('l.id')->limit(10)
            ->get(['l.created_at as time', 'l.created_by as operator', 'l.message as content', 'b.bom_no as object'])->map(fn ($r) => [...(array) $r, 'module' => 'BOM']));
        if ($can('inventory.transaction.view')) $rows = $rows->concat(DB::table('erp_inventory_transactions')->where('posting_status', 'posted')->whereNotNull('posted_at')->orderByDesc('posted_at')->orderByDesc('id')->limit(10)
            ->get(['posted_at as time', 'posted_by as operator', 'transaction_no as object'])->map(fn ($r) => [...(array) $r, 'content' => '库存事务已过账', 'module' => '库存']));
        return $rows->sortByDesc('time')->take(10)->map(fn ($r) => [...$r, 'time' => $this->iso($r['time']), 'operator' => $this->actor($r['operator'])])->values()->all();
    }

    private function actor(string|int|null $value): string
    {
        if (!$value) return '未记录';
        if (!ctype_digit((string) $value)) return (string) $value;
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', (int) $value)->first(['nickname', 'username']);
        return $user ? ($user->nickname ?: $user->username) : '未记录';
    }

    private function iso(?string $time): ?string
    {
        return $time ? CarbonImmutable::parse($time, config('app.timezone'))->toIso8601String() : null;
    }
}
