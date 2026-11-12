<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\CrmLead;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerAccessService;
use App\Services\Erp\Crm\CrmCustomerApplicationService;
use App\Services\Erp\Crm\CrmLeadApplicationService;
use App\Services\Erp\Crm\CrmListViewQueryService;
use App\Services\Erp\Crm\CrmOrderAmountQueryService;
use App\Services\Erp\CustomerRegionService;
use App\Services\Erp\SalesCustomerDeletionApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 客户中心：列表、汇总、负责人选项、详情和增改删全部按 CrmCustomerAccessService 的同一可见范围。
 * picker=1 时搜索全公司：可见客户正常返回，不可见客户只返回脱敏信息且不可选（用于选择客户/查重）。
 */
class SalesCustomerController extends Controller
{
    public function __construct(private readonly CrmCustomerAccessService $access) {}

    public function regions(Request $request, CustomerRegionService $regions)
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录');
        abort_unless($this->access->can($user, 'sales.customer') || $this->access->can($user, 'sales.customer.view') || $this->access->can($user, 'crm.lead.view'), 403, '无地区选择权限。');
        $data = $request->validate(['parent_id' => 'required|integer|min:0']);
        return response()->json($regions->children((int) $data['parent_id']));
    }

    public function regionPath(Request $request, int $id, CustomerRegionService $regions)
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录');
        abort_unless($this->access->can($user, 'sales.customer') || $this->access->can($user, 'sales.customer.view') || $this->access->can($user, 'crm.lead.view'), 403, '无地区选择权限。');
        return response()->json($regions->path($id));
    }

    public function index(Request $request)
    {
        $user = $this->user($request, 'sales.customer');
        $filters = $request->validate([
            'ids' => 'nullable|array|max:1000', 'ids.*' => 'required|integer|min:1|distinct',
            'tab' => 'nullable|in:mine,collab,all', 'picker' => 'nullable|boolean',
            'keyword' => 'nullable|string|max:160', 'status' => 'nullable|string|max:30', 'phone' => 'nullable|string|max:80',
            'customer_kind' => 'nullable|in:enterprise,individual', 'industry' => 'nullable|string|max:80', 'customer_grade' => 'nullable|string|max:40',
            'owner_legacy_id' => 'nullable|integer', 'owner_name' => 'nullable|string|max:80',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ] + CrmListViewQueryService::rules('customers'));
        $picker = !empty($filters['picker']);
        $base = SalesCustomer::query();
        if (!$picker) $this->access->applyVisible($base, $user);
        $tab = $filters['tab'] ?? 'all';
        if ($tab === 'mine') $base->where('owner_legacy_id', (int) $user->legacy_id);
        if ($tab === 'collab') $this->access->applyCollaborating($base, $user);

        $summary = [
            'total' => (clone $base)->count(),
            'enabled' => (clone $base)->where('status', 'enabled')->count(),
            'potential' => (clone $base)->where('status', 'potential')->count(),
            'disabled' => (clone $base)->where('status', 'disabled')->count(),
            'blacklisted' => (clone $base)->where('status', 'blacklisted')->count(),
        ];
        $tabs = $picker ? null : $this->tabCounts($user);
        $query = (clone $base)->with([
            'contacts' => fn ($q) => $q->where('status', 'enabled')->orderByDesc('is_default')->orderBy('id'),
            'addresses' => fn ($q) => $q->where('status', 'enabled')->orderByDesc('is_default')->orderBy('id'),
        ])->latest('updated_at');
        if (isset($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('customer_name', 'like', "%{$keyword}%")->orWhere('customer_code', 'like', "%{$keyword}%")
                ->orWhere('contact_name', 'like', "%{$keyword}%")->orWhere('contact_phone', 'like', "%{$keyword}%"));
        }
        foreach (['status', 'customer_kind', 'industry', 'customer_grade', 'owner_name'] as $field) {
            if (!empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (!empty($filters['owner_legacy_id'])) $query->where('owner_legacy_id', (int) $filters['owner_legacy_id']);
        if ($phone = trim((string) ($filters['phone'] ?? ''))) $query->where('contact_phone', 'like', "%{$phone}%");

        CrmListViewQueryService::apply($query, $filters);
        $page = $query->paginate(isset($filters['ids']) ? max(1, count($filters['ids'])) : min(100, max(5, (int) ($filters['per_page'] ?? 10))));
        $pools = $tags = [];
        if (!$picker && $this->access->can($user, 'crm.lead.view')) {
            $leads = CrmLead::query()->whereIn('customer_id', $page->getCollection()->pluck('id'));
            app(CrmLeadApplicationService::class)->applyVisible($leads, $user);
            foreach ($leads->select('id', 'customer_id', 'customer_pool', 'customer_tags')->lazyById(200) as $lead) {
                if ($lead->customer_pool) $pools[$lead->customer_id][$lead->customer_pool] = $lead->customer_pool;
                foreach ($lead->customer_tags ?? [] as $tag) $tags[$lead->customer_id][$tag] = $tag;
            }
        }
        $money = $picker ? [] : app(CrmOrderAmountQueryService::class)->forCustomers($page->getCollection(), $user);
        $opportunities = $picker ? [] : app(\App\Services\Erp\Crm\CrmCustomerLifecycleQueryService::class)->opportunitySummaries($page->getCollection()->pluck('id')->all(), $user);
        $page->through(fn (SalesCustomer $customer) => $this->row($customer, $user, $picker)
            + ['customer_pools' => array_values($pools[$customer->id] ?? []),
                'customer_tags' => array_values($tags[$customer->id] ?? []),
                'opportunity_summary' => $opportunities[$customer->id] ?? null]
            + ($money[$customer->id] ?? []));
        $payload = $page->toArray();
        $payload['summary'] = $summary;
        $payload['tabs'] = $tabs;
        $ownerBase = SalesCustomer::query();
        $this->access->applyVisible($ownerBase, $user);
        $payload['owner_options'] = $ownerBase->whereNotNull('owner_name')->where('owner_name', '<>', '')
            ->distinct()->orderBy('owner_name')->pluck('owner_name')->values();
        return response()->json($payload);
    }

    public function show(Request $request, int $id)
    {
        $user = $this->user($request, 'sales.customer');
        $customer = SalesCustomer::with([
            'contacts' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id'),
            'addresses' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id'),
            'identities' => fn ($q) => $q->where('status', '<>', 'disabled')->orderBy('id'),
            'collaborators' => fn ($q) => $q->where('status', 'active')->orderBy('id'),
        ])->findOrFail($id);
        $abilities = $this->access->abilities($customer, $user);
        abort_unless($abilities['view'], 403, '该客户由其他部门负责，你无权查看详情，可申请协作。');
        $money = app(CrmOrderAmountQueryService::class)->forCustomers(collect([$customer]), $user);
        return response()->json($customer->toArray() + [
            'owner_department_name' => $this->access->departmentName($customer->owner_department_legacy_id),
            'abilities' => $abilities,
            'overview' => app(\App\Services\Erp\Crm\CrmCustomerLifecycleQueryService::class)->overview($customer, $user),
        ] + $money[$customer->id]);
    }

    public function store(Request $request, CrmCustomerApplicationService $service)
    {
        $user = $this->user($request, 'sales.customer.create');
        $data = $this->validated($request);
        $customer = $service->create($data, $user, $data['reservation_token'] ?? null, $data['creation_session_id'] ?? null);
        return response()->json(['message' => '客户已新增', 'data' => $customer], 201);
    }

    public function update(Request $request, int $id, CrmCustomerApplicationService $service)
    {
        $user = $this->user($request, 'sales.customer.edit');
        $customer = $service->update($id, $this->validated($request, $id), $user);
        return response()->json(['message' => '客户已更新；历史订单快照未被修改', 'data' => $customer]);
    }

    public function destroy(Request $request, int $id, SalesCustomerDeletionApplicationService $service)
    {
        $user = $this->user($request, 'sales.customer.delete');
        $this->access->assert(SalesCustomer::query()->findOrFail($id), $user, 'delete', '无权删除该客户。');
        $service->delete($id);
        return response()->json(['message' => '未被业务引用的客户档案已删除。']);
    }

    private function row(SalesCustomer $customer, object $user, bool $picker): array
    {
        $relation = $this->access->relation($customer, $user);
        if ($relation === null) return $this->access->masked($customer) + ['selectable' => false];
        return $customer->toArray() + [
            'visible' => true, 'relation' => $relation,
            'abilities' => $this->access->abilities($customer, $user),
            'selectable' => in_array($customer->status, ['enabled', 'potential'], true),
            'owner_department_name' => $this->access->departmentName($customer->owner_department_legacy_id),
        ];
    }

    private function tabCounts(object $user): array
    {
        $visible = SalesCustomer::query();
        $this->access->applyVisible($visible, $user);
        $collab = SalesCustomer::query();
        $this->access->applyCollaborating($collab, $user);
        return [
            'mine' => SalesCustomer::query()->where('owner_legacy_id', (int) $user->legacy_id)->count(),
            'collab' => $collab->count(),
            'all' => $visible->count(),
        ];
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'reservation_token' => $id ? 'prohibited' : 'nullable|required_with:creation_session_id|uuid',
            'creation_session_id' => $id ? 'prohibited' : 'nullable|required_with:reservation_token|uuid',
            'crm_version' => $id ? 'required|integer|min:1' : 'prohibited',
            'customer_name' => 'required|string|max:160', 'customer_short_name' => 'nullable|string|max:160', 'customer_type' => 'nullable|string|max:40',
            'customer_kind' => ['required', Rule::in(['enterprise', 'individual'])],
            'status' => 'required|in:enabled,disabled,blacklisted,potential', 'remark' => 'nullable|string',
            'company_background' => 'nullable|string|max:5000', 'customer_grade' => 'nullable|string|max:40', 'industry' => 'nullable|string|max:80',
            'purchasing_power' => 'nullable|string|max:40', 'business_lines' => 'nullable|array|max:20', 'business_lines.*' => 'string|max:60',
            'credit_code' => 'nullable|string|max:40', 'region_id' => 'nullable|integer|exists:erp_regions,id',
            'duplicate_confirmed' => 'nullable|boolean', 'duplicate_reason' => 'nullable|required_if:duplicate_confirmed,true|string|max:500',
            'invoice_type' => 'nullable|in:vat_normal,vat_special', 'invoice_title' => 'nullable|string|max:200', 'invoice_tax_no' => 'nullable|string|max:60',
            'invoice_address_phone' => 'nullable|string|max:300', 'invoice_bank_account' => 'nullable|string|max:300', 'invoice_email' => 'nullable|email|max:160',
            'contacts' => 'nullable|array', 'contacts.*.id' => 'nullable|integer', 'contacts.*.contact_name' => 'required|string|max:80', 'contacts.*.mobile' => 'nullable|string|max:80', 'contacts.*.phone' => 'nullable|string|max:80', 'contacts.*.email' => 'nullable|email|max:160', 'contacts.*.wechat' => 'nullable|string|max:80', 'contacts.*.position' => 'nullable|string|max:80', 'contacts.*.is_default' => 'boolean', 'contacts.*.status' => 'nullable|in:enabled,disabled', 'contacts.*.remark' => 'nullable|string',
            'addresses' => 'nullable|array', 'addresses.*.id' => 'nullable|integer', 'addresses.*.receiver_name' => 'required|string|max:80', 'addresses.*.receiver_phone' => 'nullable|string|max:80', 'addresses.*.country' => 'nullable|string|max:80', 'addresses.*.region_id' => 'nullable|integer|min:1', 'addresses.*.province' => 'nullable|string|max:80', 'addresses.*.city' => 'nullable|string|max:80', 'addresses.*.district' => 'nullable|string|max:80', 'addresses.*.detail_address' => 'required|string|max:500', 'addresses.*.is_default' => 'boolean', 'addresses.*.status' => 'nullable|in:enabled,disabled', 'addresses.*.remark' => 'nullable|string',
        ], [], [
            'customer_name' => '客户名称', 'customer_short_name' => '客户简称', 'credit_code' => '统一社会信用代码', 'duplicate_reason' => '新建原因',
            'invoice_title' => '发票抬头', 'invoice_tax_no' => '纳税人识别号', 'invoice_address_phone' => '开票地址电话', 'invoice_bank_account' => '开户行及账号', 'invoice_email' => '收票邮箱',
            'contacts.*.contact_name' => '联系人姓名', 'contacts.*.mobile' => '联系人手机', 'contacts.*.phone' => '联系方式2', 'contacts.*.email' => '联系人邮箱', 'contacts.*.wechat' => '微信号',
            'addresses.*.receiver_name' => '收货人', 'addresses.*.receiver_phone' => '收货电话', 'addresses.*.province' => '省份', 'addresses.*.city' => '城市',
            'addresses.*.district' => '区县', 'addresses.*.detail_address' => '详细地址',
        ]);
        foreach ($data['addresses'] ?? [] as $index => $address) {
            if (!empty($address['region_id'])) {
                $data['addresses'][$index] = array_merge($address, app(CustomerRegionService::class)->address((int) $address['region_id'], $address['detail_address']));
                continue;
            }
            $old = $id && !empty($address['id'])
                ? SalesCustomerAddress::where('customer_id', $id)->find($address['id']) : null;
            // 未关联地区的历史地址允许原样保存；新增或改地址必须选择真实地区。
            $unchanged = $old && !$old->region_id;
            foreach (['country', 'province', 'city', 'district', 'detail_address'] as $field) {
                $unchanged = $unchanged && trim((string) ($address[$field] ?? '')) === trim((string) $old?->$field);
            }
            if (!$unchanged) {
                throw ValidationException::withMessages(["addresses.$index.region_id" => '请选择收货地区。']);
            }
            $data['addresses'][$index]['full_address'] = $old->full_address;
        }
        return $data;
    }

    private function user(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录');
        abort_unless($this->access->can($user, $permission), 403, '无客户管理权限');
        return $user;
    }
}
