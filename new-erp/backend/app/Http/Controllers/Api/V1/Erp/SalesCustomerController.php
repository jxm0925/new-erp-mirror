<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\SalesCustomerDeletionApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesCustomerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize($request, 'sales.customer');
        $summary = [
            'total' => SalesCustomer::count(),
            'enabled' => SalesCustomer::where('status', 'enabled')->count(),
            'potential' => SalesCustomer::where('status', 'potential')->count(),
            'disabled' => SalesCustomer::where('status', 'disabled')->count(),
            'blacklisted' => SalesCustomer::where('status', 'blacklisted')->count(),
        ];
        $query = SalesCustomer::query()->with([
            'contacts' => fn ($q) => $q->where('status', 'enabled')->orderByDesc('is_default')->orderBy('id'),
            'addresses' => fn ($q) => $q->where('status', 'enabled')->orderByDesc('is_default')->orderBy('id'),
        ])->latest('updated_at');
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('customer_name', 'like', "%{$keyword}%")->orWhere('customer_code', 'like', "%{$keyword}%")->orWhere('contact_phone', 'like', "%{$keyword}%"));
        }
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('owner_legacy_id')) $query->where('owner_legacy_id', $request->integer('owner_legacy_id'));
        if ($request->filled('owner_name')) $query->where('owner_name', $request->input('owner_name'));
        if ($request->filled('phone')) {
            $phone = trim((string) $request->input('phone'));
            $query->where('contact_phone', 'like', "%{$phone}%");
        }
        if ($request->filled('customer_kind')) {
            $query->where('customer_kind', $request->input('customer_kind'));
        }
        $page = $query->paginate(min(100, max(5, $request->integer('per_page', 10))));
        $payload = $page->toArray();
        $payload['summary'] = $summary;
        $payload['owner_options'] = SalesCustomer::query()
            ->whereNotNull('owner_name')->where('owner_name', '<>', '')
            ->distinct()->orderBy('owner_name')->pluck('owner_name')->values();
        return response()->json($payload);
    }

    public function show(Request $request, int $id)
    {
        $this->authorize($request, 'sales.customer');
        return response()->json(SalesCustomer::with(['contacts' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id'), 'addresses' => fn ($q) => $q->orderByDesc('is_default')->orderBy('id')])->findOrFail($id));
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        $this->authorize($request, 'sales.customer.create');
        return DB::transaction(function () use ($request, $numbers) {
            $data = $this->validated($request);
            $reservationToken = $data['reservation_token'] ?? null;
            $operatorId = app(AuthContextService::class)->currentUser($request)?->legacy_id;
            $contacts = $data['contacts'] ?? [];
            $addresses = $data['addresses'] ?? [];
            $creationSessionId = $data['creation_session_id'] ?? null;
            unset($data['contacts'], $data['addresses'], $data['reservation_token'], $data['creation_session_id']);
            $data = $this->withDedupeIdentity($data);
            $data['customer_code'] = $reservationToken
                ? $numbers->reservedNumber($reservationToken, 'customer', $operatorId, $creationSessionId)
                : ($data['customer_code'] ?? $numbers->next('customer'));
            $customer = SalesCustomer::create($data);
            $this->syncChildren($customer, $contacts, $addresses);
            if ($reservationToken) $numbers->consume($reservationToken, 'customer', $customer->customer_code, $operatorId, 'customer', $customer->id);
            return response()->json(['message' => '客户已新增', 'data' => $customer->fresh(['contacts', 'addresses'])], 201);
        });
    }

    public function update(Request $request, int $id)
    {
        $this->authorize($request, 'sales.customer.edit');
        return DB::transaction(function () use ($request, $id) {
            $customer = SalesCustomer::findOrFail($id);
            $data = $this->validated($request, $id);
            $contacts = $data['contacts'] ?? [];
            $addresses = $data['addresses'] ?? [];
            unset($data['contacts'], $data['addresses']);
            $data = $this->withDedupeIdentity($data);
            $customer->update($data);
            $this->syncChildren($customer, $contacts, $addresses);
            return response()->json(['message' => '客户已更新；历史订单快照未被修改', 'data' => $customer->fresh(['contacts', 'addresses'])]);
        });
    }

    public function destroy(Request $request, int $id, SalesCustomerDeletionApplicationService $service)
    {
        $this->authorize($request, 'sales.customer.delete');
        $service->delete($id);
        return response()->json(['message' => '未被业务引用的客户档案已删除。']);
    }

    private function syncChildren(SalesCustomer $customer, array $contacts, array $addresses): void
    {
        $this->sync($customer, $contacts, SalesCustomerContact::class, ['contact_name', 'mobile', 'phone', 'email', 'position', 'is_default', 'status', 'remark']);
        $this->sync($customer, $addresses, SalesCustomerAddress::class, ['receiver_name', 'receiver_phone', 'province', 'city', 'district', 'detail_address', 'full_address', 'is_default', 'status', 'remark']);
        $defaultContact = $customer->contacts()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $defaultAddress = $customer->addresses()->where('status', 'enabled')->orderByDesc('is_default')->first();
        $customer->update([
            'contact_name' => $defaultContact?->contact_name,
            'contact_phone' => $defaultContact?->mobile ?: $defaultContact?->phone,
            'full_address' => $defaultAddress?->full_address,
            'dedupe_name_key' => $this->dedupeKey($customer->customer_name),
            'dedupe_address_key' => $this->dedupeKey($defaultAddress?->full_address),
        ]);
    }

    private function sync(SalesCustomer $customer, array $rows, string $model, array $allowed): void
    {
        $keep = [];
        foreach ($rows as $index => $row) {
            $payload = array_intersect_key($row, array_flip($allowed));
            $payload['is_default'] = !empty($row['is_default']);
            if ($model === SalesCustomerAddress::class) $payload['full_address'] = trim(implode(' ', array_filter([$payload['province'] ?? null, $payload['city'] ?? null, $payload['district'] ?? null, $payload['detail_address'] ?? null])));
            $record = !empty($row['id']) ? $model::where('customer_id', $customer->id)->findOrFail($row['id']) : new $model(['customer_id' => $customer->id]);
            $record->fill($payload)->save();
            $keep[] = $record->id;
        }
        if ($rows !== []) $model::where('customer_id', $customer->id)->whereNotIn('id', $keep)->update(['status' => 'disabled', 'is_default' => false]);
        $default = $model::where('customer_id', $customer->id)->where('is_default', true)->where('status', 'enabled')->orderBy('id')->first();
        if ($default) $model::where('customer_id', $customer->id)->whereKeyNot($default->id)->update(['is_default' => false]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'customer_code' => ['nullable', 'string', 'max:80', Rule::unique('erp_sales_customers', 'customer_code')->ignore($id)],
            'reservation_token' => $id ? 'prohibited' : 'nullable|required_with:creation_session_id|uuid',
            'creation_session_id' => $id ? 'prohibited' : 'nullable|required_with:reservation_token|uuid',
            'customer_name' => 'required|string|max:160', 'customer_short_name' => 'nullable|string|max:160', 'customer_type' => 'nullable|string|max:40',
            'customer_kind' => ['nullable', Rule::in(['enterprise', 'individual'])], 'source_platform' => 'nullable|string|max:160', 'platform_buyer_id' => 'nullable|string|max:160',
            'owner_legacy_id' => 'nullable|integer', 'owner_name' => 'nullable|string|max:80', 'status' => 'required|in:enabled,disabled,blacklisted,potential', 'remark' => 'nullable|string',
            'contacts' => 'nullable|array', 'contacts.*.id' => 'nullable|integer', 'contacts.*.contact_name' => 'required|string|max:80', 'contacts.*.mobile' => 'nullable|string|max:40', 'contacts.*.phone' => 'nullable|string|max:40', 'contacts.*.email' => 'nullable|email|max:160', 'contacts.*.position' => 'nullable|string|max:80', 'contacts.*.is_default' => 'boolean', 'contacts.*.status' => 'nullable|in:enabled,disabled', 'contacts.*.remark' => 'nullable|string',
            'addresses' => 'nullable|array', 'addresses.*.id' => 'nullable|integer', 'addresses.*.receiver_name' => 'required|string|max:80', 'addresses.*.receiver_phone' => 'nullable|string|max:40', 'addresses.*.province' => 'nullable|string|max:80', 'addresses.*.city' => 'nullable|string|max:80', 'addresses.*.district' => 'nullable|string|max:80', 'addresses.*.detail_address' => 'required|string|max:500', 'addresses.*.is_default' => 'boolean', 'addresses.*.status' => 'nullable|in:enabled,disabled', 'addresses.*.remark' => 'nullable|string',
        ], [], [
            'customer_code' => '客户编码', 'customer_name' => '客户名称', 'customer_short_name' => '客户简称', 'owner_name' => '负责人',
            'contacts.*.contact_name' => '联系人姓名', 'contacts.*.mobile' => '联系人手机', 'contacts.*.phone' => '联系人电话', 'contacts.*.email' => '联系人邮箱',
            'addresses.*.receiver_name' => '收货人', 'addresses.*.receiver_phone' => '收货电话', 'addresses.*.province' => '省份', 'addresses.*.city' => '城市',
            'addresses.*.district' => '区县', 'addresses.*.detail_address' => '详细地址',
        ]);
    }

    private function withDedupeIdentity(array $data): array
    {
        $data['customer_kind'] = $data['customer_kind'] ?? 'individual';
        $data['source_platform'] = trim((string) ($data['source_platform'] ?? '')) ?: null;
        $data['platform_buyer_id'] = trim((string) ($data['platform_buyer_id'] ?? '')) ?: null;
        if (!$data['source_platform'] || !$data['platform_buyer_id']) {
            $data['source_platform'] = $data['source_platform'] ?: null;
            $data['platform_buyer_id'] = $data['platform_buyer_id'] ?: null;
        }
        $data['platform_identity_key'] = $this->platformIdentityKey($data['source_platform'], $data['platform_buyer_id']);
        $data['dedupe_name_key'] = $this->dedupeKey($data['customer_name'] ?? null);
        $data['dedupe_address_key'] = $this->dedupeKey($data['full_address'] ?? null);
        return $data;
    }

    private function dedupeKey(?string $value): ?string
    {
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', mb_strtolower(trim((string) $value)));
        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function platformIdentityKey(?string $sourcePlatform, ?string $buyerId): ?string
    {
        return $sourcePlatform && $buyerId ? hash('sha256', $sourcePlatform."\0".$buyerId) : null;
    }

    private function authorize(Request $request, string $permission): void
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录');
        $auth = app(AuthContextService::class);
        $codes = $auth->permissionCodes($user);
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $codes, true), 403, '无客户管理权限');
    }
}
