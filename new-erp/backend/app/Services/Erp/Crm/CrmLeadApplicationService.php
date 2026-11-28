<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CustomerRegionService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 线索的正式写入口；客户归属与线索负责人独立，转交/回收不改历史订单。 */
final class CrmLeadApplicationService
{
    public const CHANNELS = ['线上平台' => '线上平台', '线下咨询' => '线下咨询', '客户介绍' => '客户介绍', '展会活动' => '展会活动', '主动开发' => '主动开发', '其他' => '其他'];

    public const STATUSES = ['draft' => '草稿', 'pending_review' => '待核实', 'pending_assignment' => '待分配', 'pending_acceptance' => '待接收', 'active' => '跟进中', 'converted' => '已转商机', 'public_pool' => '公海', 'invalid' => '无效'];
    private const DELEGATED_OWNER_STATUSES = ['active', 'converted', 'invalid'];
    public const EDITABLE = [
        'customer_id', 'customer_kind', 'customer_name', 'contact_id', 'contact_name', 'contact_role', 'mobile', 'contact_value2', 'wechat', 'email', 'credit_code',
        'customer_grade', 'industry', 'purchasing_power', 'customer_business_lines',
        'region_id', 'province', 'city', 'district', 'detail_address', 'full_address', 'trade_type', 'department_legacy_id', 'source_channel', 'lead_type',
        'customer_tags', 'custom_tags', 'is_new_inquiry', 'customer_pool', 'expected_amount', 'currency', 'content', 'demand_summary',
        'platform_legacy_id', 'platform_buyer_id', 'next_contact_at', 'next_plan',
    ];
    private ?array $recycleDepartmentOptions = null;

    public function __construct(
        private readonly AuthContextService $auth,
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmSalesStaffService $staff,
        private readonly CrmCustomerDedupeService $dedupe,
        private readonly CrmCustomerApplicationService $customerApp,
        private readonly CrmPlatformIdentityService $identities,
        private readonly CrmCustomerLogService $customerLogs,
    ) {}

    public function permission(object $actor, string $code): void
    {
        $allowed = $this->customers->can($actor, $code) || ($code === 'sales.customer' && $this->customers->can($actor, 'sales.customer.view'))
            || ($code === 'crm.lead.recycle' && $this->customers->can($actor, 'crm.lead.release'));
        abort_unless($allowed, 403, '没有该操作权限。');
    }

