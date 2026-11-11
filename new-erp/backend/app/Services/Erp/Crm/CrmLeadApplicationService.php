<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmLead;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CustomerRegionService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 线索的正式写入口；客户归属与线索负责人独立，转交/回收不改历史订单。 */
final class CrmLeadApplicationService
{
    public const CHANNELS = ['线上平台' => '线上平台', '线下咨询' => '线下咨询', '客户介绍' => '客户介绍', '展会活动' => '展会活动', '主动开发' => '主动开发', '其他' => '其他'];
    public const POOLS = ['明日成交池', '补位池', '48小时推进池', '老客户转介绍/复购', '老线索补位池', '新线索快转池', '发货池', '观察池', '本周跟进池', '本月跟进池', '农改水客户池'];
    public const STATUSES = ['draft' => '草稿', 'pending_review' => '待核实', 'pending_assignment' => '待分配', 'pending_acceptance' => '待接收', 'active' => '跟进中', 'public_pool' => '公海', 'invalid' => '无效'];
    public const EDITABLE = [
        'customer_id', 'customer_kind', 'customer_name', 'contact_id', 'contact_name', 'contact_role', 'mobile', 'contact_value2', 'wechat', 'email', 'credit_code',
        'region_id', 'province', 'city', 'district', 'detail_address', 'full_address', 'trade_type', 'department_legacy_id', 'source_channel', 'lead_type',
        'customer_tags', 'custom_tags', 'is_new_inquiry', 'business_lines', 'customer_pool', 'expected_amount', 'currency', 'content', 'demand_summary',
        'platform_legacy_id', 'platform_buyer_id', 'next_contact_at', 'next_plan',
    ];

    public function __construct(
        private readonly AuthContextService $auth,
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmSalesStaffService $staff,
        private readonly CrmCustomerDedupeService $dedupe,
        private readonly CrmCustomerApplicationService $customerApp,
        private readonly CrmPlatformIdentityService $identities,
    ) {}

    public function permission(object $actor, string $code): void
    {
        $allowed = $this->customers->can($actor, $code) || ($code === 'sales.customer' && $this->customers->can($actor, 'sales.customer.view'));
        abort_unless($allowed, 403, '没有该操作权限。');
    }

