<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\CrmOpportunityOrder;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\SalesAmountVisibilityService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class CrmOpportunityOrderService
{
    public function __construct(
        private readonly CrmOpportunityApplicationService $opportunities,
        private readonly CrmCustomerAccessService $customers,
    ) {}

    public function context(int $id, object $actor): array
    {
        $opportunity = $this->opportunities->visible($id, $actor);
        $this->assertCanOrder($opportunity, $actor);
        $customer = SalesCustomer::query()->findOrFail($opportunity->customer_id);
        abort_unless($this->customers->relation($customer, $actor) !== null, 403, '该客户不在你的可见范围内，请先申请协作。');
        abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或在黑名单，不能创建订单。');
        $contact = $customer->contacts()->where('status', 'enabled')
            ->when($opportunity->contact_id, fn ($q) => $q->whereKey($opportunity->contact_id))
            ->orderByDesc('is_default')->orderBy('id')->first();
        $tradeType = data_get($opportunity->source_snapshot, 'trade_type');
        return [
            'opportunity_id' => $opportunity->id,
            'opportunity' => $opportunity->only(['id', 'opportunity_no', 'name']),
            'customer_id' => $customer->id,
            'customer' => $customer->only(['id', 'customer_code', 'customer_name', 'customer_kind']),
            'customer_name' => $customer->customer_name, 'customer_kind' => $customer->customer_kind,
            'customer_contact_id' => $contact?->id,
            'contact_name' => $contact?->contact_name ?: $customer->contact_name,
            'contact_phone' => $contact?->mobile ?: $contact?->phone ?: $customer->contact_phone,
            'currency' => $opportunity->currency,
            'trade_type' => match ($tradeType) {
                'domestic', '内贸销售', '内贸业务' => 'domestic',
                'foreign', '外贸' => 'foreign',
                default => null,
            },
            'source_trade_type' => $tradeType,
        ];
    }

    public function orders(int $id, array $filters, object $actor): LengthAwarePaginator
    {
        $this->opportunities->visible($id, $actor);
        abort_unless($this->customers->can($actor, 'sales_order.view'), 403, '没有查看销售订单的权限。');
        $query = SalesOrder::query()->with('crmOpportunityOrder')
            ->whereHas('crmOpportunityOrder', fn ($link) => $link->where('opportunity_id', $id));
        app(SalesOrderVisibilityService::class)->apply($query, $actor, true);
        $columns = ['id', 'sales_order_no', 'order_status', 'confirm_status', 'order_date', 'currency', 'total_amount', 'confirmed_at'];
        $rows = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), $columns);
        $amounts = $this->customers->can($actor, 'sales_order.amount.view');
        $rows->through(function (SalesOrder $order) use ($columns, $amounts): array {
            $row = $order->only($columns);
            $row['first_effective_at'] = $order->crmOpportunityOrder?->first_effective_at?->toDateTimeString();
            return $amounts ? $row : app(SalesAmountVisibilityService::class)->redact($row);
        });
        return $rows;
    }

    /** 仅随新订单事务建关联，不提供给既有订单补绑、改绑或解绑的入口。 */
    public function attachNew(SalesOrder $order, mixed $opportunityId, ?object $actor): void
    {
        if (!$opportunityId) return;
        abort_unless($actor, 401, '请先登录 ERP。');
        abort_unless($order->wasRecentlyCreated && $order->order_status === 'draft' && !$order->confirmed_at, 422, '只能在新建订单时关联商机。');
        $opportunity = $this->opportunities->visible((int) $opportunityId, $actor, true);
        $this->assertCanOrder($opportunity, $actor);
        $this->sameCustomer($opportunity, $order->customer_id);
        $link = CrmOpportunityOrder::create([
            'opportunity_id' => $opportunity->id, 'order_id' => $order->id, 'customer_id' => $order->customer_id,
            'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $actor->nickname ?: $actor->username,
        ]);
        $this->opportunities->log($opportunity, 'order_created', '新建关联订单 '.$order->sales_order_no, null,
            ['order_id' => $order->id, 'order_no' => $order->sales_order_no, 'customer_id' => $link->customer_id], null, $actor);
    }

    public function guardOrderUpdate(SalesOrder $order, array &$header): void
    {
        $link = $this->lockedLink($order);
        if (array_key_exists('opportunity_id', $header)) {
            $requested = $header['opportunity_id'] ? (int) $header['opportunity_id'] : null;
            if ($requested !== ($link ? (int) $link->opportunity_id : null)) {
                throw ValidationException::withMessages(['opportunity_id' => '已有订单不能补绑、更换或解除来源商机，请从商机新建订单。']);
            }
        }
        unset($header['opportunity_id']);
        if ($link) $this->assertCustomer($order, $header['customer_id'] ?? $order->customer_id, $link);
    }

    /** 换客先按不可变关联快照拦截，避免在平台身份校验前反向持有商机锁。 */
    public function assertCustomer(SalesOrder $order, mixed $customerId, ?CrmOpportunityOrder $link = null): void
    {
        $link ??= $this->lockedLink($order);
        if (!$link) return;
        if ((int) $order->customer_id !== (int) $link->customer_id || (int) $customerId !== (int) $link->customer_id) {
            throw ValidationException::withMessages(['customer_id' => '关联订单不能更换客户，请从对应客户的商机另建订单。']);
        }
    }

    /** 订单联动统一按订单、关联、客户、商机加锁，商机动作不得反向锁订单。 */
    public function beforeSubmit(SalesOrder $order, ?object $actor): void
    {
        $link = $this->lockedLink($order);
        if (!$link) return;
        abort_unless($actor, 401, '请先登录 ERP。');
        $opportunity = $this->opportunities->visible($link->opportunity_id, $actor, true);
        $this->assertCanOrder($opportunity, $actor);
        $this->sameCustomer($opportunity, $order->customer_id, $link);
    }

    /** 提交人已校验 CRM 范围；正式确认沿原订单授权，不额外要求审批人有 CRM 权限。 */
    public function beforeConfirm(SalesOrder $order): void
    {
        $link = $this->lockedLink($order);
        if (!$link) return;
        SalesCustomer::query()->whereKey($link->customer_id)->lockForUpdate()->firstOrFail();
        $opportunity = CrmOpportunity::query()->lockForUpdate()->findOrFail($link->opportunity_id);
        $this->sameCustomer($opportunity, $order->customer_id, $link);
        abort_if($opportunity->status === 'lost', 422, '来源商机已丢单，不能确认关联订单。');
    }

    public function recordEffective(SalesOrder $order, ?object $actor): void
    {
        $link = $this->lockedLink($order);
        if (!$link) return;
        abort_unless($order->order_status === 'confirmed' && $order->confirm_status === 'confirmed' && $order->confirmed_at, 422, '订单尚未正式生效，不能记录成交。');
        $this->beforeConfirm($order);
        $opportunity = CrmOpportunity::query()->lockForUpdate()->findOrFail($link->opportunity_id);
        if (!$link->first_effective_at) {
            $link->update([
                'first_effective_at' => $order->confirmed_at,
                'first_effective_by_legacy_id' => $actor?->legacy_id,
                'first_effective_by_name' => $order->confirmed_by,
            ]);
            $this->opportunities->log($opportunity, 'order_effective', '关联订单正式生效 '.$order->sales_order_no, null,
                ['order_id' => $order->id, 'order_no' => $order->sales_order_no, 'first_effective_at' => $link->first_effective_at->toDateTimeString()], null, $actor);
        }
        $this->opportunities->markWon($opportunity, $actor, $order->id);
    }

    public function deleteDraftLink(SalesOrder $order, ?object $actor): void
    {
        $link = $this->lockedLink($order);
        if (!$link) return;
        abort_if($link->first_effective_at || $order->confirmed_at, 422, '订单已有正式生效记录，不能删除来源关联。');
        $opportunity = CrmOpportunity::query()->lockForUpdate()->findOrFail($link->opportunity_id);
        $this->opportunities->log($opportunity, 'order_draft_deleted', '删除关联订单草稿 '.$order->sales_order_no,
            ['order_id' => $order->id, 'order_no' => $order->sales_order_no, 'customer_id' => $link->customer_id], null, null, $actor);
        $link->delete();
    }

    private function lockedLink(SalesOrder $order): ?CrmOpportunityOrder
    {
        return CrmOpportunityOrder::query()->where('order_id', $order->id)->lockForUpdate()->first();
    }

    private function assertCanOrder(CrmOpportunity $opportunity, object $actor): void
    {
        abort_unless($this->customers->can($actor, 'sales_order.create') || $this->customers->can($actor, 'sales.order.create'), 403, '没有新建销售订单的权限。');
        abort_unless($this->opportunities->abilities($opportunity, $actor)['order'] ?? false, 403, '当前商机不能新建订单。');
    }

    private function sameCustomer(CrmOpportunity $opportunity, mixed $customerId, ?CrmOpportunityOrder $link = null): void
    {
        if ((int) $customerId !== (int) $opportunity->customer_id || ($link && (int) $customerId !== (int) $link->customer_id)) {
            throw ValidationException::withMessages(['customer_id' => '来源商机与订单必须属于同一客户，关联订单不能更换客户，请另建订单。']);
        }
    }
}