    public function applyVisible(Builder $query, object $actor): void
    {
        $id = (int) $actor->legacy_id;
        $query->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('created_by_legacy_id', $id));
        if ($this->customers->dataScope($actor, 'crm.lead.view') === 'all') return;
        $dept = $this->staff->departmentOf($id);
        $poolDepartments = $this->assignments->visiblePoolDepartments($actor, $dept, $this->customers->dataScope($actor, 'crm.lead.view'));
        $departmentUsers = $this->customers->dataScope($actor, 'crm.lead.view') === 'department' ? $this->auth->departmentUserIds($actor) : [];
        $grant = $this->assignments->activeGrant($actor);
        $reviewDepartments = $grant && in_array('verify', $grant->actions, true) && $this->customers->can($actor, 'sales.customer.verify')
            ? $this->assignments->expandDepartments($grant->department_ids) : [];
        $query->where(function ($q) use ($actor, $id, $poolDepartments, $departmentUsers, $grant, $reviewDepartments) {
            $q->where('owner_legacy_id', $id)->orWhere('pending_user_legacy_id', $id)
                ->orWhere(fn ($created) => $created->where('created_by_legacy_id', $id)->whereIn('status', ['draft', 'pending_review', 'pending_assignment']));
            if ($departmentUsers) $q->orWhere(fn ($department) => $department->where('status', '!=', 'public_pool')
                ->where(fn ($people) => $people->whereIn('owner_legacy_id', $departmentUsers)->orWhereIn('created_by_legacy_id', $departmentUsers)));
            if ($poolDepartments) $q->orWhere(fn ($pool) => $pool->where('status', 'public_pool')->whereIn('department_legacy_id', $poolDepartments));
            if ($grant && in_array('claim', $grant->actions, true) && $this->customers->can($actor, 'crm.lead.claim')) {
                $q->orWhere(function ($pool) use ($grant) {
                    $pool->where('status', 'public_pool')->whereIn('department_legacy_id', $this->assignments->expandDepartments($grant->department_ids));
                    if ($grant->channels) $pool->whereIn('source_channel', $grant->channels);
                });
            }
            $q->orWhere(fn ($scope) => $this->assignments->applyVisible($scope, $actor));
            $q->orWhere(fn ($delegated) => $this->applyDelegated($delegated, $actor));
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
        $lead = $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        if ($lock && $this->delegated($lead, $actor)) {
            abort_unless($this->delegated($lead, $actor, true), 403, '线索代班已到期或结束，请刷新后重试。');
        }
        return $lead;
    }

    public function applyDelegated(Builder $query, object $actor): void
    {
        $owners = app(CrmSalesDelegationService::class)->activeOwners($actor);
        $query->where(fn ($assigned) => $assigned->where(fn ($pending) => $pending->where('status', 'pending_acceptance')->whereIn('pending_user_legacy_id', $owners))
            ->orWhere(fn ($owned) => $owned->whereIn('status', self::DELEGATED_OWNER_STATUSES)->whereIn('owner_legacy_id', $owners)));
    }

    /** 代班只沿该线索当前归属，不沿共同客户扩大到其他销售的线索。 */
    public function delegated(CrmLead $lead, object $actor, bool $lock = false): bool
    {
        $owner = $lead->status === 'pending_acceptance' ? (int) $lead->pending_user_legacy_id
            : (in_array($lead->status, self::DELEGATED_OWNER_STATUSES, true) ? (int) $lead->owner_legacy_id : 0);
        if (!$owner || $owner === (int) $actor->legacy_id) return false;
        return app(CrmSalesDelegationService::class)->delegatesFor($actor, $owner, $lock);
    }

    public function applyCollaborating(Builder $query, object $actor): void
    {
        $query->whereExists(fn ($c) => $this->collaborationQuery($c, (int) $actor->legacy_id)->whereColumn('c.customer_id', 'erp_crm_leads.customer_id'));
    }

    public function abilities(CrmLead $lead, object $actor, bool $lock = false): array
    {
        $own = (int) $lead->owner_legacy_id === (int) $actor->legacy_id;
        $draft = $lead->status === 'draft';
        $creator = (int) $lead->created_by_legacy_id === (int) $actor->legacy_id;
        $isSales = !$this->auth->isSuperAdmin($actor) && $this->staff->isActiveSales((int) $actor->legacy_id);
        $can = fn ($action) => $this->customers->can($actor, 'crm.lead.'.$action);
        $scope = fn ($action, $target = null) => $this->assignments->allows($actor, $action, $lead->department_legacy_id, $lead->source_channel, $target, $lock);
        $assign = $scope('assign');
        $delegated = $this->delegated($lead, $actor, $lock);
        $handles = $own || $delegated;
        $receives = (int) $lead->pending_user_legacy_id === (int) $actor->legacy_id || $delegated;
        $manages = $handles || $assign;
        $managerRecycle = $can('recycle') && in_array($lead->status, ['active', 'pending_acceptance', 'pending_assignment'], true) && $scope('recycle');
        $selfRelease = ($can('release') || $can('recycle')) && $lead->status === 'active' && $handles;
        $editable = $can('edit') && match ($lead->status) {
            'draft' => $creator,
            'pending_assignment' => !$isSales && ($creator || $assign),
            'pending_acceptance', 'public_pool' => !$isSales && $assign,
            'active' => $manages,
            default => false,
        };
        $exclusive = $editable && !$draft && $this->isExclusivePotentialCustomer($lead, $lead->customer, $lock);
        return [
            'edit' => $editable,
            'edit_identity' => $draft || $exclusive,
            'edit_department' => $draft ? !$isSales : ($exclusive && !$isSales && in_array($lead->status, ['pending_assignment', 'pending_acceptance', 'public_pool'], true) && $assign),
            'edit_channel' => $draft || ($exclusive && ($lead->status === 'active' || $assign)),
            'edit_custom_tags' => $editable && (int) $lead->created_by_legacy_id === (int) $actor->legacy_id,
            'assign' => $can('assign') && in_array($lead->status, ['pending_assignment', 'public_pool'], true) && $assign,
            'accept' => $can('accept') && $lead->status === 'pending_acceptance' && $receives,
            'return' => $can('return') && $lead->status === 'pending_acceptance' && $receives,
            'transfer' => $can('transfer') && $lead->status === 'active' && $scope('transfer'),
            'recycle' => $managerRecycle || $selfRelease,
            'self_release' => !$managerRecycle && $selfRelease,
            'claim' => $can('claim') && $lead->status === 'public_pool' && $scope('claim', (int) $actor->legacy_id),
            'pool' => $can('pool') && in_array($lead->status, ['active', 'converted'], true) && $handles,
            'invalidate' => $can('invalidate') && in_array($lead->status, ['active', 'pending_assignment'], true) && $manages,
            'progress' => $lead->status === 'active' && ($editable || ($can('follow') && ($handles || ($lead->customer_id && $this->canFollowCustomer($lead->customer_id, $actor))))),
            'follow' => $can('follow') && $lead->status === 'active' && ($handles || ($lead->customer_id && $this->canFollowCustomer($lead->customer_id, $actor))),
            'convert' => $this->customers->can($actor, 'crm.opportunity.create') && app(CrmOpportunityApplicationService::class)->canConvert($lead, $actor),
        ];
    }

    public function recycleTargetDepartments(CrmLead $lead, object $actor, bool $lock = false): array
    {
        return $this->recycleTargets($lead, $actor, $this->abilities($lead, $actor, $lock), $lock);
    }

    private function recycleTargets(CrmLead $lead, object $actor, array $abilities, bool $lock = false, ?int $target = null): array
    {
        if (!$abilities['recycle']) return [];
        $ids = null;
        if ($abilities['self_release']) {
            $ids = $this->assignments->ancestorDepartments($lead->department_legacy_id ?: $this->staff->departmentOf((int) $actor->legacy_id, $lock), $lock);
        } elseif (!$this->auth->isSuperAdmin($actor)) {
            $grant = $this->assignments->activeGrant($actor, $lock);
            if (!$grant || !in_array('recycle', $grant->actions, true)) return [];
            $ids = $this->assignments->ancestorDepartments($lead->department_legacy_id, $lock);
            if ($target === null) {
                $ids = array_unique(array_merge($ids, $this->assignments->expandDepartments($grant->department_ids, $lock)));
            } elseif (!in_array($target, $ids, true) && $this->assignments->grantContainsDepartment($grant, $target, $lock)) {
                $ids[] = $target;
            }
        } elseif ($target !== null) {
            $ids = [$target];
        }
        if ($target !== null) return in_array($target, $ids, true) ? [$target] : [];
        if ($lock) {
            if ($ids !== null) return array_values($ids);
            return DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')
                ->orderBy('legacy_id')->pluck('legacy_id')->map(fn ($id) => (int) $id)->all();
        }
        $activeIds = array_column($this->recycleDepartmentOptions(), 'id');
        return $ids === null ? $activeIds : array_values(array_intersect($activeIds, $ids));
    }

    private function recycleDepartmentOptions(): array
    {
        return $this->recycleDepartmentOptions ??= DB::table('erp_departments')->whereNull('deleted_at')->where('status', 'normal')
            ->orderBy('sort')->orderBy('legacy_id')->get(['legacy_id', 'parent_legacy_id', 'name'])
            ->map(fn ($department) => ['id' => (int) $department->legacy_id, 'parent_id' => (int) $department->parent_legacy_id, 'name' => $department->name])->all();
    }

    public function withRecycleOrigin(Builder $query): void
    {
        $query->addSelect(['recycle_origin_department_id' => DB::table('erp_crm_lead_logs')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(before_snapshot, '$.department_legacy_id'))")
            ->whereColumn('lead_id', 'erp_crm_leads.id')->whereIn('action', ['claim', 'accept'])->orderByDesc('id')->limit(1)]);
    }