    public function applyVisible(Builder $query, object $actor): void
    {
        $id = (int) $actor->legacy_id;
        $query->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('created_by_legacy_id', $id));
        if ($this->auth->isSuperAdmin($actor) || $this->auth->dataScope($actor) === 'all') return;
        $dept = $this->staff->departmentOf($id);
        $departmentUsers = $this->auth->dataScope($actor) === 'department' ? $this->auth->departmentUserIds($actor) : [];
        $grant = $this->assignments->activeGrant($actor);
        $reviewDepartments = $grant && in_array('verify', $grant->actions, true) && $this->customers->can($actor, 'sales.customer.verify')
            ? $this->assignments->expandDepartments($grant->department_ids) : [];
        $query->where(function ($q) use ($actor, $id, $dept, $departmentUsers, $grant, $reviewDepartments) {
            $q->where('owner_legacy_id', $id)->orWhere('pending_user_legacy_id', $id)->orWhere('created_by_legacy_id', $id);
            if ($departmentUsers) $q->orWhereIn('owner_legacy_id', $departmentUsers)->orWhereIn('created_by_legacy_id', $departmentUsers);
            if ($dept) $q->orWhere(fn ($pool) => $pool->where('status', 'public_pool')->where('department_legacy_id', $dept));
            if ($grant && in_array('claim', $grant->actions, true) && $this->customers->can($actor, 'crm.lead.claim')) {
                $q->orWhere(function ($pool) use ($grant) {
                    $pool->where('status', 'public_pool')->whereIn('department_legacy_id', $this->assignments->expandDepartments($grant->department_ids));
                    if ($grant->channels) $pool->whereIn('source_channel', $grant->channels);
                });
            }
            $q->orWhere(fn ($scope) => $this->assignments->applyVisible($scope, $actor));
            $q->orWhereExists(fn ($c) => $this->collaborationQuery($c, $id)->whereColumn('c.customer_id', 'erp_crm_leads.customer_id'));
            if ($reviewDepartments) $q->orWhere(function ($reviewing) use ($grant, $reviewDepartments) {
                $reviewing->where('status', 'pending_review')->whereExists(function ($review) use ($grant, $reviewDepartments) {
                    $review->from('erp_crm_duplicate_reviews as r')->where('r.subject_type', 'lead')->whereColumn('r.subject_id', 'erp_crm_leads.id')
                        ->where('r.status', 'pending')->whereIn('r.review_department_legacy_id', $reviewDepartments);
                    if ($grant->channels) $review->whereIn('r.source_channel', $grant->channels);
                });
            });
        });
    }

    public function visible(int $id, object $actor, bool $lock = false): CrmLead
    {
        $query = CrmLead::query();
        $this->applyVisible($query, $actor);
        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function applyCollaborating(Builder $query, object $actor): void
    {
        $query->whereExists(fn ($c) => $this->collaborationQuery($c, (int) $actor->legacy_id)->whereColumn('c.customer_id', 'erp_crm_leads.customer_id'));
    }

    public function abilities(CrmLead $lead, object $actor): array
    {
        $own = (int) $lead->owner_legacy_id === (int) $actor->legacy_id;
        $draft = $lead->status === 'draft' && (int) $lead->created_by_legacy_id === (int) $actor->legacy_id;
        $can = fn ($action) => $this->customers->can($actor, 'crm.lead.'.$action);
        $scope = fn ($action, $target = null) => $this->assignments->allows($actor, $action, $lead->department_legacy_id, $lead->source_channel, $target);
        $manages = $own || $scope('assign');
        return [
            'edit' => $can('edit') && ($draft || ($lead->status === 'active' && $manages)),
            'assign' => $can('assign') && in_array($lead->status, ['pending_assignment', 'public_pool'], true) && $scope('assign'),
            'accept' => $can('accept') && $lead->status === 'pending_acceptance' && (int) $lead->pending_user_legacy_id === (int) $actor->legacy_id,
            'return' => $can('return') && $lead->status === 'pending_acceptance' && (int) $lead->pending_user_legacy_id === (int) $actor->legacy_id,
            'transfer' => $can('transfer') && $lead->status === 'active' && $scope('transfer'),
            'recycle' => $can('recycle') && in_array($lead->status, ['active', 'pending_acceptance', 'pending_assignment'], true) && $scope('recycle'),
            'claim' => $can('claim') && $lead->status === 'public_pool' && $scope('claim', (int) $actor->legacy_id),
            'pool' => $can('pool') && $lead->status === 'active' && $own,
            'invalidate' => $can('invalidate') && in_array($lead->status, ['active', 'pending_assignment'], true) && $manages,
            'confirm' => $can('confirm') && $lead->status === 'active' && !$lead->confirmed_at && $own,
            'follow' => $can('follow') && $lead->status === 'active' && ($own || ($lead->customer_id && $this->canFollowCustomer($lead->customer_id, $actor))),
        ];
    }

    public function present(CrmLead $lead, object $actor): array
    {
        $row = $lead->toArray();
        unset($row['customer_input_snapshot'], $row['legacy_payload']);
        if ($lead->status === 'pending_review' && (int) $lead->created_by_legacy_id !== (int) $actor->legacy_id) {
            foreach (['mobile', 'contact_value2'] as $field) if ($row[$field]) $row[$field] = mb_strlen($row[$field]) >= 7 ? mb_substr($row[$field], 0, 3).'****'.mb_substr($row[$field], -4) : '***';
            $row['wechat'] = $row['wechat'] ? '***' : null;
            $row['email'] = $row['email'] ? '***' : null;
        }
        $row['department_name'] = $this->customers->departmentName($lead->department_legacy_id);
        $row['status_label'] = self::STATUSES[$lead->status] ?? $lead->status;
        $row['abilities'] = $this->abilities($lead, $actor);
        if ($lead->customer_id) {
            $customer = $lead->customer;
            $row['customer'] = $customer && $this->customers->relation($customer, $actor) !== null
                ? $customer->only(['id', 'customer_code', 'customer_name', 'owner_name', 'crm_version'])
                : ($customer ? $this->customers->masked($customer) : null);
            $row['abilities']['transfer_customer'] = $customer && $this->customers->abilities($customer, $actor)['transfer'];
        }
        $row['attachments'] = $lead->attachments->where('category', 'communication')->map(fn ($file) => app(CrmAttachmentApplicationService::class)->present($file))->values()->all();
        $row['assignment_attachments'] = $lead->attachments->where('category', 'assignment')->map(fn ($file) => app(CrmAttachmentApplicationService::class)->present($file))->values()->all();
        if ($lead->status === 'pending_review') $row['review'] = app(CrmDuplicateReviewApplicationService::class)->forLead($lead, $actor);
        if ($lead->platform_legacy_id) $row['platform_name'] = DB::table('erp_sales_order_trade_platforms')->where('legacy_id', $lead->platform_legacy_id)->value('name');
        return $row;
    }

    public function save(?int $id, array $data, object $actor): CrmLead
    {
        $this->permission($actor, $id ? 'crm.lead.edit' : 'crm.lead.create');
        return DB::transaction(function () use ($id, $data, $actor) {
            // 平台设置、身份绑定、线索提交统一先锁平台计数器，避免边查重边改变识别规则。
            app(TradePlatformApplicationService::class)->lockIdentityCounter();
            $lead = $id ? $this->visible($id, $actor, true) : new CrmLead;
            if ($id) {
                $this->version($lead, $data);
                abort_unless($this->abilities($lead, $actor)['edit'], 403, '当前线索不能编辑。');
            }
            $before = $id ? $this->snapshot($lead) : null;
            $values = array_intersect_key($data, array_flip(self::EDITABLE));
            if ($id && $lead->status !== 'draft') {
                // 已正式关联的客户/渠道/所属部门不通过编辑重新归属，改动需走转交或核实动作。
                foreach (['customer_id', 'department_legacy_id', 'source_channel', 'platform_legacy_id', 'platform_buyer_id'] as $field) {
                    if (array_key_exists($field, $values) && (string) $values[$field] !== (string) $lead->$field) {
                        throw ValidationException::withMessages([$field => '正式线索不能直接修改客户身份或分配范围，请通过对应业务操作处理。']);
                    }
                }
            }
            $lead->fill($values);
            if ($lead->region_id) {
                $address = app(CustomerRegionService::class)->address((int) $lead->region_id, (string) $lead->detail_address);
                $lead->fill(array_intersect_key($address, array_flip(['province', 'city', 'district', 'full_address'])));
            } elseif (!trim((string) $lead->full_address)) {
                $lead->full_address = trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])) ?: null;
            }
            if (!$id) {
                $lead->lead_no = 'LD'.now()->format('Ymd').strtoupper(substr((string) Str::ulid(), -12));
                $lead->entry_method = $this->staff->isActiveSales((int) $actor->legacy_id) ? 'self' : 'operations';
                $lead->created_by_legacy_id = $actor->legacy_id;
                $lead->created_by_name = $this->actorName($actor);
                $lead->status = 'draft';
                $lead->version = 1;
                $lead->customer_kind = $lead->customer_kind ?: 'individual';
                $lead->currency = $lead->currency ?: 'CNY';
                $lead->customer_pool = $lead->customer_pool ?: '新线索快转池';
                $lead->is_new_inquiry = $lead->is_new_inquiry ?? true;
            }
            if ($lead->entry_method === 'self' && $lead->status === 'draft') {
                $lead->department_legacy_id = $this->staff->departmentOf((int) $lead->created_by_legacy_id);
                $this->assignments->assert($actor, 'self_entry', $lead->department_legacy_id, $lead->source_channel, (int) $actor->legacy_id);
            } elseif ($lead->status === 'draft') {
                $lead->department_legacy_id = $lead->department_legacy_id ?: $this->staff->departmentOf((int) $actor->legacy_id);
                $this->assignments->assert($actor, 'assign', $lead->department_legacy_id, $lead->source_channel);
            }
            $lead->customer_input_snapshot = $this->input($lead);
            $mode = $data['mode'] ?? ($id && $lead->status !== 'draft' ? 'submit' : 'draft');
            if ($mode === 'review') {
                abort_unless($lead->status === 'draft', 422, '只有草稿可以申请身份核实。');
                abort_if(trim((string) ($data['review_note'] ?? '')) === '', 422, '请填写申请说明。');
                $matches = $this->dedupe->match($lead->customer_input_snapshot);
                abort_if(!$matches, 422, '当前没有需要核实的重复客户，请重新查重后正式录入。');
                $lead->status = 'pending_review';
                $lead->save();
                app(CrmDuplicateReviewApplicationService::class)->createForLead($lead, $matches, $data['review_note'], $actor);
            } elseif ($mode === 'submit' && $lead->status === 'draft') {
                $this->formalize($lead, $data, $actor);
            } else {
                abort_unless($mode === 'draft' || ($mode === 'submit' && $lead->status === 'active'), 422, '当前状态不能保存。');
            }
            if ($id) $lead->version++;
            $lead->save();
            if (array_key_exists('attachment_ids', $data)) app(CrmAttachmentApplicationService::class)->bind($data['attachment_ids'] ?? [], 'lead', $lead->id, $actor);
            $this->log($lead, $id ? 'edit' : 'create', $mode === 'review' ? '申请客户身份核实' : ($lead->status === 'draft' ? '保存线索草稿' : ($id ? '修改线索资料' : '录入线索')), $before, $this->snapshot($lead), $data['duplicate_reason'] ?? null, $actor);
            if ($lead->customer_id) app(CrmFollowupApplicationService::class)->refreshContactFacts($lead->customer_id, $lead->id, false);
            return $lead->fresh();
        }, 3);
    }

    public function act(int $id, string $action, array $data, object $actor): CrmLead
    {
        $this->permission($actor, 'crm.lead.'.$action);
        return DB::transaction(function () use ($id, $action, $data, $actor) {
            $lead = $this->visible($id, $actor, true);
            $this->version($lead, $data);
            abort_unless($this->abilities($lead, $actor)[$action] ?? false, 403, '当前线索状态或授权范围不允许该操作，请刷新后重试。');
            $before = $this->snapshot($lead);
            $reason = trim((string) ($data['reason'] ?? ''));
            if (in_array($action, ['assign', 'transfer', 'return', 'recycle', 'invalidate', 'pool'], true)) {
                abort_if($reason === '', 422, '请填写操作原因。');
            }
            switch ($action) {
                case 'assign':
                case 'transfer':
                    $to = (int) ($data['to_user_legacy_id'] ?? 0);
                    $this->assignments->assert($actor, $action, $lead->department_legacy_id, $lead->source_channel);
                    $this->assignments->assert($actor, $action, $this->staff->departmentOf($to), $lead->source_channel, $to);
                    abort_if($to === (int) $lead->owner_legacy_id, 422, '新负责人不能与当前负责人相同。');
                    if ($action === 'transfer' && !empty($data['transfer_customer'])) {
                        abort_unless($lead->customer_id, 422, '当前线索尚未关联客户。');
                        $this->customerApp->transfer($lead->customer_id, $to, $reason, (int) ($data['customer_version'] ?? 0), $actor);
                    }
                    $lead->previous_owner_legacy_id = $lead->owner_legacy_id;
                    $lead->previous_owner_name = $lead->owner_name;
                    $lead->owner_legacy_id = null;
                    $lead->owner_name = null;
                    $lead->pending_user_legacy_id = $to;
                    $lead->pending_user_name = $this->staff->name($to);
                    $lead->assigned_by_legacy_id = $actor->legacy_id;
                    $lead->assigned_by_name = $this->actorName($actor);
                    $lead->assigned_at = now();
                    $lead->assignment_reason = $reason;
                    $lead->accepted_at = null;
                    $lead->status = 'pending_acceptance';
                    if (array_key_exists('assignment_attachment_ids', $data)) app(CrmAttachmentApplicationService::class)->bind($data['assignment_attachment_ids'] ?? [], 'lead', $lead->id, $actor, 'assignment');
                    break;
                case 'accept':
                    abort_unless($this->staff->isActiveSales((int) $actor->legacy_id), 422, '销售员档案已失效，不能接收线索。');
                    $this->setOwner($lead, (int) $actor->legacy_id);
                    $lead->accepted_at = now();
                    $lead->status = 'active';
                    $this->customerApp->claimIfUnowned($lead->customer_id, (int) $actor->legacy_id, '接收线索', $actor);
                    break;
                case 'return':
                    $lead->pending_user_legacy_id = null;
                    $lead->pending_user_name = null;
                    $lead->status = 'pending_assignment';
                    break;
                case 'recycle':
                    $this->assignments->assert($actor, 'recycle', $lead->department_legacy_id, $lead->source_channel);
                    $lead->previous_owner_legacy_id = $lead->owner_legacy_id ?: $lead->pending_user_legacy_id;
                    $lead->previous_owner_name = $lead->owner_name ?: $lead->pending_user_name;
                    $lead->owner_legacy_id = null;
                    $lead->owner_name = null;
                    $lead->pending_user_legacy_id = null;
                    $lead->pending_user_name = null;
                    $lead->status = 'public_pool';
                    $lead->recycled_at = now();
                    $lead->recycled_by_name = $this->actorName($actor);
                    $lead->recycle_reason = $reason;
                    break;
                case 'claim':
                    // 先锁行再查授权与状态，两个销售同时领取只允许第一个版本成功。
                    $this->assignments->assert($actor, 'claim', $lead->department_legacy_id, $lead->source_channel, (int) $actor->legacy_id);
                    $this->setOwner($lead, (int) $actor->legacy_id);
                    $lead->status = 'active';
                    $lead->accepted_at = now();
                    $this->customerApp->claimIfUnowned($lead->customer_id, (int) $actor->legacy_id, '领取线索', $actor);
                    break;
                case 'pool':
                    abort_unless(in_array($data['customer_pool'] ?? null, self::POOLS, true), 422, '请选择有效客户池。');
                    $lead->customer_pool = $data['customer_pool'];
                    break;
                case 'invalidate':
                    $lead->status = 'invalid';
                    $lead->invalid_reason = $reason;
                    break;
                case 'confirm':
                    $lead->confirmed_at = now();
                    break;
            }
            $lead->version++;
            $lead->save();
            $labels = ['assign' => '分配线索', 'transfer' => '转交线索，待新负责人接收', 'accept' => '接收线索', 'return' => '退回待分配', 'recycle' => '人工回收到公海', 'claim' => '领取公海线索', 'pool' => '人工调整客户池', 'invalidate' => '标记线索无效', 'confirm' => '完成基础线索确认'];
            $this->log($lead, $action, $labels[$action], $before, $this->snapshot($lead), trim($reason.' '.($data['note'] ?? '')) ?: null, $actor);
            if ($lead->customer_id) app(CrmFollowupApplicationService::class)->refreshContactFacts($lead->customer_id, $lead->id, false);
            return $lead->fresh();
        }, 3);
    }

    /** 仅待核实服务在重新校验范围与强身份后调用，不能由普通表单跳过查重。 */
    public function completeReview(int $leadId, ?int $customerId, string $decision, string $note, object $actor): CrmLead
    {
        return DB::transaction(function () use ($leadId, $customerId, $decision, $note, $actor) {
            app(TradePlatformApplicationService::class)->lockIdentityCounter();
            $lead = CrmLead::query()->lockForUpdate()->findOrFail($leadId);
            abort_unless($lead->status === 'pending_review', 422, '该线索已经处理，请刷新。');
            $before = $this->snapshot($lead);
            if ($decision === 'returned') {
                $lead->status = 'draft';
                $lead->customer_id = null;
            } else {
                abort_unless($decision === 'linked' && $customerId, 422, '请选择核实结果。');
                $lead->customer_id = $customerId;
                $this->formalize($lead, [], $actor, true);
                $lead->owner_legacy_id = null;
                $lead->owner_name = null;
                $lead->pending_user_legacy_id = null;
                $lead->pending_user_name = null;
                $lead->accepted_at = null;
                $lead->status = 'pending_assignment';
            }
            $lead->version++;
            $lead->save();
            $this->log($lead, 'review_'.$decision, $decision === 'linked' ? '核实后关联客户' : '退回录入人修改', $before, $this->snapshot($lead), $note, $actor);
            return $lead->fresh();
        }, 3);
    }

    public function log(CrmLead $lead, string $action, string $summary, ?array $before, ?array $after, ?string $reason, object $actor): void
    {
        DB::table('erp_crm_lead_logs')->insert([
            'lead_id' => $lead->id, 'action' => $action, 'summary' => $summary,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'operator_legacy_id' => $actor->legacy_id, 'operator_name' => $this->actorName($actor), 'created_at' => now(),
        ]);
    }

    public function input(CrmLead $lead): array
    {
        return $lead->only(['customer_kind', 'customer_name', 'credit_code', 'wechat', 'email', 'region_id', 'full_address', 'platform_legacy_id', 'platform_buyer_id'])
            + ['phones' => array_values(array_filter([$lead->mobile, $lead->contact_value2]))];
    }

    public function snapshot(CrmLead $lead): array
    {
        return $lead->attributesToArray() + ['attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('lead', $lead->id),
            'assignment_attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('lead', $lead->id, 'assignment')];
    }

    public function version(CrmLead $lead, array $data): void
    {
        if ((int) ($data['version'] ?? 0) !== (int) $lead->version) {
            throw ValidationException::withMessages(['version' => '线索已被其他人处理，请刷新后重试。']);
        }
    }

    private function formalize(CrmLead $lead, array $data, object $actor, bool $reviewed = false): void
    {
        abort_if(trim((string) $lead->customer_name) === '' || !$lead->department_legacy_id || trim((string) $lead->source_channel) === '', 422, '请补齐客户姓名、所属部门和来源渠道。');
        abort_unless(DB::table('erp_departments')->where('legacy_id', $lead->department_legacy_id)->exists(), 422, '所属部门不存在。');
        $online = $lead->source_channel === '线上平台' || $lead->platform_legacy_id || trim((string) $lead->platform_buyer_id) !== '';
        if ($online) {
            abort_unless($lead->platform_legacy_id && trim((string) $lead->platform_buyer_id) !== '', 422, '线上线索请填写成交平台和平台买家ID，可先保存草稿。');
            abort_unless(DB::table('erp_sales_order_trade_platforms')->where('legacy_id', $lead->platform_legacy_id)->where('enabled', true)->where('parent_legacy_id', 0)->exists(), 422, '接待店铺已停用或不存在。');
            $resolved = $this->identities->resolve($lead->platform_legacy_id, $lead->platform_buyer_id, true);
            abort_unless($resolved['key'], 422, '该平台未设置买家ID识别方式，只能保存草稿。');
        }
        $matches = $this->dedupe->match($this->input($lead));
        $strongIds = array_column(array_filter($matches, fn ($m) => $m['strong']), 'customer_id');
        $conflict = count(array_unique($strongIds)) > 1 || ($strongIds && count($matches) > 1);
        $customer = $lead->customer_id ? SalesCustomer::query()->lockForUpdate()->findOrFail($lead->customer_id) : null;
        $confirmedOtherCandidates = !empty($data['duplicate_confirmed']) && trim((string) ($data['duplicate_reason'] ?? '')) !== '';
        $otherMatches = $customer ? array_filter($matches, fn ($match) => (int) $match['customer_id'] !== (int) $customer->id) : [];
        $strongMismatch = $customer && array_diff($strongIds, [$customer->id]);
        if (!$reviewed && ($conflict || $strongMismatch || ($otherMatches && !$confirmedOtherCandidates) || (!$customer && $matches && !(!$strongIds && $confirmedOtherCandidates)))) {
            throw new HttpResponseException(response()->json(['message' => $conflict ? '客户身份指向不同客户，请申请核实。' : '发现疑似客户，请确认后关联或填写新建原因。',
                'duplicates' => $this->dedupe->present($matches, $actor), 'requires_confirmation' => !$strongIds, 'review_required' => (bool) ($conflict || $strongMismatch)], 422));
        }
        if ($customer) {
            if (!$reviewed) abort_unless($this->customers->relation($customer, $actor) !== null, 403, '该客户不在可见范围，请先申请核实或协作。');
            abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或列入黑名单。');
            if ($online) $this->identities->assertCustomer($customer->id, $lead->platform_legacy_id, $lead->platform_buyer_id, true);
        } else {
            $contact = ['contact_name' => $lead->contact_name ?: $lead->customer_name, 'mobile' => $lead->mobile, 'phone' => $lead->contact_value2,
                'wechat' => $lead->wechat, 'email' => $lead->email, 'position' => $lead->contact_role, 'is_default' => true, 'status' => 'enabled'];
            abort_if(mb_strlen($contact['contact_name']) > 80, 422, '请填写不超过80字的实际联系人姓名。');
            $address = $lead->full_address || $lead->detail_address ? [['full_address' => $lead->full_address ?: trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])),
                'province' => $lead->province, 'city' => $lead->city, 'district' => $lead->district, 'detail_address' => $lead->detail_address ?: $lead->full_address,
                'region_id' => $lead->region_id, 'receiver_name' => $contact['contact_name'], 'receiver_phone' => $lead->mobile, 'is_default' => true, 'status' => 'enabled']] : [];
            $customer = $this->customerApp->create($lead->only(['customer_name', 'customer_kind', 'credit_code', 'region_id', 'business_lines']) + [
                'status' => 'potential', 'contacts' => [$contact], 'addresses' => $address,
                'duplicate_confirmed' => $data['duplicate_confirmed'] ?? false, 'duplicate_reason' => $data['duplicate_reason'] ?? null,
            ], $actor);
            $lead->customer_id = $customer->id;
            $lead->contact_id = $customer->contacts->first()?->id;
        }
        if ($lead->contact_id) abort_unless(DB::table('erp_sales_customer_contacts')->where('id', $lead->contact_id)->where('customer_id', $customer->id)->where('status', 'enabled')->exists(), 422, '联系人不属于当前客户或已停用。');
        if ($online) $this->identities->bind($customer, $lead->platform_legacy_id, $lead->platform_buyer_id, 'lead', $actor, '线索录入时登记');
        if (!$customer->first_source) $customer->update(['first_source' => $lead->source_channel]);
        if ($lead->entry_method === 'self' && !$reviewed) {
            abort_unless($this->staff->isActiveSales((int) $lead->created_by_legacy_id), 422, '录入销售已失效，请退回修改后由运营重新分配。');
            $this->setOwner($lead, (int) $lead->created_by_legacy_id);
            $lead->accepted_at = now();
            $lead->status = 'active';
            $this->customerApp->claimIfUnowned($customer->id, $lead->owner_legacy_id, '录入线索', $actor);
        } else {
            $lead->status = 'pending_assignment';
        }
    }

    private function setOwner(CrmLead $lead, int $userId): void
    {
        $lead->owner_legacy_id = $userId;
        $lead->owner_name = $this->staff->name($userId);
        $lead->department_legacy_id = $this->staff->departmentOf($userId);
        $lead->pending_user_legacy_id = null;
        $lead->pending_user_name = null;
    }

    private function canFollowCustomer(int $id, object $actor): bool
    {
        $customer = SalesCustomer::query()->find($id);
        return $customer && ($this->customers->abilities($customer, $actor)['follow'] ?? false);
    }

    private function collaborationQuery($query, int $actor)
    {
        return $query->from('erp_crm_customer_collaborators as c')->where('c.user_legacy_id', $actor)->where('c.status', 'active')
            ->where('c.effective_from', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>=', now()->toDateString()));
    }

    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? '')); }
}
