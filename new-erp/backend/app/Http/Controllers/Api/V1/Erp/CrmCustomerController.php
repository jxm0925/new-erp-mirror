<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\CrmCustomerIdentity;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmCustomerApplicationService;
use App\Services\Erp\Crm\CrmCustomerCollaborationService;
use App\Services\Erp\Crm\CrmCustomerDedupeService;
use App\Services\Erp\Crm\CrmPlatformIdentityService;
use App\Services\Erp\Crm\CrmOpportunityApplicationService;
use App\Services\Erp\Crm\CrmSalesStaffService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** 客户中心的 CRM 子资源：平台身份、协作、日志、转交负责销售、查重与销售候选。写操作都经应用服务。 */
final class CrmCustomerController extends Controller
{
    public function __construct(private readonly CrmCustomerAccessService $access) {}

    public function dedupe(Request $request, CrmCustomerDedupeService $dedupe)
    {
        $user = $this->user($request, 'sales.customer');
        $data = $request->validate([
            'customer_kind' => 'nullable|in:enterprise,individual', 'customer_name' => 'nullable|string|max:160',
            'phones' => 'nullable|array|max:10', 'phones.*' => 'nullable|string|max:80', 'wechat' => 'nullable|string|max:80',
            'email' => 'nullable|string|max:160', 'credit_code' => 'nullable|string|max:40', 'region_id' => 'nullable|integer',
            'full_address' => 'nullable|string|max:500', 'platform_legacy_id' => 'nullable|integer', 'platform_buyer_id' => 'nullable|string|max:160',
            'exclude_id' => 'nullable|integer',
        ]);
        $matches = $dedupe->match($data, $data['exclude_id'] ?? null);
        return response()->json(['data' => $dedupe->present($matches, $user)]);
    }

    public function logs(Request $request, int $id)
    {
        [$user, $customer] = $this->visible($request, $id);
        $page = DB::table('erp_crm_customer_logs')->where('customer_id', $customer->id)->orderByDesc('id')
            ->paginate(min(50, max(5, $request->integer('per_page', 10))), ['id', 'action', 'summary', 'reason', 'operator_name', 'created_at']);
        return response()->json($page);
    }

    public function transfer(Request $request, int $id, CrmCustomerApplicationService $service)
    {
        $user = $this->user($request, 'sales.customer.transfer');
        $data = $request->validate([
            'to_user_legacy_id' => 'required|integer|min:1', 'reason' => 'required|string|max:500', 'crm_version' => 'required|integer|min:1',
        ], [], ['to_user_legacy_id' => '新负责销售', 'reason' => '转交原因']);
        $customer = $service->transfer($id, (int) $data['to_user_legacy_id'], $data['reason'], (int) $data['crm_version'], $user);
        return response()->json(['message' => '负责销售已转交；历史订单归属不变', 'data' => $customer]);
    }

    public function collaborators(Request $request, int $id)
    {
        [$user, $customer] = $this->visible($request, $id);
        $rows = $customer->collaborators()->orderByRaw("status = 'active' DESC")->orderByDesc('id')->limit(100)->get();
        $names = collect();
        if ($this->access->can($user, 'crm.opportunity.view')) {
            $ids = $rows->flatMap(fn ($row) => $row->scope['opportunity_ids'] ?? [])->unique()->all();
            $query = CrmOpportunity::query()->where('customer_id', $id)->whereIn('id', $ids);
            app(CrmOpportunityApplicationService::class)->applyVisible($query, $user);
            $names = $query->pluck('name', 'id');
        }
        return response()->json(['data' => $rows->map(function ($row) use ($names) {
            $row->setAttribute('opportunity_names', $names->only($row->scope['opportunity_ids'] ?? [])->values()->all());
            return $row;
        })]);
    }

    public function addCollaborator(Request $request, int $id, CrmCustomerCollaborationService $service)
    {
        $user = $this->user($request, 'sales.customer.collaborate');
        $data = $request->validate([
            'user_legacy_id' => 'required|integer|min:1',
            'scope' => 'nullable|array', 'scope.order_amount' => 'nullable|boolean', 'scope.finance' => 'nullable|boolean',
            'scope.opportunity_ids' => 'nullable|array|max:100', 'scope.opportunity_ids.*' => 'required|integer|min:1|distinct',
            'actions' => 'nullable|array', 'actions.follow' => 'nullable|boolean', 'actions.edit_master' => 'nullable|boolean',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'reason' => 'required|string|max:500',
        ], [], ['user_legacy_id' => '协作人员', 'effective_from' => '生效日期', 'effective_to' => '截止日期', 'reason' => '协作原因']);
        return response()->json(['message' => '协作已添加', 'data' => $service->add($id, $data, $user)], 201);
    }

    public function endCollaborator(Request $request, int $id, int $collabId, CrmCustomerCollaborationService $service)
    {
        $user = $this->user($request, 'sales.customer.collaborate');
        $data = $request->validate(['reason' => 'required|string|max:500'], [], ['reason' => '结束原因']);
        $service->end($id, $collabId, $data['reason'], $user);
        return response()->json(['message' => '协作已结束']);
    }