    /** 仅独占潜在客户可随线索纠错；保存时以持锁读取复核共享业务，避免覆盖其他业务档案。 */
    private function isExclusivePotentialCustomer(CrmLead $lead, ?SalesCustomer $customer, bool $lock = false): bool
    {
        if (!$customer || $customer->status !== 'potential' || (int) $customer->id !== (int) $lead->customer_id) return false;
        $owner = match ($lead->status) {
            'active' => (int) $lead->owner_legacy_id,
            'pending_assignment', 'pending_acceptance', 'public_pool' => (int) $lead->previous_owner_legacy_id,
            default => null,
        };
        if ($owner === null || ($customer->owner_legacy_id !== null && (int) $customer->owner_legacy_id !== $owner)) return false;
        $otherLeads = DB::table('erp_crm_leads')->where('customer_id', $customer->id)->where('id', '!=', $lead->id);
        if ($otherLeads->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) return false;
        foreach (['erp_crm_opportunities', 'erp_sales_orders', 'erp_sales_invoice_requests', 'erp_sales_returns', 'erp_aftersales_cases', 'erp_customization_records'] as $table) {
            if (DB::table($table)->where('customer_id', $customer->id)->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) return false;
        }
        return DB::table('erp_crm_customer_collaborators')->where('customer_id', $customer->id)->where('status', 'active')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) === null;
    }

    public function present(CrmLead $lead, object $actor): array
    {
        $row = $lead->toArray();
        unset($row['latest_followup']);
        if (!$row['latest_followup_summary']) $row['latest_followup_summary'] = $lead->latestFollowup?->content;
        $row['latest_followup_content'] = $lead->latestFollowup?->content;
        $row['latest_followup_at'] = $lead->latestFollowup?->contacted_at?->toDateTimeString() ?? $lead->last_contact_at?->toDateTimeString();
        unset($row['customer_input_snapshot'], $row['legacy_payload'], $row['recycle_origin_department_id']);
        if ((int) $lead->created_by_legacy_id !== (int) $actor->legacy_id) $row['custom_tags'] = [];
        if ($lead->status === 'pending_review' && (int) $lead->created_by_legacy_id !== (int) $actor->legacy_id) {
            foreach (['mobile', 'contact_value2'] as $field) if ($row[$field]) $row[$field] = mb_strlen($row[$field]) >= 7 ? mb_substr($row[$field], 0, 3).'****'.mb_substr($row[$field], -4) : '***';
            $row['wechat'] = $row['wechat'] ? '***' : null;
            $row['email'] = $row['email'] ? '***' : null;
        }
        $row['customer_pool'] = app(CrmCustomerPoolService::class)->normalize($lead->customer_pool);
        $row['nine_step_stage'] = CrmNineStepCatalog::normalizeStage('lead', $lead->nine_step_stage);
        $row['department_name'] = $this->customers->departmentName($lead->department_legacy_id);
        $row['status_label'] = self::STATUSES[$lead->status] ?? $lead->status;
        $row['abilities'] = $this->abilities($lead, $actor);
        $recycleDepartments = $this->recycleTargets($lead, $actor, $row['abilities']);
        $row['recycle_departments'] = $recycleDepartments
            ? array_values(array_filter($this->recycleDepartmentOptions(), fn ($department) => in_array($department['id'], $recycleDepartments, true))) : [];
        $row['default_recycle_department_id'] = null;
        if ($recycleDepartments) {
            $origin = array_key_exists('recycle_origin_department_id', $lead->getAttributes()) ? $lead->getAttribute('recycle_origin_department_id')
                : DB::table('erp_crm_lead_logs')->where('lead_id', $lead->id)->whereIn('action', ['claim', 'accept'])->orderByDesc('id')
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(before_snapshot, '$.department_legacy_id')) as department_id")->value('department_id');
            $default = in_array((int) $origin, $recycleDepartments, true) ? (int) $origin : (int) $lead->department_legacy_id;
            if (in_array($default, $recycleDepartments, true)) $row['default_recycle_department_id'] = $default;
        }
        $row['delegated'] = $this->delegated($lead, $actor);
        $row['delegated_owner'] = $row['delegated'] ? ['id' => (int) ($lead->pending_user_legacy_id ?: $lead->owner_legacy_id),
            'name' => $lead->pending_user_name ?: $lead->owner_name] : null;
        $opportunity = CrmOpportunity::query()->where('source_lead_id', $lead->id)->first();
        $row['has_opportunity'] = $opportunity !== null;
        $row['source_opportunity'] = null;
        if ($opportunity && $this->customers->can($actor, 'crm.opportunity.view')) {
            $query = CrmOpportunity::query()->whereKey($opportunity->id);
            app(CrmOpportunityApplicationService::class)->applyVisible($query, $actor);
            if ($query->exists()) $row['source_opportunity'] = $opportunity->only(['id', 'opportunity_no', 'name']);
        }
        $row['opportunity_id'] = $row['source_opportunity']['id'] ?? null;
        $row['opportunity_no'] = $row['source_opportunity']['opportunity_no'] ?? null;
        if ($lead->customer_id) {
            $customer = $lead->customer;
            $row['customer'] = $customer && $this->customers->relation($customer, $actor) !== null
                ? $customer->only(['id', 'customer_code', 'customer_name', 'owner_name', 'crm_version', 'business_lines'])
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
            $formal = $id && $lead->status !== 'draft';
            $mode = $data['mode'] ?? ($formal ? 'submit' : 'draft');
            $customer = null;
            $exclusiveCustomer = false;
            if ($id) {
                $this->version($lead, $data);
                if ($formal) abort_unless($mode === 'submit' && in_array($lead->status, ['pending_assignment', 'pending_acceptance', 'public_pool', 'active'], true), 422, '当前状态不能保存，正式线索不能存回草稿。');
                if ($formal) {
                    $customer = $lead->customer_id ? SalesCustomer::query()->lockForUpdate()->findOrFail($lead->customer_id) : null;
                    $lead->setRelation('customer', $customer);
                }
                $abilities = $this->abilities($lead, $actor, true);
                abort_unless($abilities['edit'], 403, '当前线索不能编辑。');
                $exclusiveCustomer = $formal && $abilities['edit_identity'];
                if ($this->delegated($lead, $actor, true) && !$this->assignments->allows($actor, 'assign', $lead->department_legacy_id, $lead->source_channel, null, true)
                    && array_key_exists('contact_id', $data)) {
                    abort_unless((int) $data['contact_id'] === (int) $lead->contact_id, 422, '代班只能使用该线索已关联的联系人。');
                }
            }
            $before = $id ? $this->snapshot($lead) : null;
            $beforeInput = $formal ? $this->input($lead) : [];
            $values = array_intersect_key($data, array_flip(self::EDITABLE));
            // 共享线索不会共享创建人的个人标签，编辑时的空数组也不能清除他人的值。
            if ($id && (int) $lead->created_by_legacy_id !== (int) $actor->legacy_id) unset($values['custom_tags']);
            if ($formal) {
                $changed = fn ($field) => array_key_exists($field, $values) && (string) ($values[$field] ?? '') !== (string) ($lead->$field ?? '');
                if ($changed('customer_id')) throw ValidationException::withMessages(['customer_id' => '正式线索不能替换或解除关联客户。']);
                if (!$exclusiveCustomer) {
                    foreach (['customer_kind', 'customer_name', 'credit_code', 'department_legacy_id', 'source_channel', 'platform_legacy_id', 'platform_buyer_id'] as $field) {
                        if ($changed($field)) throw ValidationException::withMessages([$field => '该客户已被其他业务共用，不能通过线索修改客户身份或分配范围。']);
                    }
                }
                $departmentChanged = $changed('department_legacy_id');
                $channelChanged = $changed('source_channel') || $changed('platform_legacy_id') || $changed('platform_buyer_id');
                $department = array_key_exists('department_legacy_id', $values) ? $values['department_legacy_id'] : $lead->department_legacy_id;
                $channel = array_key_exists('source_channel', $values) ? $values['source_channel'] : $lead->source_channel;
                if ($departmentChanged) {
                    abort_unless($abilities['edit_department'] ?? false, 403, '没有修改线索所属部门的权限。');
                    abort_unless($department && $this->assignments->activeDepartment((int) $department, true), 422, '所属部门不存在或已停用。');
                }
                if ($channelChanged) abort_unless($abilities['edit_channel'] ?? false, 403, '没有修改线索来源渠道或平台身份的权限。');
                if (($departmentChanged || $channelChanged) && $lead->status === 'pending_acceptance') {
                    abort_unless($this->assignments->allows($actor, 'assign', $department ? (int) $department : null, $channel,
                        (int) $lead->pending_user_legacy_id, true), 422, '先退回待分配后再调整部门或渠道');
                }
                $handles = ($lead->status === 'active' && (int) $lead->owner_legacy_id === (int) $actor->legacy_id) || $this->delegated($lead, $actor, true);
                if ($departmentChanged || ($channelChanged && !$handles)) {
                    $this->assignments->assert($actor, 'assign', $department ? (int) $department : null, $channel);
                }
            }
            $clearedRegion = $formal && $lead->region_id && array_key_exists('region_id', $values) && !$values['region_id'];
            $addressChanged = false;
            if ($formal) {
                foreach (['province', 'city', 'district', 'detail_address'] as $field) {
                    if (array_key_exists($field, $values) && (string) ($values[$field] ?? '') !== (string) ($lead->$field ?? '')) $addressChanged = true;
                }
            }
            $contactChanged = $formal && array_key_exists('contact_id', $values) && (int) ($values['contact_id'] ?? 0) !== (int) $lead->contact_id;
            $lead->fill($values);
            if ($contactChanged && $lead->contact_id) {
                $contact = DB::table('erp_sales_customer_contacts')->where('id', $lead->contact_id)->where('customer_id', $lead->customer_id)->lockForUpdate()->first(['id']);
                if (!$contact) {
                    throw ValidationException::withMessages(['contact_id' => '联系人不存在或不属于当前客户。']);
                }
            }
            if ($lead->region_id) {
                $address = app(CustomerRegionService::class)->address((int) $lead->region_id, (string) $lead->detail_address,
                    $id ? (int) $lead->getRawOriginal('region_id') : null);
                $lead->fill(array_intersect_key($address, array_flip(['province', 'city', 'district', 'full_address'])));
            } elseif ($clearedRegion || ($formal && $addressChanged)) {
                // 仅修改地址组成时重算，避免旧汇总覆盖本次修改；未动的历史整段地址继续保留。
                if ($clearedRegion) $lead->fill(['province' => null, 'city' => null, 'district' => null]);
                $lead->full_address = trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])) ?: null;
            } elseif (!trim((string) $lead->full_address)) {
                $lead->full_address = trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])) ?: null;
            }
            if ($formal && mb_strlen((string) $lead->full_address) > 500) {
                throw ValidationException::withMessages(['full_address' => '完整地址不能超过500字，请缩短详细地址。']);
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
                $lead->customer_pool = $lead->customer_pool ?: '新线索4小时快转池';
                $lead->is_new_inquiry = $lead->is_new_inquiry ?? true;
            }
            if ($lead->entry_method === 'self' && $lead->status === 'draft') {
                $lead->department_legacy_id = $this->staff->departmentOf((int) $lead->created_by_legacy_id);
                $this->assignments->assert($actor, 'self_entry', $lead->department_legacy_id, $lead->source_channel, (int) $actor->legacy_id);
            } elseif ($lead->status === 'draft') {
                $lead->department_legacy_id = $lead->department_legacy_id ?: $this->staff->departmentOf((int) $actor->legacy_id);
                $this->assignments->assert($actor, 'assign', $lead->department_legacy_id, $lead->source_channel);
            }
            $lead->customer_pool = app(CrmCustomerPoolService::class)->assertSelectable($lead->customer_pool, 'lead', $id ? $lead->getOriginal('customer_pool') : null);
            if (!$lead->trade_type || $lead->isDirty('department_legacy_id')) $lead->trade_type = CrmNineStepCatalog::tradeType($lead->department_legacy_id);
            if (!$id || !$formal || $lead->isDirty(['customer_id', 'customer_name', 'mobile', 'wechat', 'platform_legacy_id', 'platform_buyer_id'])) {
                $lead->is_new_inquiry = $this->isNewInquiry($lead->customer_id, $this->input($lead), $id);
            } else {
                $lead->is_new_inquiry = $lead->getOriginal('is_new_inquiry');
            }
            $lead->customer_input_snapshot = $this->input($lead);
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
            } elseif ($formal) {
                $this->validateFormalEdit($lead, $data, $actor, $customer, $exclusiveCustomer, $beforeInput);
                if ($exclusiveCustomer) $this->syncExclusiveCustomer($lead, $customer, $actor, $address['country'] ?? null);
            } else {
                abort_unless($mode === 'draft', 422, '当前状态不能保存。');
            }
            if ($id) $lead->version++;
            $lead->save();
            if (array_key_exists('attachment_ids', $data)) app(CrmAttachmentApplicationService::class)->bind($data['attachment_ids'] ?? [], 'lead', $lead->id, $actor);
            $this->log($lead, $id ? 'edit' : 'create', $mode === 'review' ? '申请客户身份核实' : ($lead->status === 'draft' ? '保存线索草稿' : ($id ? '修改线索资料' : '录入线索')), $before, $this->snapshot($lead), $data['duplicate_reason'] ?? null, $actor);
            if ($lead->customer_id) app(CrmFollowupApplicationService::class)->refreshContactFacts($lead->customer_id, $lead->id, false);
            return $lead->fresh();
        }, 3);
    }

    private function validateFormalEdit(CrmLead $lead, array $data, object $actor, ?SalesCustomer $customer, bool $exclusiveCustomer, array $beforeInput): void
    {
        abort_unless(in_array($lead->customer_kind, ['individual', 'enterprise'], true), 422, '请选择有效的客户类型。');
        abort_if(trim((string) $lead->customer_name) === '' || !$lead->department_legacy_id || trim((string) $lead->source_channel) === '', 422, '请补齐客户姓名、所属部门和来源渠道。');
        abort_unless(DB::table('erp_departments')->where('legacy_id', $lead->department_legacy_id)->exists(), 422, '所属部门不存在。');
        abort_unless(isset(self::CHANNELS[$lead->source_channel]), 422, '请选择有效的来源渠道。');
        $online = $this->isOnlineLead($lead);
        if ($online) {
            abort_unless($lead->platform_legacy_id && trim((string) $lead->platform_buyer_id) !== '', 422, '线上线索请填写接待店铺和平台买家ID。');
            abort_unless(DB::table('erp_sales_order_trade_platforms')->where('legacy_id', $lead->platform_legacy_id)->where('enabled', true)->where('parent_legacy_id', 0)->exists(), 422, '接待店铺已停用或不存在。');
            try {
                $resolved = $this->identities->assertCustomer((int) ($customer?->id ?? 0), $lead->platform_legacy_id, $lead->platform_buyer_id, true);
            } catch (HttpResponseException $error) {
                throw ValidationException::withMessages(['platform_buyer_id' => '该平台买家ID已属于其他客户，不能重复绑定。']);
            }
            abort_unless($resolved['key'], 422, '该平台未设置买家ID识别方式，不能保存正式线索。');
        }
        if (!$exclusiveCustomer || !$customer) return;
        $input = $this->input($lead);
        foreach (['region_id', 'platform_legacy_id'] as $field) {
            $beforeInput[$field] = !empty($beforeInput[$field]) ? (int) $beforeInput[$field] : null;
            $input[$field] = !empty($input[$field]) ? (int) $input[$field] : null;
        }
        if ($beforeInput === $input) return;
        $matches = $this->dedupe->match($input, $customer->id, true);
        foreach ($matches as $match) {
            if (!$match['strong']) continue;
            $field = in_array('credit_code', $match['reasons'], true) ? 'credit_code' : 'platform_buyer_id';
            throw ValidationException::withMessages([$field => '统一社会信用代码或平台身份已属于其他客户，不能重复使用。']);
        }
        if ($matches && (empty($data['duplicate_confirmed']) || trim((string) ($data['duplicate_reason'] ?? '')) === '')) {
            throw new HttpResponseException(response()->json([
                'message' => '发现疑似客户，请确认是否为同一客户。', 'duplicates' => $this->dedupe->present($matches, $actor),
                'requires_confirmation' => true, 'review_required' => false,
            ], 422));
        }
    }

    public function act(int $id, string $action, array $data, object $actor): CrmLead
    {
        $this->permission($actor, 'crm.lead.'.$action);
        return DB::transaction(function () use ($id, $action, $data, $actor) {
            $lead = $this->visible($id, $actor, true);
            $this->version($lead, $data);
            $abilities = $this->abilities($lead, $actor, true);
            abort_unless($abilities[$action] ?? false, 403, '当前线索状态或授权范围不允许该操作，请刷新后重试。');
            $before = $this->snapshot($lead);
            $reason = trim((string) ($data['reason'] ?? ''));
            if (in_array($action, ['assign', 'transfer', 'return', 'recycle', 'invalidate'], true)) {
                abort_if($reason === '', 422, '请选择操作原因。');
                abort_if($reason === '其他' && trim((string) ($data['note'] ?? '')) === '', 422, '选择其他原因时请补充说明。');
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
                    $owner = (int) $lead->pending_user_legacy_id;
                    abort_unless($this->staff->isActiveSales($owner), 422, '销售员档案已失效，不能接收线索。');
                    $now = now();
                    $this->setOwner($lead, $owner);
                    $lead->accepted_at = $now;
                    $lead->nine_step_stage ??= CrmNineStepCatalog::LEAD[0];
                    $lead->win_probability ??= CrmNineStepCatalog::probability($lead->nine_step_stage);
                    $lead->status = 'active';
                    $this->customerApp->claimIfUnowned($lead->customer_id, $owner, '接收线索', $actor, $lead->id);
                    break;
                case 'return':
                    $lead->pending_user_legacy_id = null;
                    $lead->pending_user_name = null;
                    $lead->status = 'pending_assignment';
                    break;
                case 'recycle':
                    if (!$abilities['self_release']) $this->assignments->assert($actor, 'recycle', $lead->department_legacy_id, $lead->source_channel);
                    $target = (int) ($data['target_department_legacy_id'] ?? $lead->department_legacy_id);
                    abort_unless(in_array($target, $this->recycleTargets($lead, $actor, $abilities, true, $target), true), 422, '所选目标公海层级不在允许范围内。');
                    $targetDepartment = DB::table('erp_departments')->where('legacy_id', $target)->whereNull('deleted_at')->where('status', 'normal')
                        ->lockForUpdate()->first(['name']);
                    abort_unless($targetDepartment, 422, '所选目标公海层级不在允许范围内。');
                    $recycleSummary = ($abilities['self_release'] ? '释放线索到公海：' : '人工回收到公海：').$targetDepartment->name;
                    $lead->department_legacy_id = $target;
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
                    $now = now();
                    $this->setOwner($lead, (int) $actor->legacy_id);
                    $lead->status = 'active';
                    $lead->accepted_at = $now;
                    $lead->nine_step_stage ??= CrmNineStepCatalog::LEAD[0];
                    $lead->win_probability ??= CrmNineStepCatalog::probability($lead->nine_step_stage);
                    $this->customerApp->claimIfUnowned($lead->customer_id, (int) $actor->legacy_id, '领取线索', $actor, $lead->id);
                    break;
                case 'pool':
                    $lead->customer_pool = app(CrmCustomerPoolService::class)->assertSelectable($data['customer_pool'] ?? null, 'lead', $lead->customer_pool);
                    break;
                case 'invalidate':
                    $lead->status = 'invalid';
                    $lead->invalid_reason = $reason;
                    break;
            }
            $lead->version++;
            $lead->save();
            $labels = ['assign' => '分配线索', 'transfer' => '转交线索，待新负责人接收', 'accept' => '接收线索', 'return' => '退回待分配', 'recycle' => $recycleSummary ?? '人工回收到公海', 'claim' => '领取公海线索', 'pool' => '人工调整客户池', 'invalidate' => '标记线索无效'];
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

    public function isNewInquiry(?int $customerId, array $input, ?int $excludeLeadId = null): bool
    {
        $ids = array_values(array_unique(array_filter(array_merge([$customerId], array_column($this->dedupe->match($input), 'customer_id')))));
        if (!$ids) return true;
        if (CrmLead::query()->whereIn('customer_id', $ids)->when($excludeLeadId, fn ($q) => $q->where('id', '!=', $excludeLeadId))->where('status', '!=', 'draft')->exists()) return false;
        foreach (['erp_crm_opportunities', 'erp_sales_orders'] as $table) if (DB::table($table)->whereIn('customer_id', $ids)->exists()) return false;
        if (DB::table('erp_crm_followups')->whereIn('customer_id', $ids)->where('subject_type', 'customer')->exists()) return false;
        return true;
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

    /** 调用方已锁定线索、客户及平台规则并确认独占；不走全量子表同步，保留其余联系人和地址。 */
    private function syncExclusiveCustomer(CrmLead $lead, SalesCustomer $customer, object $actor, ?string $country): void
    {
        $contactName = $lead->contact_name ?: $lead->customer_name;
        if (mb_strlen((string) $contactName) > 80) {
            throw ValidationException::withMessages(['contact_name' => '请填写不超过80字的实际联系人姓名。']);
        }
        $contact = $lead->contact_id ? $customer->contacts()->whereKey($lead->contact_id)->lockForUpdate()->first() : null;
        $contact ??= $customer->contacts()->where('is_default', true)->where('status', 'enabled')->orderBy('id')->lockForUpdate()->first();
        $address = $customer->addresses()->where('is_default', true)->where('status', 'enabled')->orderBy('id')->lockForUpdate()->first();
        $contactFields = ['id', 'contact_name', 'mobile', 'phone', 'wechat', 'email', 'position', 'is_default', 'status'];
        $addressFields = ['id', 'region_id', 'country', 'province', 'city', 'district', 'detail_address', 'full_address', 'receiver_name', 'receiver_phone', 'is_default', 'status'];
        $customerFields = ['customer_name', 'customer_kind', 'credit_code', 'dedupe_name_key', 'region_id', 'customer_grade', 'industry', 'purchasing_power',
            'business_lines', 'first_source', 'contact_name', 'contact_phone', 'province', 'city', 'district', 'full_address', 'dedupe_address_key'];
        // 快照只比较业务字段；版本和更新时间变化不能让相同资料重复产生客户编辑日志。
        $snapshot = fn ($contact, $address) => $customer->only($customerFields)
            + ['contact' => $contact?->only($contactFields), 'default_address' => $address?->only($addressFields)];
        $before = $snapshot($contact, $address);
        $contact ??= $customer->contacts()->make(['is_default' => true, 'status' => 'enabled']);
        $contact->fill([
            'contact_name' => $contactName, 'mobile' => $lead->mobile, 'phone' => $lead->contact_value2,
            'wechat' => $lead->wechat, 'email' => $lead->email, 'position' => $lead->contact_role,
        ]);
        $hasAddress = $lead->region_id || trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address, $lead->full_address])) !== '';
        if ($hasAddress) {
            $address ??= $customer->addresses()->make(['is_default' => true, 'status' => 'enabled']);
            // 只有未拆分地区的存量整段地址才回退为详情，地区本身不能重复写进街道地址。
            $hasRegion = $lead->region_id || trim(implode('', [$lead->province, $lead->city, $lead->district])) !== '';
            $address->fill([
                'region_id' => $lead->region_id, 'province' => $lead->province, 'city' => $lead->city, 'district' => $lead->district,
                'detail_address' => (string) ($lead->detail_address ?: ($hasRegion ? '' : $lead->full_address)),
                'full_address' => $lead->full_address ?: trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])),
                'receiver_name' => $contactName, 'receiver_phone' => $lead->mobile,
            ]);
        } elseif ($address) {
            $address->fill(['is_default' => false, 'status' => 'disabled']);
        }
        // 没有关联地区的存量地址保留人工国家，清除原地区时则同步清空国家。
        if ($address && ($lead->region_id || $lead->getRawOriginal('region_id'))) $address->country = $country;
        $customer->fill($lead->only(['customer_name', 'customer_kind', 'region_id', 'customer_grade', 'industry', 'purchasing_power']) + [
            'credit_code' => $this->dedupe->normalizeCode($lead->credit_code),
            'dedupe_name_key' => CrmCustomerDedupeService::dedupeKey($lead->customer_name),
            'business_lines' => $lead->customer_business_lines, 'first_source' => $lead->source_channel,
            'contact_name' => $contact->contact_name, 'contact_phone' => $contact->mobile ?: $contact->phone,
            'province' => $hasAddress ? $address->province : null, 'city' => $hasAddress ? $address->city : null,
            'district' => $hasAddress ? $address->district : null, 'full_address' => $hasAddress ? $address->full_address : null,
            'dedupe_address_key' => CrmCustomerDedupeService::dedupeKey($hasAddress ? $address->full_address : null),
        ]);
        $changed = $customer->isDirty() || $contact->isDirty() || ($address && $address->isDirty());
        $customer->crm_version++;
        try {
            $customer->save();
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062 && str_contains($error->getMessage(), 'credit_code')) {
                throw ValidationException::withMessages(['credit_code' => '该统一社会信用代码已属于其他客户。']);
            }
            throw $error;
        }
        if ($contact->isDirty()) $contact->save();
        if ($address && $address->isDirty()) $address->save();
        $lead->contact_id = $contact->id;

        $online = $this->isOnlineLead($lead);
        $identityKey = $online ? $this->identities->resolve($lead->platform_legacy_id, $lead->platform_buyer_id, true)['key'] : null;
        $oldIdentities = $customer->identities()->where('source', 'lead')->where('status', 'confirmed')->orderBy('id')->lockForUpdate()->get();
        foreach ($oldIdentities as $oldIdentity) {
            if (!$online || $oldIdentity->identity_key !== $identityKey) {
                $this->identities->disable($oldIdentity, $actor, '线索线上身份更正');
            }
        }
        if ($online) {
            $this->identities->bind($customer, (int) $lead->platform_legacy_id, $lead->platform_buyer_id, 'lead', $actor, '线索编辑同步');
        }
        if ($changed) {
            $this->customerLogs->write($customer->id, 'edit', '随线索修改同步客户资料', $before, $snapshot($contact, $address), null, $actor);
        }
    }

    private function isOnlineLead(CrmLead $lead): bool
    {
        return $lead->source_channel === '线上平台' || $lead->platform_legacy_id || trim((string) $lead->platform_buyer_id) !== '';
    }

    private function formalize(CrmLead $lead, array $data, object $actor, bool $reviewed = false): void
    {
        $selfEntry = $lead->entry_method === 'self';
        if ($selfEntry) abort_unless($this->staff->isActiveSales((int) $lead->created_by_legacy_id), 422, '录入销售已失效，请退回修改后由运营重新分配。');
        abort_if(trim((string) $lead->customer_name) === '' || !$lead->department_legacy_id || trim((string) $lead->source_channel) === '', 422, '请补齐客户姓名、所属部门和来源渠道。');
        abort_unless(DB::table('erp_departments')->where('legacy_id', $lead->department_legacy_id)->exists(), 422, '所属部门不存在。');
        $online = $this->isOnlineLead($lead);
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
        $newCustomer = !$customer;
        $confirmedOtherCandidates = !empty($data['duplicate_confirmed']) && trim((string) ($data['duplicate_reason'] ?? '')) !== '';
        $otherMatches = $customer ? array_filter($matches, fn ($match) => (int) $match['customer_id'] !== (int) $customer->id) : [];
        $strongMismatch = $customer && array_diff($strongIds, [$customer->id]);
        if (!$reviewed && ($conflict || $strongMismatch || ($otherMatches && !$confirmedOtherCandidates) || (!$customer && $matches && !(!$strongIds && $confirmedOtherCandidates)))) {
            throw new HttpResponseException(response()->json(['message' => $conflict ? '客户身份指向不同客户，请申请核实。' : '发现疑似客户，请确认后关联或填写新建原因。',
                'duplicates' => $this->dedupe->present($matches, $actor), 'requires_confirmation' => !$strongIds, 'review_required' => (bool) ($conflict || $strongMismatch)], 422));
        }
        if ($customer) {
            if (!$reviewed) $this->customers->assert($customer, $actor, 'link_lead', '该客户当前不能新增线索，请先领取所属公海线索、申请核实或协作。');
            abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或列入黑名单。');
            if ($online) $this->identities->assertCustomer($customer->id, $lead->platform_legacy_id, $lead->platform_buyer_id, true);
            // 在客户锁内复核，避免选择客户后新出现的线索被直接重复录入；只提示本人有权查看的记录。
            if (!$reviewed && empty($data['new_demand_confirmed']) && $this->customers->can($actor, 'crm.lead.view')) {
                $existing = CrmLead::query()->where('customer_id', $customer->id)->where('id', '!=', $lead->id ?? 0);
                $this->applyVisible($existing, $actor);
                if ($existing->exists()) {
                    throw new HttpResponseException(response()->json([
                        'message' => '该客户已有线索，请选择继续跟进或新增需求。',
                        'requires_lead_choice' => true, 'customer_id' => (int) $customer->id,
                    ], 409));
                }
            }
        } else {
            $contact = ['contact_name' => $lead->contact_name ?: $lead->customer_name, 'mobile' => $lead->mobile, 'phone' => $lead->contact_value2,
                'wechat' => $lead->wechat, 'email' => $lead->email, 'position' => $lead->contact_role, 'is_default' => true, 'status' => 'enabled'];
            abort_if(mb_strlen($contact['contact_name']) > 80, 422, '请填写不超过80字的实际联系人姓名。');
            $address = $lead->full_address || $lead->detail_address ? [['full_address' => $lead->full_address ?: trim(implode('', [$lead->province, $lead->city, $lead->district, $lead->detail_address])),
                'province' => $lead->province, 'city' => $lead->city, 'district' => $lead->district, 'detail_address' => $lead->detail_address ?: $lead->full_address,
                'region_id' => $lead->region_id, 'receiver_name' => $contact['contact_name'], 'receiver_phone' => $lead->mobile, 'is_default' => true, 'status' => 'enabled']] : [];
            $customer = $this->customerApp->create($lead->only(['customer_name', 'customer_kind', 'credit_code', 'region_id', 'customer_grade', 'industry', 'purchasing_power']) + [
                'business_lines' => $lead->customer_business_lines,
                'status' => 'potential', 'contacts' => [$contact], 'addresses' => $address,
                'duplicate_confirmed' => $data['duplicate_confirmed'] ?? false, 'duplicate_reason' => $data['duplicate_reason'] ?? null,
            ], $actor, null, null, $selfEntry, $selfEntry ? (int) $lead->created_by_legacy_id : null);
            $lead->customer_id = $customer->id;
            $lead->contact_id = $customer->contacts->first()?->id;
        }
        if ($lead->contact_id) abort_unless(DB::table('erp_sales_customer_contacts')->where('id', $lead->contact_id)->where('customer_id', $customer->id)->where('status', 'enabled')->exists(), 422, '联系人不属于当前客户或已停用。');
        if ($online) {
            $identity = $this->identities->owner($resolved['key'], true);
            if (!$identity) {
                if (!$newCustomer) $this->customers->assert($customer, $actor, 'bind_identity', '无权为该客户登记新的平台身份，请由负责销售或管理人员办理。');
                $this->identities->bind($customer, $lead->platform_legacy_id, $lead->platform_buyer_id, 'lead', $actor, '线索录入时登记');
            }
        }
        if (!$customer->first_source && ($newCustomer || $this->customers->abilities($customer, $actor)['edit'])) $customer->update(['first_source' => $lead->source_channel]);
        if ($selfEntry) {
            $now = now();
            $this->setOwner($lead, (int) $lead->created_by_legacy_id);
            $lead->accepted_at = $now;
            $lead->confirmed_at ??= $now;
            $lead->status = 'active';
            $lead->nine_step_stage ??= CrmNineStepCatalog::LEAD[0];
            $lead->win_probability ??= CrmNineStepCatalog::probability($lead->nine_step_stage);
            $this->customerApp->claimIfUnowned($customer->id, $lead->owner_legacy_id, '录入线索', $actor, $lead->id);
        } else {
            $lead->owner_legacy_id = null;
            $lead->owner_name = null;
            $lead->pending_user_legacy_id = null;
            $lead->pending_user_name = null;
            $lead->accepted_at = null;
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
            ->where(fn ($scope) => $scope->whereNull('c.scope->type')->orWhere('c.scope->type', '!=', 'owner_opportunity_delegation'))
            ->where('c.effective_from', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>=', now()->toDateString()));
    }

    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? '')); }
}
