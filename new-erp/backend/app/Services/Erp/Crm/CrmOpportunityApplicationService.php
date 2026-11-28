<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmAttachment;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\CrmOpportunityFile;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 商机独立保存需求与归属；客户可见不等于可修改该客户的全部商机。 */
final class CrmOpportunityApplicationService
{
    private array $managementDepartments = [];
    public const STAGES = CrmNineStepCatalog::OPPORTUNITY;
    public const STATUSES = ['open' => '进行中', 'won' => '已成交', 'lost' => '已丢单'];
    public const FILE_CATEGORIES = ['requirement' => '需求', 'solution' => '方案', 'quotation' => '报价', 'contract' => '合同'];
    public const FILE_STATUSES = ['current' => '当前版', 'pending' => '待客户确认', 'history' => '历史版'];
    public const EDITABLE = ['name', 'contact_id', 'business_line', 'owner_legacy_id', 'expected_amount', 'currency', 'expected_close_date', 'demand_summary', 'next_contact_at', 'next_plan', 'customer_pool', 'trade_type'];

    public function __construct(
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmAssignmentAccessService $assignments,
        private readonly CrmSalesStaffService $staff,
        private readonly CrmLeadApplicationService $leads,
    ) {}

    public function permission(object $actor, string $code): void
    {
        abort_unless($this->customers->can($actor, $code), 403, '没有该操作权限。');
    }

    public function applyManageable(Builder $query, object $actor): void
    {
        $this->applyManagementRange($query, $actor, 'erp_crm_opportunities');
    }

    public function applyVisible(Builder $query, object $actor): void
    {
        if ($this->all($actor)) return;
        $query->where(function ($visible) use ($actor) {
            $visible->where(fn ($managed) => $this->applyManageable($managed, $actor))
                ->orWhere(fn ($delegated) => $this->applyDelegated($delegated, $actor))
                ->orWhereExists(fn ($collaboration) => $this->collaborationQuery($collaboration, $actor));
        });
    }

    public function applyCollaborating(Builder $query, object $actor): void
    {
        $query->whereExists(fn ($collaboration) => $this->collaborationQuery($collaboration, $actor));
    }

    public function applyDelegated(Builder $query, object $actor): void
    {
        $query->whereIn('erp_crm_opportunities.owner_legacy_id', app(CrmSalesDelegationService::class)->activeOwners($actor));
    }

    public function delegated(CrmOpportunity $opportunity, object $actor, bool $lock = false): bool
    {
        return app(CrmSalesDelegationService::class)->delegatesFor($actor, (int) $opportunity->owner_legacy_id, $lock);
    }

