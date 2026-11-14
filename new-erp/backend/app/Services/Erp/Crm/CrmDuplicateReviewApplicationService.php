<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmDuplicateReview;
use App\Models\Erp\CrmLead;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CustomerRegionService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 待核实只保留申请与办理事实，不合并客户、不覆盖主资料，也不改挂已有订单。 */
final class CrmDuplicateReviewApplicationService
{
    public function __construct(
        private readonly CrmCustomerDedupeService $dedupe,
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmPlatformIdentityService $identities,
        private readonly AuthContextService $auth,
        private readonly CrmSalesStaffService $staff,
    ) {}

    public function createForLead(CrmLead $lead, array $matches, string $note, object $actor): CrmDuplicateReview
    {
        return $this->create(['purpose' => 'duplicate', 'subject_type' => 'lead', 'subject_id' => $lead->id,
            'input_snapshot' => $lead->customer_input_snapshot, 'application_note' => $note], $actor);
    }

    public function forLead(CrmLead $lead, object $actor): ?array
    {
        $review = CrmDuplicateReview::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->latest('id')->first();
        return $review && ((int) $review->applicant_legacy_id === (int) $actor->legacy_id || $this->canResolve($review, $actor))
            ? $this->present($review, $actor) : null;
    }

    public function create(array $payload, object $actor): CrmDuplicateReview
    {
        $data = Validator::make($payload, [
            'purpose' => 'required|in:duplicate,collaboration', 'subject_type' => 'required|in:lead,customer,order_draft',
            'subject_id' => 'nullable|integer|min:1', 'target_customer_id' => 'nullable|integer|min:1',
            'application_note' => 'required|string|max:500', 'input_snapshot' => 'nullable|array',
            'input_snapshot.customer_kind' => 'nullable|in:enterprise,individual', 'input_snapshot.customer_name' => 'nullable|string|max:160',
            'input_snapshot.phones' => 'nullable|array|max:10', 'input_snapshot.phones.*' => 'nullable|string|max:80',
            'input_snapshot.wechat' => 'nullable|string|max:80', 'input_snapshot.email' => 'nullable|string|max:160',
            'input_snapshot.credit_code' => 'nullable|string|max:40', 'input_snapshot.region_id' => 'nullable|integer|min:1',
            'input_snapshot.full_address' => 'nullable|string|max:500', 'input_snapshot.platform_legacy_id' => 'nullable|integer|min:1',
            'input_snapshot.detail_address' => 'nullable|string|max:500',
            'input_snapshot.platform_buyer_id' => 'nullable|string|max:160',
        ])->validate();
        abort_unless(trim($data['application_note']) !== '', 422, '请填写申请说明。');
        return DB::transaction(function () use ($data, $actor) {
            abort_unless($this->assignments->activeAccount((int) $actor->legacy_id), 403, '当前账号已停用。');
            $purpose = $data['purpose'];
            $snapshot = array_intersect_key($data['input_snapshot'] ?? [], array_flip(['customer_kind', 'customer_name', 'phones', 'wechat', 'email',
                'credit_code', 'region_id', 'full_address', 'detail_address', 'platform_legacy_id', 'platform_buyer_id']));
            $source = $this->subject($data, $actor);
            $channel = $source['channel'];
            if ($data['subject_type'] === 'lead') $snapshot = $source['snapshot'];
            elseif (!empty($snapshot['region_id']) && array_key_exists('detail_address', $snapshot)) {
                $snapshot['full_address'] = app(CustomerRegionService::class)->address((int) $snapshot['region_id'], trim((string) $snapshot['detail_address']))['full_address'];
            }
            if ($purpose === 'collaboration') {
                abort_unless($this->assignments->activeSales((int) $actor->legacy_id), 422, '只有有效销售可申请客户协作。');
                $target = SalesCustomer::query()->lockForUpdate()->findOrFail((int) ($data['target_customer_id'] ?? 0));
                abort_if((int) $target->owner_legacy_id === (int) $actor->legacy_id || $this->customers->collaboration($target->id, (int) $actor->legacy_id), 422, '已具有客户访问范围，无需重复申请。');
                $departmentId = $this->customerDepartment($target);
                $snapshot = ['purpose' => $purpose, 'target_customer_id' => $target->id];
                $matches = [['customer_id' => $target->id, 'reasons' => ['collaboration'], 'strong' => false]];
            } else {
                $matches = $this->dedupe->match($snapshot, $data['subject_type'] === 'customer' ? ($data['subject_id'] ?? null) : null);
                abort_unless($matches, 422, '当前录入资料未匹配到客户，请返回修改。');
                $fallbackDepartment = $source['department'] ?: DB::table('erp_department_users')->where('user_legacy_id', $actor->legacy_id)->orderBy('id')->value('department_legacy_id');
                $departmentId = $this->reviewDepartment($matches, $fallbackDepartment ? (int) $fallbackDepartment : null);
                $snapshot['purpose'] = $purpose;
            }
            // 锁住申请人的既有账号，保证无主对象的新建申请重试也只生成一个 pending。
            DB::table('erp_legacy_admin_users')->where('legacy_id', $actor->legacy_id)->lockForUpdate()->first();
            $subjectId = $data['subject_id'] ?? null;
            $key = hash('sha256', $purpose.'|'.$data['subject_type'].'|'.($subjectId ?? 'new:'.$actor->legacy_id.'|'.json_encode($snapshot, JSON_UNESCAPED_UNICODE)));
            $existing = CrmDuplicateReview::query()->where('pending_key', $key)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->applicant_legacy_id === (int) $actor->legacy_id || $this->canResolve($existing, $actor), 409, '该对象已有待处理的核实申请。');
                return $existing;
            }
            $review = CrmDuplicateReview::create(['subject_type' => $data['subject_type'], 'subject_id' => $subjectId,
                'input_snapshot' => $snapshot, 'matches' => $matches, 'review_department_legacy_id' => $departmentId, 'source_channel' => $channel,
                'applicant_legacy_id' => $actor->legacy_id, 'applicant_name' => $this->actorName($actor), 'application_note' => trim($data['application_note']),
                'status' => 'pending', 'pending_key' => $key]);
            $this->audit('review_apply', $review, null, $actor);
            return $review;
        }, 3);
    }

    public function index(object $actor, array $filters)
    {
        $query = CrmDuplicateReview::query();
        if (!$this->auth->isSuperAdmin($actor)) {
            $grant = $this->assignments->activeGrant($actor);
            $actions = $grant ? array_filter(['verify', 'collab'], fn ($action) => in_array($action, $grant->actions, true)
                && $this->assignments->hasPermission($actor, CrmAssignmentAccessService::PERMISSIONS[$action])) : [];
            $query->where(function ($q) use ($actor, $grant, $actions) {
                $q->where('applicant_legacy_id', $actor->legacy_id);
                if ($actions) $q->orWhere(function ($g) use ($grant, $actions) {
                    $g->whereIn('review_department_legacy_id', $this->assignments->expandDepartments($grant->department_ids));
                    if ($grant->channels) $g->whereIn('source_channel', $grant->channels);
                    if (!in_array('collab', $actions, true)) $g->where('input_snapshot->purpose', 'duplicate');
                    if (!in_array('verify', $actions, true)) $g->where('input_snapshot->purpose', 'collaboration');
                });
            });
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['subject_type'])) $query->where('subject_type', $filters['subject_type']);
        if (!empty($filters['subject_id'])) $query->where('subject_id', $filters['subject_id']);
        return $query->orderByDesc('id')->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 10))))->through(fn ($r) => $this->present($r, $actor));
    }

    public function show(int $id, object $actor): array
    {
        $review = CrmDuplicateReview::query()->findOrFail($id);
        abort_unless((int) $review->applicant_legacy_id === (int) $actor->legacy_id || $this->canResolve($review, $actor), 403, '无权查看该核实申请。');
        return $this->present($review, $actor);
    }

    public function resolve(int $id, array $payload, object $actor): array
    {
        $data = Validator::make($payload, ['decision' => 'required|in:linked,returned', 'resolved_customer_id' => 'nullable|integer|min:1',
            'note' => 'required|string|max:500', 'expected_version' => 'required|integer|min:1'])->validate();
        abort_unless(trim($data['note']) !== '', 422, '请填写处理说明。');
        return DB::transaction(function () use ($id, $data, $actor) {
            $review = CrmDuplicateReview::query()->lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending', 409, '该申请已经处理，请刷新查看结果。');
            abort_unless($review->version === (int) $data['expected_version'], 409, '申请已发生变化，请刷新后重试。');
            $this->assignments->lockPeople([(int) $actor->legacy_id, (int) $review->applicant_legacy_id]);
            abort_unless($this->canResolve($review, $actor, true), 403, '无权处理该部门或渠道的核实申请。');
            $before = $review->toArray();
            $snapshot = $review->input_snapshot;
            $customerId = null;
            if (($snapshot['purpose'] ?? 'duplicate') === 'collaboration') {
                $customer = SalesCustomer::query()->lockForUpdate()->findOrFail((int) $snapshot['target_customer_id']);
                if ($data['decision'] === 'linked') {
                    $this->customers->assert($customer, $actor, 'collaborate', '无权为该客户处理协作申请。');
                    $departmentId = $this->customerDepartment($customer);
                    abort_unless((int) $departmentId === (int) $review->review_department_legacy_id, 409, '客户负责部门已变化，请退回后重新申请。');
                    $this->assignments->assert($actor, 'collab', $departmentId, $review->source_channel);
                    abort_unless($this->assignments->activeSales((int) $review->applicant_legacy_id, true), 422, '申请人的销售档案已失效。');
                    if (!$this->customers->collaboration($customer->id, (int) $review->applicant_legacy_id)
                        && (int) $customer->owner_legacy_id !== (int) $review->applicant_legacy_id) {
                        app(CrmCustomerCollaborationService::class)->add($customer->id, ['user_legacy_id' => $review->applicant_legacy_id,
                            'scope' => ['basic' => true], 'actions' => ['view' => true, 'follow' => true],
                            'effective_from' => today()->toDateString(), 'effective_to' => null, 'reason' => $data['note']], $actor);
                    }
                    $customerId = $customer->id;
                }
            } else {
                // 平台设置与身份绑定共用锁；重新查重后才允许关联，旧候选ID不能成为越权或误挂依据。
                if (!empty($snapshot['platform_legacy_id']) && !empty($snapshot['platform_buyer_id'])) {
                    $identity = $this->identities->resolve((int) $snapshot['platform_legacy_id'], $snapshot['platform_buyer_id'], true);
                    $this->identities->owner($identity['key'], true);
                }
                $matches = $this->dedupe->match($snapshot, $review->subject_type === 'customer' ? $review->subject_id : null, true);
                $review->matches = $matches;
                if ($data['decision'] === 'linked') {
                    $customerId = (int) ($data['resolved_customer_id'] ?? 0);
                    $strongIds = array_column(array_filter($matches, fn ($m) => $m['strong']), 'customer_id');
                    abort_unless(in_array($customerId, array_column($matches, 'customer_id'), true), 422, '所选客户已不在实际匹配结果中，请退回修改。');
                    abort_if(count($strongIds) > 1 || ($strongIds && $strongIds[0] !== $customerId), 422, '强身份指向不同客户，不能直接关联，请退回录入人核对。');
                    $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
                    abort_unless($this->customers->relation($customer, $actor) !== null, 403, '无权关联该客户，请由其所属部门确认。');
                    $customerDepartment = $this->customerDepartment($customer, true);
                    abort_unless(!$customerDepartment || (int) $customerDepartment === (int) $review->review_department_legacy_id
                        || $this->auth->isSuperAdmin($actor), 403, '其他部门客户只保留为候选，不在本次处理范围内。');
                    $currentDepartment = $this->reviewDepartment($matches, $review->review_department_legacy_id, true);
                    abort_unless((int) $currentDepartment === (int) $review->review_department_legacy_id, 409, '实际匹配客户的负责部门已变化，请退回后重新申请。');
                }
            }
            if ($review->subject_type === 'lead' && ($snapshot['purpose'] ?? 'duplicate') === 'duplicate') {
                app(CrmLeadApplicationService::class)->completeReview((int) $review->subject_id, $customerId, $data['decision'], $data['note'], $actor);
            }
            $review->fill(['status' => $data['decision'], 'pending_key' => null, 'resolved_customer_id' => $customerId,
                'resolver_legacy_id' => $actor->legacy_id, 'resolver_name' => $this->actorName($actor), 'resolved_at' => now(),
                'note' => trim($data['note']), 'version' => $review->version + 1])->save();
            $this->audit('review_'.$data['decision'], $review, $before, $actor);
            return $this->present($review->fresh(), $actor);
        }, 3);
    }

    public function present(CrmDuplicateReview $review, object $actor): array
    {
        $snapshot = $review->input_snapshot;
        $matches = ($snapshot['purpose'] ?? 'duplicate') === 'collaboration' ? $review->matches
            : $this->dedupe->match($snapshot, $review->subject_type === 'customer' ? $review->subject_id : null);
        $safeSnapshot = $snapshot;
        if (($snapshot['purpose'] ?? 'duplicate') === 'duplicate' && (int) $review->applicant_legacy_id !== (int) $actor->legacy_id) {
            $safeSnapshot['phones'] = array_map(fn ($p) => mb_strlen((string) $p) >= 7 ? mb_substr((string) $p, 0, 3).'****'.mb_substr((string) $p, -4) : (string) $p, $snapshot['phones'] ?? []);
        }
        $resolvedCustomer = $review->resolved_customer_id ? SalesCustomer::query()->find($review->resolved_customer_id) : null;
        $resolved = $resolvedCustomer ? array_replace($this->customers->masked($resolvedCustomer), [
            'visible' => $this->customers->relation($resolvedCustomer, $actor) !== null,
            'owner_department_name' => $this->customers->departmentName($this->customerDepartment($resolvedCustomer)),
        ]) : null;
        return $review->only(['id', 'subject_type', 'subject_id', 'status', 'review_department_legacy_id', 'source_channel', 'applicant_legacy_id', 'applicant_name',
            'application_note', 'resolved_customer_id', 'resolver_name', 'resolved_at', 'note', 'version', 'created_at'])
            + ['purpose' => $snapshot['purpose'] ?? 'duplicate', 'input_snapshot' => $safeSnapshot,
                'review_department_name' => $this->customers->departmentName($review->review_department_legacy_id),
                'matches' => $this->dedupe->present($matches, $actor), 'resolved_customer' => $resolved,
                'can_resolve' => $review->status === 'pending' && $this->canResolve($review, $actor)];
    }

    private function canResolve(CrmDuplicateReview $review, object $actor, bool $lock = false): bool
    {
        $action = ($review->input_snapshot['purpose'] ?? 'duplicate') === 'collaboration' ? 'collab' : 'verify';
        $departmentId = $review->review_department_legacy_id;
        if ($action === 'collab') {
            $customer = SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->find((int) ($review->input_snapshot['target_customer_id'] ?? 0));
            if (!$customer) return false;
            $departmentId = $this->customerDepartment($customer, $lock);
        }
        if (!$departmentId && $this->auth->isSuperAdmin($actor)) return $this->assignments->activeAccount((int) $actor->legacy_id, $lock);
        if ($lock) { $this->assignments->assert($actor, $action, $departmentId, $review->source_channel); return true; }
        return $this->assignments->allows($actor, $action, $departmentId, $review->source_channel);
    }

    private function subject(array $data, object $actor): array
    {
        $id = $data['subject_id'] ?? null;
        if ($data['subject_type'] === 'lead') {
            abort_unless($id && $this->assignments->hasPermission($actor, 'crm.lead.view'), 403, '无线索申请权限。');
            $lead = CrmLead::query()->lockForUpdate()->findOrFail($id);
            abort_unless((int) $lead->created_by_legacy_id === (int) $actor->legacy_id || $this->auth->isSuperAdmin($actor), 403, '只能提交本人录入线索的核实申请。');
            abort_unless(in_array($lead->status, ['draft', 'pending_review'], true), 422, '当前线索状态不能提交核实。');
            return ['channel' => $lead->source_channel, 'department' => $lead->department_legacy_id, 'snapshot' => $lead->customer_input_snapshot];
        }
        if ($data['subject_type'] === 'customer') {
            abort_unless($this->assignments->hasPermission($actor, 'sales.customer'), 403, '没有客户查看权限。');
            if ($id) {
                $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
                if ($data['purpose'] !== 'collaboration') $this->customers->assert($customer, $actor, 'edit', '无权提交该客户的核实申请。');
            } else abort_unless($data['purpose'] === 'collaboration' || $this->assignments->hasPermission($actor, 'sales.customer.create'), 403, '没有客户新建权限。');
            return ['channel' => null, 'department' => null, 'snapshot' => null];
        }
        $permission = $id ? 'sales_order.edit_draft' : 'sales_order.create';
        abort_unless($this->assignments->hasPermission($actor, $permission), 403, '没有订单草稿办理权限。');
        if ($id) {
            $query = SalesOrder::query()->whereKey($id)->where('order_status', 'draft');
            app(SalesOrderVisibilityService::class)->apply($query, $actor);
            abort_unless($query->lockForUpdate()->exists(), 403, '该订单草稿不存在或不在可办理范围内。');
        }
        return ['channel' => null, 'department' => null, 'snapshot' => null];
    }

    private function reviewDepartment(array $matches, ?int $fallback, bool $lock = false): ?int
    {
        foreach (['identity', 'credit_code'] as $reason) foreach ($matches as $match) if (in_array($reason, $match['reasons'], true)) {
            $customer = SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($match['customer_id']);
            return $customer ? ($this->customerDepartment($customer, $lock) ?? $fallback) : $fallback;
        }
        $customer = SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($matches[0]['customer_id'] ?? 0);
        return $customer ? ($this->customerDepartment($customer, $lock) ?? $fallback) : $fallback;
    }

    private function customerDepartment(SalesCustomer $customer, bool $lock = false): ?int
    {
        return $customer->owner_department_legacy_id ? (int) $customer->owner_department_legacy_id
            : ($customer->owner_legacy_id ? $this->staff->departmentOf((int) $customer->owner_legacy_id, $lock) : null);
    }

    private function actorName(object $actor): ?string { return ($actor->nickname ?? null) ?: ($actor->username ?? null); }
    private function audit(string $action, CrmDuplicateReview $review, ?array $before, object $actor): void
    {
        DB::table('erp_operation_logs')->insert(['module' => 'crm', 'action' => $action, 'target_type' => 'crm_duplicate_review', 'target_id' => $review->id,
            'old_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), 'new_snapshot' => json_encode($review->toArray(), JSON_UNESCAPED_UNICODE),
            'operator_id' => $actor->legacy_id, 'operator_name' => $this->actorName($actor), 'created_at' => now()]);
    }
}