    public function bindIdentity(Request $request, int $id, CrmPlatformIdentityService $service)
    {
        $user = $this->user($request, 'sales.customer.identity');
        $data = $request->validate([
            'platform_legacy_id' => 'required|integer|min:1', 'buyer_id' => 'required|string|max:160', 'note' => 'nullable|string|max:500',
        ], [], ['platform_legacy_id' => '成交平台', 'buyer_id' => '平台买家ID']);
        $identity = DB::transaction(function () use ($id, $data, $user, $service) {
            app(TradePlatformApplicationService::class)->lockIdentityCounter();
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            $this->access->assert($customer, $user, 'bind_identity', '无权为该客户绑定平台身份。');
            return $service->bind($customer, (int) $data['platform_legacy_id'], $data['buyer_id'], 'manual', $user, $data['note'] ?? null);
        }, 3);
        return response()->json(['message' => '平台身份已绑定', 'data' => $identity], 201);
    }

    public function verifyIdentity(Request $request, int $id, int $identityId, CrmPlatformIdentityService $service)
    {
        $user = $this->user($request, 'sales.customer.identity');
        $data = $request->validate([
            'platform_legacy_id' => 'required|integer|min:1', 'buyer_id' => 'required|string|max:160', 'note' => 'required|string|max:500',
        ], [], ['platform_legacy_id' => '成交平台', 'buyer_id' => '平台买家ID', 'note' => '核实说明']);
        $identity = DB::transaction(function () use ($id, $identityId, $data, $user, $service) {
            app(TradePlatformApplicationService::class)->lockIdentityCounter();
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            $this->access->assert($customer, $user, 'bind_identity', '无权核实该客户的平台身份。');
            $identity = CrmCustomerIdentity::query()->where('customer_id', $id)->lockForUpdate()->findOrFail($identityId);
            return $service->verify($identity, (int) $data['platform_legacy_id'], $data['buyer_id'], $user, $data['note']);
        }, 3);
        return response()->json(['message' => $identity->status === 'confirmed' ? '平台身份已核实' : '该买家ID已属于其他客户，旧值已停用', 'data' => $identity]);
    }

    public function disableIdentity(Request $request, int $id, int $identityId, CrmPlatformIdentityService $service)
    {
        $user = $this->user($request, 'sales.customer.identity');
        $data = $request->validate(['reason' => 'required|string|max:500'], [], ['reason' => '停用原因']);
        DB::transaction(function () use ($id, $identityId, $data, $user, $service) {
            app(TradePlatformApplicationService::class)->lockIdentityCounter();
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
            $this->access->assert($customer, $user, 'bind_identity', '无权停用该客户的平台身份。');
            $service->disable(CrmCustomerIdentity::query()->where('customer_id', $id)->lockForUpdate()->findOrFail($identityId), $user, $data['reason']);
        }, 3);
        return response()->json(['message' => '平台身份已停用']);
    }

    /** 可绑定身份的成交平台（主平台＝店铺）及其买家ID识别方式。 */
    public function platforms(Request $request)
    {
        $this->user($request, 'sales.customer');
        $rows = DB::table('erp_sales_order_trade_platforms')->where('parent_legacy_id', 0)->where('enabled', true)
            ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id', 'name', 'legacy_payload']);
        return response()->json(['data' => $rows->map(fn ($row) => ['id' => (int) $row->legacy_id, 'name' => $row->name]
            + TradePlatformApplicationService::buyerIdentity($row->legacy_payload))->values()]);
    }

    public function staffOptions(Request $request, CrmSalesStaffService $staff)
    {
        $user = $this->user($request, 'sales.customer');
        $data = $request->validate(['keyword' => 'nullable|string|max:80', 'per_page' => 'nullable|integer|min:1|max:50', 'ids' => 'nullable|array|max:50', 'ids.*' => 'integer',
            'collaboration_customer_id' => 'nullable|integer|min:1']);
        $excludeIds = [];
        if (!empty($data['collaboration_customer_id'])) {
            $customer = SalesCustomer::query()->findOrFail((int) $data['collaboration_customer_id']);
            $this->access->assert($customer, $user, 'collaborate', '无权为该客户添加协作。');
            $excludeIds = [(int) $user->legacy_id, (int) $customer->owner_legacy_id];
        }
        return response()->json($staff->options(trim((string) ($data['keyword'] ?? '')), (int) ($data['per_page'] ?? 10), $data['ids'] ?? null, $excludeIds));
    }

    private function visible(Request $request, int $id): array
    {
        $user = $this->user($request, 'sales.customer');
        $customer = SalesCustomer::query()->findOrFail($id);
        abort_unless($this->access->relation($customer, $user) !== null, 403, '无权查看该客户。');
        return [$user, $customer];
    }

    private function user(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录');
        abort_unless($this->access->can($user, $permission), 403, '没有该操作权限。');
        return $user;
    }
}