    public function visible(int $id, object $actor, bool $lock = false): CrmOpportunity
    {
        $query = CrmOpportunity::query();
        $this->applyVisible($query, $actor);
        if ($lock) {
            // 客户归属终身固定，统一先锁客户再锁商机，和订单、跟进汇总保持同一顺序。
            $customerId = (clone $query)->whereKey($id)->firstOrFail(['customer_id'])->customer_id;
            SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
        }
        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function canManage(CrmOpportunity $opportunity, object $actor): bool
    {
        return $this->managesOwner((int) $opportunity->owner_legacy_id, (int) $opportunity->department_legacy_id, $actor);
    }

    private function sharedActions(CrmOpportunity $opportunity, object $actor, bool $lockCollaboration): array
    {
        $collaboration = $this->customers->collaboration((int) $opportunity->customer_id, (int) $actor->legacy_id, $lockCollaboration);
        $scope = $collaboration ? (json_decode($collaboration->scope, true) ?: []) : [];
        if (($scope['type'] ?? null) === 'owner_opportunity_delegation'
            && (int) $opportunity->owner_legacy_id !== (int) $collaboration->created_by_legacy_id) return [];
        $actions = $collaboration ? (json_decode($collaboration->actions, true) ?: []) : [];
        $shared = !empty($actions['view']) && in_array((int) $opportunity->id, array_map('intval', $scope['opportunity_ids'] ?? []), true);
        if (!$shared) return [];
        if ((!empty($actions['stage']) || !empty($actions['order'])) && (!$collaboration->effective_to
            || !$this->customers->can($actor, 'crm.opportunity.view') || !$this->assignments->activeSales((int) $actor->legacy_id, $lockCollaboration))) {
            unset($actions['stage'], $actions['order']);
        }
        return $actions;
    }

    public function hasOrderDelegation(CrmOpportunity $opportunity, object $actor, bool $lockCollaboration = false): bool
    {
        return $this->delegated($opportunity, $actor, $lockCollaboration)
            || ((int) $actor->legacy_id !== (int) $opportunity->owner_legacy_id && !empty($this->sharedActions($opportunity, $actor, $lockCollaboration)['order']));
    }

    /** 来源商机只授权使用本商机的客户，不能变成全客户选择权限。 */
    public function orderCustomerContext(int $opportunityId, int $customerId, object $actor, bool $lock = false): SalesCustomer
    {
        $this->permission($actor, 'crm.opportunity.view');
        $opportunity = $this->visible($opportunityId, $actor, $lock);
        abort_unless($this->abilities($opportunity, $actor, $lock)['order'], 403, '当前商机不能新建订单。');
        abort_unless((int) $opportunity->customer_id === $customerId, 422, '来源商机与订单客户不一致。');
        return SalesCustomer::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($customerId);
    }

    public function abilities(CrmOpportunity $opportunity, object $actor, bool $lockCollaboration = false): array
    {
        $delegated = $this->delegated($opportunity, $actor, $lockCollaboration);
        $actions = $this->sharedActions($opportunity, $actor, $lockCollaboration);
        $manages = fn ($action) => $this->managesOwner((int) $opportunity->owner_legacy_id, (int) $opportunity->department_legacy_id, $actor, 'crm.opportunity.'.$action);
        $open = $opportunity->status === 'open';
        $can = fn ($action) => $this->customers->can($actor, 'crm.opportunity.'.$action);
        $abilities = [
            'view' => $can('view') && ($manages('view') || $delegated || !empty($actions['view'])),
            'edit' => $can('edit') && ($manages('edit') || $delegated) && $open,
            'stage' => $can('stage') && ($manages('stage') || $delegated || !empty($actions['stage'])) && $open,
            'lose' => $can('lose') && ($manages('lose') || $delegated) && $open && !$opportunity->won_at,
            'follow' => $can('follow') && ($manages('follow') || $delegated || !empty($actions['follow'])) && $opportunity->status !== 'lost',
            'file' => $can('file') && ($manages('file') || $delegated) && $opportunity->status !== 'lost',
            'order' => $can('order') && ($manages('order') || $delegated || !empty($actions['order'])) && $opportunity->status !== 'lost'
                && ($this->customers->can($actor, 'sales_order.create') || $this->customers->can($actor, 'sales.order.create')),
        ];
        return $abilities;
    }

    public function applyConvertible(Builder $query, object $actor): void
    {
        $query->where('erp_crm_leads.status', 'active')->whereNotNull('erp_crm_leads.customer_id')
            ->whereNotExists(fn ($q) => $q->from('erp_crm_opportunities as converted')->whereColumn('converted.source_lead_id', 'erp_crm_leads.id'));
        if (!$this->customers->can($actor, 'crm.opportunity.create') || !$this->customers->can($actor, 'crm.lead.view')) { $query->whereRaw('1 = 0'); return; }
        $query->where(fn ($allowed) => $allowed->where(fn ($managed) => $this->applyManagementRange($managed, $actor, 'erp_crm_leads', 'crm.opportunity.create'))
            ->orWhere(fn ($delegated) => $this->leads->applyDelegated($delegated, $actor)));
    }

    public function canConvert(CrmLead $lead, object $actor): bool
    {
        return $this->customers->can($actor, 'crm.opportunity.create') && $this->customers->can($actor, 'crm.lead.view')
            && $lead->status === 'active'
            && $lead->customer_id && ($this->managesOwner((int) $lead->owner_legacy_id, (int) $lead->department_legacy_id, $actor, 'crm.opportunity.create') || $this->leads->delegated($lead, $actor))
            && !CrmOpportunity::query()->where('source_lead_id', $lead->id)->exists();
    }

    public function owners(array $filters, object $actor)
    {
        abort_unless($this->customers->can($actor, 'crm.opportunity.create') || $this->customers->can($actor, 'crm.opportunity.edit'), 403, '没有维护商机负责人的权限。');
        $ids = null;
        if (!$this->all($actor, 'crm.opportunity.create') && !$this->all($actor, 'crm.opportunity.edit')) {
            $departmentUsers = array_unique(array_merge($this->departmentUsers($actor, 'crm.opportunity.create'), $this->departmentUsers($actor, 'crm.opportunity.edit')));
            $managed = $departmentUsers ? $this->managedDepartments($actor) : [];
            $ids = array_unique(array_merge([(int) $actor->legacy_id], $departmentUsers,
                $managed ? DB::table('erp_business_staff')->where('staff_type', 'sales')->whereIn('department_legacy_id', $managed)->pluck('user_legacy_id')->all() : []));
        }
        if (!empty($filters['ids'])) $ids = $ids === null ? $filters['ids'] : array_values(array_intersect($ids, $filters['ids']));
        return $this->staff->options(trim((string) ($filters['keyword'] ?? '')), (int) ($filters['per_page'] ?? 10), $ids);
    }

    public function save(?int $id, array $data, object $actor): CrmOpportunity
    {
        $this->permission($actor, $id ? 'crm.opportunity.edit' : 'crm.opportunity.create');
        try {
            return DB::transaction(function () use ($id, $data, $actor) {
                $lead = null;
                if (!$id) {
                    // 同一表单重试与同一来源线索各有唯一约束，不重复形成需求事实。
                    $existing = CrmOpportunity::query()->where('request_key', $data['request_key'])->first();
                    if ($existing) return $this->creationReplay($existing, $data, $actor);
                    if (!empty($data['source_lead_id'])) {
                        $this->leads->permission($actor, 'crm.lead.view');
                        $lead = $this->leads->visible((int) $data['source_lead_id'], $actor, true);
                        $converted = CrmOpportunity::query()->where('source_lead_id', $lead->id)->first();
                        if ($converted) return $this->visible((int) $converted->id, $actor);
                        $this->leads->version($lead, ['version' => $data['source_lead_version'] ?? 0]);
                        abort_unless($this->canConvert($lead, $actor), 403, '线索须已接收或领用，且由负责人或其管理人员转商机。');
                        abort_unless((int) $lead->customer_id === (int) $data['customer_id'], 422, '来源线索不属于当前客户。');
                    }
                }
                $opportunity = $id ? $this->visible($id, $actor, true) : new CrmOpportunity;
                if ($id) {
                    $this->version($opportunity, $data);
                    abort_unless($this->abilities($opportunity, $actor, true)['edit'], 403, '当前商机状态或归属不允许修改。');
                    abort_if(trim((string) ($data['reason'] ?? '')) === '', 422, '修改商机必须填写原因。');
                    abort_if(isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $opportunity->customer_id, 422, '商机不能更换客户。');
                    abort_if(array_key_exists('source_lead_id', $data) && (int) $data['source_lead_id'] !== (int) $opportunity->source_lead_id, 422, '商机不能更换来源线索。');
                }
                $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($id ? $opportunity->customer_id : $data['customer_id']);
                abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或列入黑名单。');
                $customerRelation = $this->customers->relation($customer, $actor);
                if (!$id && !$lead) abort_unless(in_array($customerRelation, ['all', 'owner', 'department', 'public', 'delegate'], true), 403, '无权为该客户新建商机。');
                $delegatedOwner = $id && $this->delegated($opportunity, $actor, true) ? (int) $opportunity->owner_legacy_id : null;
                if (!$id && !$lead && $customer->owner_legacy_id) {
                    $delegates = app(CrmSalesDelegationService::class)->delegatesFor($actor, (int) $customer->owner_legacy_id, true);
                    abort_if($customerRelation === 'delegate' && !$delegates, 403, '销售代班已到期或结束，请刷新后重试。');
                    if ($delegates) $delegatedOwner = (int) $customer->owner_legacy_id;
                }
                $before = $id ? $this->snapshot($opportunity) : null;
                if (!$id) {
                    $defaults = $lead ? ['contact_id' => $lead->contact_id,
                        'expected_amount' => $lead->expected_amount, 'currency' => $lead->currency, 'demand_summary' => $lead->demand_summary,
                        'next_contact_at' => $lead->next_contact_at, 'next_plan' => $lead->next_plan] : [];
                    if ($lead && trim((string) ($data['business_line'] ?? '')) === '') {
                        $leadLines = CrmBusinessLineOptions::normalize(is_array($lead->customer_business_lines) ? $lead->customer_business_lines : []);
                        if (count($leadLines) === 1) $data['business_line'] = $leadLines[0];
                    }
                    $opportunity->fill($defaults + ['currency' => 'CNY']);
                    $opportunity->fill(['opportunity_no' => 'OP'.now()->format('Ymd').strtoupper(Str::random(10)), 'request_key' => $data['request_key'],
                        'customer_id' => $customer->id, 'customer_name' => $customer->customer_name, 'source_lead_id' => $lead?->id,
                        'source_lead_no' => $lead?->lead_no, 'source_snapshot' => $lead?->attributesToArray(), 'stage' => 'step_4_solution', 'win_probability' => '50%｜方案匹配中', 'customer_pool' => '48小时推进池', 'trade_type' => $lead?->trade_type, 'status' => 'open',
                        'stage_entered_at' => now(), 'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor), 'version' => 1]);
                }
                $opportunity->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
                $delegatedConversion = $lead && $this->leads->delegated($lead, $actor, true);
                if ($delegatedConversion) {
                    $delegatedOwner = (int) $lead->owner_legacy_id;
                    abort_unless((int) $opportunity->contact_id === (int) $lead->contact_id, 422, '代班只能使用该线索已关联的联系人。');
                }
                if ($delegatedOwner) {
                    abort_unless(!array_key_exists('owner_legacy_id', $data) || (int) $data['owner_legacy_id'] === $delegatedOwner, 422, '代班商机须保留原负责销售，不能更换负责人。');
                    if ($id && $customerRelation === null && $opportunity->contact_id) {
                        abort_unless((int) $opportunity->contact_id === (int) $opportunity->getOriginal('contact_id'), 422, '只能使用该商机已关联的联系人。');
                    }
                }
                $this->setOwner($opportunity, $delegatedOwner ?? (int) ($data['owner_legacy_id'] ?? $opportunity->owner_legacy_id ?? $lead?->owner_legacy_id ?? $actor->legacy_id), $actor, $delegatedOwner);
                $opportunity->customer_pool = app(CrmCustomerPoolService::class)->assertSelectable($opportunity->customer_pool, 'opportunity', $id ? $opportunity->getOriginal('customer_pool') : null);
                if (!$opportunity->trade_type || ($id && $opportunity->isDirty('department_legacy_id') && !array_key_exists('trade_type', $data))) $opportunity->trade_type = CrmNineStepCatalog::tradeType($opportunity->department_legacy_id);
                $this->validateContactAndPlan($opportunity);
                if ($id) $opportunity->version++;
                $opportunity->save();
                foreach ($data['files'] ?? [] as $file) {
                    abort_unless($this->customers->can($actor, 'crm.opportunity.file'), 403, '没有维护商机文件的权限。');
                    $this->addFile($opportunity, $file, $actor);
                }
                $this->log($opportunity, $id ? 'edit' : 'create', $id ? '修改商机资料' : ($lead ? '线索转商机' : '新建商机'), $before, $this->snapshot($opportunity), $data['reason'] ?? null, $actor);
                if ($lead) {
                    \App\Models\Erp\CrmDrawingRequest::query()->where('lead_id', $lead->id)->whereNull('opportunity_id')->where('status', '!=', 'deleted')
                        ->update(['opportunity_id' => $opportunity->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                    // 后续待办由商机接续，原线索的计划和沟通历史仍完整保留。
                    $leadBefore = $this->leads->snapshot($lead);
                    $lead->confirmed_at ??= now();
                    $lead->status = 'converted';
                    $lead->version++;
                    $lead->save();
                    $this->leads->log($lead, 'convert', '转为商机 '.$opportunity->opportunity_no, $leadBefore,
                        $this->leads->snapshot($lead) + ['opportunity_id' => $opportunity->id, 'opportunity_no' => $opportunity->opportunity_no], '保留原线索来源与跟进记录', $actor);
                }
                app(CrmFollowupApplicationService::class)->refreshContactFacts((int) $customer->id, null, false);
                return $opportunity->fresh();
            }, 3);
        } catch (QueryException $error) {
            if ($id || ($error->errorInfo[1] ?? null) !== 1062) throw $error;
            $existing = CrmOpportunity::query()->where('request_key', $data['request_key'])->first();
            if ($existing) return $this->creationReplay($existing, $data, $actor);
            $converted = !empty($data['source_lead_id']) ? CrmOpportunity::query()->where('source_lead_id', $data['source_lead_id'])->first() : null;
            if ($converted) return $this->visible((int) $converted->id, $actor);
            throw $error;
        }
    }

    public function stage(int $id, array $data, object $actor): CrmOpportunity
    {
        if (!isset($data['stage']) && !isset($data['nine_step_stage']) && isset($data['direction'])) {
            $current = $this->visible($id, $actor);
            $keys = array_keys(self::STAGES);
            $index = array_search(CrmNineStepCatalog::normalizeStage('opportunity', $current->stage), $keys, true);
            $next = ($index === false ? 0 : $index) + ($data['direction'] === 'forward' ? 1 : -1);
            abort_unless(isset($keys[$next]), 422, '已到当前阶段范围边界。');
            $data['stage'] = $keys[$next];
        }
        return app(CrmProgressApplicationService::class)->update('opportunity', $id, $data, $actor);
    }

    public function lose(int $id, array $data, object $actor): CrmOpportunity
    {
        $this->permission($actor, 'crm.opportunity.lose');
        return DB::transaction(function () use ($id, $data, $actor) {
            $opportunity = $this->visible($id, $actor, true);
            $this->version($opportunity, $data);
            abort_unless($this->abilities($opportunity, $actor, true)['lose'], 403, '当前商机不允许丢单。');
            // 订单链统一 order → link → opportunity；这里只做非锁定读取，避免反向锁订单。
            abort_if(DB::table('erp_crm_opportunity_orders')->where('opportunity_id', $id)->whereNotNull('first_effective_at')->exists(), 422, '已有生效关联订单，不能丢单，成交历史必须保留。');
            foreach (['lost_reason', 'lost_note'] as $field) abort_if(trim((string) ($data[$field] ?? '')) === '', 422, '请补齐丢单原因与说明。');
            $before = $this->snapshot($opportunity);
            $opportunity->fill(array_intersect_key($data, array_flip(['lost_reason', 'lost_destination', 'lost_note'])));
            $opportunity->status = 'lost';
            $opportunity->lost_at = now();
            $opportunity->version++;
            $opportunity->save();
            $this->log($opportunity, 'lose', '商机丢单', $before, $this->snapshot($opportunity), $data['lost_note'], $actor);
            app(CrmFollowupApplicationService::class)->refreshContactFacts((int) $opportunity->customer_id, null, false);
            return $opportunity->fresh();
        }, 3);
    }

    /** 仅正式订单生效链持有商机行锁后调用；取消、退货不抹去首次成交事实。 */
    public function markWon(CrmOpportunity $opportunity, ?object $actor, int $orderId): void
    {
        $link = DB::table('erp_crm_opportunity_orders')->where('opportunity_id', $opportunity->id)->where('order_id', $orderId)->whereNotNull('first_effective_at')->first();
        abort_unless($link && (int) $link->customer_id === (int) $opportunity->customer_id, 422, '成交必须来自同一客户已生效的关联订单。');
        abort_if($opportunity->status === 'lost', 422, '已丢单商机不能形成成交。');
        if ($opportunity->status === 'won') return;
        $before = $this->snapshot($opportunity);
        $opportunity->stage = 'step_8_closing';
        $opportunity->win_probability = '100%｜已成交';
        $opportunity->status = 'won';
        $opportunity->won_at = $link->first_effective_at;
        $opportunity->version++;
        $opportunity->save();
        $this->log($opportunity, 'won', '关联订单 #'.$orderId.' 生效，商机成交', $before, $this->snapshot($opportunity), null, $actor);
        app(CrmFollowupApplicationService::class)->refreshContactFacts((int) $opportunity->customer_id, null, false);
    }

    public function saveFile(int $id, array $data, object $actor): CrmOpportunityFile
    {
        $this->permission($actor, 'crm.opportunity.file');
        return DB::transaction(function () use ($id, $data, $actor) {
            $opportunity = $this->visible($id, $actor, true);
            $this->version($opportunity, $data);
            abort_unless($this->abilities($opportunity, $actor, true)['file'], 403, '无权维护该商机文件。');
            $file = $this->addFile($opportunity, $data, $actor);
            $opportunity->version++;
            $opportunity->save();
            return $file;
        }, 3);
    }

    public function confirmFile(int $id, int $fileId, array $data, object $actor): CrmOpportunityFile
    {
        $this->permission($actor, 'crm.opportunity.file');
        return DB::transaction(function () use ($id, $fileId, $data, $actor) {
            $opportunity = $this->visible($id, $actor, true);
            $this->version($opportunity, $data);
            abort_unless($this->abilities($opportunity, $actor, true)['file'], 403, '无权维护该商机文件。');
            abort_if(trim((string) ($data['reason'] ?? '')) === '', 422, '请填写客户确认说明。');
            $file = CrmOpportunityFile::query()->available()->where('opportunity_id', $id)->lockForUpdate()->findOrFail($fileId);
            abort_unless($file->status === 'pending', 422, '只能确认待客户确认的版本。');
            $before = $file->attributesToArray();
            $this->retireCurrentFile($file);
            $file->fill(['status' => 'current', 'confirmed_at' => now(), 'confirmed_by_legacy_id' => $actor->legacy_id, 'confirmed_by_name' => $this->actorName($actor)])->save();
            app(\App\Services\Erp\TechnicalDrawingSourceService::class)->activateFile('opportunity', $id, $file->id);
            app(\App\Services\Erp\TechnicalDrawingApplicationService::class)->confirmLinked('opportunity', $id, $file->id, $actor, $data['reason']);
            $opportunity->version++;
            $opportunity->save();
            $this->log($opportunity, 'file_confirm', '客户确认'.self::FILE_CATEGORIES[$file->category].' V'.$file->version_no, $before, $file->attributesToArray(), $data['reason'], $actor, $file->id);
            return $file->fresh('attachment');
        }, 3);
    }

    public function presentFile(CrmOpportunityFile $file): array
    {
        return $file->attributesToArray() + ['category_label' => self::FILE_CATEGORIES[$file->category] ?? $file->category,
            'status_label' => self::FILE_STATUSES[$file->status] ?? $file->status,
            'technical_drawing' => app(\App\Services\Erp\TechnicalDrawingSourceService::class)->forFile('opportunity', $file->id),
            'attachment' => $file->attachment ? app(CrmAttachmentApplicationService::class)->present($file->attachment) : null];
    }

    public function removeFile(int $id, int $fileId, array $data, object $actor): CrmOpportunity
    {
        $this->permission($actor, 'crm.opportunity.file');
        return DB::transaction(function () use ($id, $fileId, $data, $actor) {
            $opportunity = $this->visible($id, $actor, true);
            abort_unless($this->abilities($opportunity, $actor, true)['file'], 403, '无权维护该商机文件。');
            $file = CrmOpportunityFile::query()->where('opportunity_id', $id)->lockForUpdate()->findOrFail($fileId);
            $attachment = CrmAttachment::query()->lockForUpdate()->findOrFail($file->attachment_id);
            abort_unless($attachment->subject_type === 'opportunity_file' && (int) $attachment->subject_id === (int) $file->id, 422, '附件归属无效。');
            // 商机锁串行化确认与移除；重复请求不再增加版本或日志，也不撤销交图和订单的独立引用。
            if (!$attachment->is_active) return $opportunity;
            $this->version($opportunity, $data);
            $before = $file->attributesToArray() + $attachment->only(['is_active', 'removed_at', 'removed_by_legacy_id']);
            $attachment->update(['is_active' => false, 'removed_at' => now(), 'removed_by_legacy_id' => $actor->legacy_id]);
            $opportunity->version++;
            $opportunity->save();
            $this->log($opportunity, 'file_remove', '删除'.(self::FILE_CATEGORIES[$file->category] ?? $file->category).' V'.$file->version_no,
                $before, $file->attributesToArray() + $attachment->only(['is_active', 'removed_at', 'removed_by_legacy_id']), null, $actor, $file->id);
            return $opportunity;
        }, 3);
    }

    public function present(CrmOpportunity $opportunity, object $actor): array
    {
        $abilities = $this->abilities($opportunity, $actor);
        if (!$abilities['view']) {
            // 保存与幂等重放不能另授查看权；无当前权限时只确认已办结果。
            return $opportunity->only(['id', 'opportunity_no', 'owner_legacy_id', 'owner_name']) + ['abilities' => $abilities];
        }
        $row = $opportunity->attributesToArray();
        if (!$row['latest_followup_summary']) $row['latest_followup_summary'] = $opportunity->latestFollowup?->content;
        $row['latest_followup_content'] = $opportunity->latestFollowup?->content;
        $row['latest_followup_at'] = $opportunity->latestFollowup?->contacted_at?->toDateTimeString() ?? $opportunity->last_contact_at?->toDateTimeString();
        unset($row['source_snapshot'], $row['request_key']);
        $customer = $opportunity->customer;
        $row['delegated'] = $this->delegated($opportunity, $actor);
        $row['delegated_owner'] = $row['delegated'] ? ['id' => (int) $opportunity->owner_legacy_id, 'name' => $opportunity->owner_name,
            'department_legacy_id' => $opportunity->department_legacy_id, 'department_name' => $this->customers->departmentName($opportunity->department_legacy_id)] : null;
        $row['customer'] = $customer && $this->customers->relation($customer, $actor) !== null
            ? $customer->only(['id', 'customer_code', 'customer_name', 'owner_name', 'crm_version']) : ($customer ? $this->customers->masked($customer) : null);
        if ($row['delegated'] && $customer) {
            $row['customer'] = $customer->only(['id', 'customer_code', 'customer_name', 'customer_kind', 'status', 'owner_legacy_id', 'owner_name']);
            $contacts = $customer->contacts()->where('status', 'enabled');
            if ($this->customers->relation($customer, $actor) === null) $contacts->whereKey($opportunity->contact_id ?: 0);
            $row['contacts'] = $contacts->orderByDesc('is_default')->orderBy('id')->get(['id', 'contact_name', 'mobile', 'phone', 'email', 'wechat', 'position', 'status'])->toArray();
        }
        $row['source_lead'] = $opportunity->source_lead_id ? ['id' => $opportunity->source_lead_id, 'lead_no' => $opportunity->source_lead_no] : null;
        $row['stage'] = CrmNineStepCatalog::normalizeStage('opportunity', $opportunity->stage);
        $row['stage_label'] = self::STAGES[$row['stage']] ?? $opportunity->stage;
        $row['customer_pool'] = app(CrmCustomerPoolService::class)->normalize($opportunity->customer_pool);
        $row['status_label'] = self::STATUSES[$opportunity->status] ?? $opportunity->status;
        $row['department_name'] = $this->customers->departmentName($opportunity->department_legacy_id);
        $row['stage_age_days'] = $opportunity->stage_entered_at ? (int) $opportunity->stage_entered_at->diffInDays($opportunity->lost_at ?? $opportunity->won_at ?? now()) : 0;
        $row['stage_days'] = $row['stage_age_days'];
        $row['files_count'] = $opportunity->files()->available()->count();
        $row['orders_count'] = 0;
        if ($this->customers->can($actor, 'sales_order.view')) {
            $orders = SalesOrder::query()->whereHas('crmOpportunityOrder', fn ($link) => $link->where('opportunity_id', $opportunity->id));
            app(SalesOrderVisibilityService::class)->apply($orders, $actor, true);
            $row['orders_count'] = $orders->count();
        }
        $row['abilities'] = $abilities + ['progress' => $abilities['stage']];
        return $row;
    }

    public function version(CrmOpportunity $opportunity, array $data): void
    {
        if ((int) ($data['version'] ?? 0) !== (int) $opportunity->version) throw ValidationException::withMessages(['version' => '商机已被其他人处理，请刷新后重试。']);
    }

    public function log(CrmOpportunity $opportunity, string $action, string $summary, ?array $before, ?array $after, ?string $reason, ?object $actor, ?int $fileId = null): void
    {
        DB::table('erp_crm_opportunity_stage_logs')->insert([
            'opportunity_id' => $opportunity->id, 'action' => $action, 'summary' => $summary, 'file_id' => $fileId,
            'from_stage' => $before['stage'] ?? null, 'to_stage' => $after['stage'] ?? null,
            'from_status' => isset(self::STATUSES[$before['status'] ?? '']) ? $before['status'] : null,
            'to_status' => isset(self::STATUSES[$after['status'] ?? '']) ? $after['status'] : null,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_snapshot' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), 'reason' => $reason,
            'operator_legacy_id' => $actor?->legacy_id, 'operator_name' => $actor ? $this->actorName($actor) : null, 'created_at' => now(),
        ]);
    }

    private function addFile(CrmOpportunity $opportunity, array $data, object $actor): CrmOpportunityFile
    {
        abort_if(($data['category'] ?? '') === 'solution', 422, '方案图请先提交绘图需求，由承接技术人员交图。');
        abort_unless(isset(self::FILE_CATEGORIES[$data['category'] ?? '']) && in_array($data['status'] ?? 'pending', ['current', 'pending'], true), 422, '请选择有效的文件类型与状态。');
        $attachment = CrmAttachment::query()->lockForUpdate()->findOrFail($data['attachment_id']);
        abort_unless(!$attachment->subject_type && (int) $attachment->uploaded_by_legacy_id === (int) $actor->legacy_id, 403, '只能引用本人上传且尚未绑定的私有附件。');
        $version = (int) $opportunity->files()->where('category', $data['category'])->max('version_no') + 1;
        $file = CrmOpportunityFile::create(['opportunity_id' => $opportunity->id, 'attachment_id' => $attachment->id,
            'category' => $data['category'], 'version_no' => $version, 'status' => $data['status'] ?? 'pending', 'note' => $data['note'] ?? null,
            'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
        if ($file->status === 'current') $this->retireCurrentFile($file);
        $attachment->update(['subject_type' => 'opportunity_file', 'subject_id' => $file->id, 'category' => $file->category]);
        $this->log($opportunity, 'file_create', '上传'.self::FILE_CATEGORIES[$file->category].' V'.$version, null, $file->attributesToArray(), $data['note'] ?? null, $actor, $file->id);
        return $file->fresh('attachment');
    }

    private function retireCurrentFile(CrmOpportunityFile $file): void
    {
        CrmOpportunityFile::query()->where('opportunity_id', $file->opportunity_id)->where('category', $file->category)
            ->where('status', 'current')->where('id', '!=', $file->id)->update(['status' => 'history']);
    }

    private function snapshot(CrmOpportunity $opportunity): array
    {
        return array_diff_key($opportunity->attributesToArray(), array_flip(['source_snapshot', 'request_key']));
    }

    private function creationReplay(CrmOpportunity $existing, array $data, object $actor): CrmOpportunity
    {
        abort_unless((int) $existing->created_by_legacy_id === (int) $actor->legacy_id && (int) $existing->customer_id === (int) $data['customer_id']
            && (int) $existing->source_lead_id === (int) ($data['source_lead_id'] ?? 0), 422, '提交标识已被其他需求使用，请重新打开新建窗口。');
        return $existing;
    }

    private function setOwner(CrmOpportunity $opportunity, int $id, object $actor, ?int $delegatedOwner = null): void
    {
        // 未变更的历史归属不随人员调岗重写；换负责人时才重新记录正式销售部门。
        if ($opportunity->exists && (int) $opportunity->getOriginal('owner_legacy_id') === $id) return;
        abort_unless($this->staff->isActiveSales($id), 422, '负责人须为在职且销售档案有效的销售。');
        $department = $this->staff->departmentOf($id, true);
        $delegated = $id === $delegatedOwner && app(CrmSalesDelegationService::class)->delegatesFor($actor, $id, true);
        abort_unless($department && ($this->managesOwner($id, $department, $actor, $opportunity->exists ? 'crm.opportunity.edit' : 'crm.opportunity.create') || $delegated), 403, '负责人不在本人或管理范围内。');
        $opportunity->owner_legacy_id = $id;
        $opportunity->owner_name = $this->staff->name($id);
        $opportunity->department_legacy_id = $department;
    }

    private function validateContactAndPlan(CrmOpportunity $opportunity): void
    {
        abort_if(trim((string) $opportunity->name) === '', 422, '请填写商机名称。');
        $opportunity->currency = strtoupper(trim((string) ($opportunity->currency ?: 'CNY')));
        abort_unless((bool) preg_match('/^[A-Z]{3}$/', $opportunity->currency), 422, '币种须为三位字母代码。');
        if ($opportunity->contact_id) {
            $contact = DB::table('erp_sales_customer_contacts')->where('id', $opportunity->contact_id)->where('customer_id', $opportunity->customer_id)->where('status', 'enabled')->first();
            abort_unless($contact, 422, '联系人不属于当前客户或已停用。');
            $opportunity->contact_name = $contact->contact_name;
        } else $opportunity->contact_name = null;
    }

    private function all(object $actor, string $permission = 'crm.opportunity.view'): bool { return $this->customers->dataScope($actor, $permission) === 'all'; }
    private function departmentUsers(object $actor, string $permission = 'crm.opportunity.view'): array { return $this->customers->dataScope($actor, $permission) === 'department' ? $this->customers->context($actor)['department_users'] : []; }
    private function managedDepartments(object $actor): array
    {
        return $this->managementDepartments[$actor->legacy_id] ??= $this->assignments->expandDepartments(DB::table('erp_department_users')
            ->where('user_legacy_id', $actor->legacy_id)->where('is_principal', true)->pluck('department_legacy_id')->all());
    }

    private function managesOwner(int $owner, ?int $department, object $actor, string $permission = 'crm.opportunity.view'): bool
    {
        return $owner === (int) $actor->legacy_id || $this->all($actor, $permission) || in_array($owner, $this->departmentUsers($actor, $permission), true)
            || ($this->customers->dataScope($actor, $permission) === 'department' && $department && in_array($department, $this->managedDepartments($actor), true));
    }

    private function applyManagementRange(Builder $query, object $actor, string $table, string $permission = 'crm.opportunity.view'): void
    {
        if ($this->all($actor, $permission)) return;
        $users = $this->departmentUsers($actor, $permission);
        $departments = $this->customers->dataScope($actor, $permission) === 'department' ? $this->managedDepartments($actor) : [];
        $query->where(function ($q) use ($actor, $table, $users, $departments) {
            $q->where($table.'.owner_legacy_id', $actor->legacy_id);
            if ($users) $q->orWhereIn($table.'.owner_legacy_id', $users);
            if ($departments) $q->orWhereIn($table.'.department_legacy_id', $departments);
        });
    }

    private function collaborationQuery($query, object $actor)
    {
        return $query->from('erp_crm_customer_collaborators as c')->whereColumn('c.customer_id', 'erp_crm_opportunities.customer_id')
            ->where('c.user_legacy_id', $actor->legacy_id)->where('c.status', 'active')->where('c.actions->view', true)
            ->where(fn ($type) => $type->whereNull('c.scope->type')->orWhere('c.scope->type', '!=', 'owner_opportunity_delegation')
                ->orWhere(fn ($own) => $own->whereColumn('c.created_by_legacy_id', 'erp_crm_opportunities.owner_legacy_id')
                    ->whereExists(fn ($customer) => $customer->from('erp_sales_customers as grant_customer')->whereColumn('grant_customer.id', 'c.customer_id')
                        ->whereColumn('grant_customer.owner_legacy_id', 'c.created_by_legacy_id'))))
            ->where('c.effective_from', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>=', now()->toDateString()))
            ->whereRaw("JSON_CONTAINS(c.scope, CAST(erp_crm_opportunities.id AS JSON), '$.opportunity_ids')");
    }

    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? '')); }
}
