<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\SalesCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 跟进按实际联系时间形成事实；每个对象只保留最新联系对应的下一次计划。 */
final class CrmFollowupApplicationService
{
    public const EDITABLE = ['contact_id', 'contact_name', 'method', 'contacted_at', 'content', 'result', 'next_contact_at', 'next_plan', 'followup_schedule_mode'];

    public function __construct(
        private readonly CrmLeadApplicationService $leads,
        private readonly CrmCustomerAccessService $access,
        private readonly CrmCustomerLogService $logs,
        private readonly CrmSalesStaffService $staff,
    ) {}

    public function subject(string $type, int $id, object $actor, bool $write = false, bool $lock = false): array
    {
        if ($type === 'opportunity') {
            $this->leads->permission($actor, 'crm.opportunity.view');
            $opportunities = app(CrmOpportunityApplicationService::class);
            $opportunity = $opportunities->visible($id, $actor, $lock);
            if ($write) abort_unless($opportunities->abilities($opportunity, $actor, $lock)['follow'], 403, '无权为该商机填写跟进。');
            return [SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($opportunity->customer_id), null, $opportunity];
        }
        if ($type === 'lead') {
            $this->leads->permission($actor, 'crm.lead.view');
            $lead = $this->leads->visible($id, $actor, $lock);
            if ($write) abort_unless($this->leads->abilities($lead, $actor)['follow'], 403, '无权为该线索填写跟进。');
            if (!$lead->customer_id && !$write) return [null, $lead, null];
            abort_unless($lead->customer_id, 422, '草稿和待核实线索不能填写正式跟进。');
            return [SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($lead->customer_id), $lead, null];
        }
        abort_unless($type === 'customer', 422, '跟进对象无效。');
        $this->leads->permission($actor, 'sales.customer');
        $customer = SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        abort_unless($this->access->relation($customer, $actor) !== null, 403, '无权查看该客户。');
        if ($write) {
            $this->leads->permission($actor, 'crm.lead.follow');
            $this->access->assert($customer, $actor, 'follow', '无权为该客户填写跟进。');
        }
        return [$customer, null, null];
    }

    public function listing(array $filters, object $actor)
    {
        return $this->listingQuery($filters, $actor)
            ->paginate((int) ($filters['per_page'] ?? 10))->through(fn ($followup) => $this->present($followup, $actor));
    }

    public function latestForCustomer(int $customerId, object $actor): array
    {
        $query = $this->listingQuery(['subject_type' => 'customer', 'subject_id' => $customerId], $actor);
        return [
            'data' => (clone $query)->limit(3)->get()
                ->map(fn ($followup) => $this->present($followup, $actor))->all(),
            'total' => $query->count(), 'per_page' => 3, 'current_page' => 1,
        ];
    }

    private function listingQuery(array $filters, object $actor): \Illuminate\Database\Eloquent\Builder
    {
        [$customer, $lead, $opportunity] = $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
        $query = CrmFollowup::query()->with('attachments');
        if ($opportunity) {
            $query->where('subject_type', 'opportunity')->where('subject_id', $opportunity->id);
        } elseif ($lead) {
            $query->where('lead_id', $lead->id);
        } else {
            $visibleLeads = CrmLead::query()->select('id');
            $this->leads->applyVisible($visibleLeads, $actor);
            $canSeeLeads = $this->access->can($actor, 'crm.lead.view');
            $visibleOpportunities = CrmOpportunity::query()->select('id');
            app(CrmOpportunityApplicationService::class)->applyVisible($visibleOpportunities, $actor);
            $canSeeOpportunities = $this->access->can($actor, 'crm.opportunity.view');
            $query->where('customer_id', $customer->id)->where(function ($q) use ($visibleLeads, $canSeeLeads, $visibleOpportunities, $canSeeOpportunities) {
                // 商机跟进没有 lead_id，不能把它当作客户直接跟进绕过指定商机协作范围。
                $q->where('subject_type', 'customer');
                if ($canSeeLeads) $q->orWhereIn('lead_id', $visibleLeads);
                if ($canSeeOpportunities) $q->orWhere(fn ($o) => $o->where('subject_type', 'opportunity')->whereIn('subject_id', $visibleOpportunities));
            });
        }
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function save(?int $id, array $data, object $actor): CrmFollowup
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            // 对象先上锁，所有跟进共用同一顺序，更新记录与重新汇总计划属于一个事务。
            $existing = $id ? CrmFollowup::query()->findOrFail($id) : null;
            $type = $existing?->subject_type ?? $data['subject_type'];
            $subjectId = $existing?->subject_id ?? (int) $data['subject_id'];
            $this->leads->permission($actor, $id ? 'crm.followup.edit' : ($type === 'opportunity' ? 'crm.opportunity.follow' : 'crm.lead.follow'));
            [$customer, $lead, $opportunity] = $this->subject($type, (int) $subjectId, $actor, true, true);
            abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或列入黑名单。');
            $target = $opportunity ?? $lead ?? $customer;
            if (array_intersect(array_keys($data), ['customer_pool', 'nine_step_stage', 'stage', 'win_probability', 'current_blocker', 'customer_promise'])) {
                $targetVersion = $type === 'customer' ? $target->crm_version : $target->version;
                abort_unless(isset($data['business_version']) && (int) $data['business_version'] === (int) $targetVersion,
                    409, '客户推进进度已变化，请刷新跟进弹窗后重试。');
            }
            $followup = $id ? CrmFollowup::query()->lockForUpdate()->findOrFail($id) : new CrmFollowup;
            if ($id) {
                abort_unless((int) $followup->operator_legacy_id === (int) $actor->legacy_id, 403, '只能修改本人填写的跟进记录。');
                if ((int) ($data['version'] ?? 0) !== (int) $followup->version) {
                    throw ValidationException::withMessages(['version' => '跟进记录已修改，请刷新后重试。']);
                }
                abort_if(trim((string) ($data['reason'] ?? '')) === '', 422, '修改跟进必须填写原因。');
            }
            $before = $id ? $followup->attributesToArray() + ['attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('followup', $followup->id)] : null;
            if (isset($data['schedule_mode'])) $data['followup_schedule_mode'] = $data['schedule_mode'];
            $followup->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            $mode = $data['followup_schedule_mode'] ?? ($followup->next_contact_at ? 'scheduled' : 'nurture');
            abort_unless(in_array($mode, ['scheduled', 'nurture', 'completed'], true), 422, '请选择有效的下次安排。');
            abort_unless(in_array($followup->method, CrmNineStepCatalog::actions(), true)
                || ($id && $followup->method === ($before['method'] ?? null)), 422, '请选择有效的跟进动作。');
            $followup->followup_schedule_mode = $mode;
            if ($mode !== 'scheduled') {
                $followup->next_contact_at = null;
                $followup->next_plan = null;
            }
            if ($lead && $this->leads->delegated($lead, $actor) && !$this->access->abilities($customer, $actor)['follow'] && $followup->contact_id) {
                abort_unless((int) $followup->contact_id === (int) $lead->contact_id, 422, '代班只能使用该线索已关联的联系人。');
            }
            if ($opportunity && app(CrmOpportunityApplicationService::class)->delegated($opportunity, $actor, true)
                && $this->access->relation($customer, $actor) === null && $followup->contact_id) {
                abort_unless((int) $followup->contact_id === (int) $opportunity->contact_id, 422, '只能使用该商机已关联的联系人。');
            }
            abort_if(!$followup->contacted_at || $followup->contacted_at->isFuture(), 422, '实际联系时间不能晚于当前时间。');
            abort_if($mode === 'scheduled' && !$followup->next_contact_at, 422, '已约下次联系时，请填写联系时间。');
            abort_if($followup->next_contact_at && $followup->next_contact_at->lte($followup->contacted_at), 422, '下次联系时间必须晚于实际联系时间。');
            abort_if($followup->next_contact_at && trim((string) $followup->next_plan) === '', 422, '请填写下次联系计划。');
            if ($followup->contact_id) {
                $contact = DB::table('erp_sales_customer_contacts')->where('id', $followup->contact_id)->where('customer_id', $customer->id)->where('status', 'enabled')->first();
                abort_unless($contact, 422, '请选择当前客户的有效联系人。');
                $followup->contact_name = $contact->contact_name;
            }
            abort_if(trim((string) $followup->contact_name) === '', 422, '请填写对接人。');
            if (!$id) {
                $followup->subject_type = $type;
                $followup->subject_id = $subjectId;
                $followup->customer_id = $customer->id;
                $followup->lead_id = $lead?->id;
                $followup->operator_legacy_id = $actor->legacy_id;
                $followup->operator_name = ($actor->nickname ?? null) ?: ($actor->username ?? null);
                $followup->department_legacy_id = $this->staff->departmentOf((int) $actor->legacy_id);
                $followup->version = 1;
            } else {
                $followup->version++;
            }
            $progress = $this->progressPayload($type, $target, $data);
            $stageField = $type === 'opportunity' ? 'stage' : ($type === 'customer' ? 'lifecycle_stage' : 'nine_step_stage');
            $snapshotBefore = $id ? ($followup->combat_snapshot ?? []) : [];
            $followup->combat_snapshot = [
                'subject_type' => $type, 'subject_id' => (int) $subjectId,
                'customer_id' => (int) $customer->id, 'customer_name' => $customer->customer_name,
                'number' => $lead?->lead_no ?? $opportunity?->opportunity_no,
                'project' => $lead?->demand_summary ?? $opportunity?->name,
                'pool_before' => $snapshotBefore['pool_before'] ?? $target->customer_pool,
                'pool_after' => $progress['customer_pool'],
                'stage_before' => $snapshotBefore['stage_before'] ?? $target->{$stageField},
                'stage_after' => $progress['nine_step_stage'], 'probability' => $progress['win_probability'],
                'action_type' => $followup->method, 'blocker' => $progress['current_blocker'],
                'customer_promise' => $progress['customer_promise'], 'expected_pay_date' => $progress['expected_pay_date'],
                'owner_legacy_id' => $target->owner_legacy_id,
                'department_legacy_id' => $type === 'customer' ? $target->owner_department_legacy_id : $target->department_legacy_id,
                'operator_legacy_id' => (int) $actor->legacy_id,
                'target_version' => (int) ($type === 'customer' ? $target->crm_version : $target->version),
                'followup_version' => (int) $followup->version,
                'followup_schedule_mode' => $mode,
                'contacted_at' => $followup->contacted_at->toDateTimeString(),
                'next_contact_at' => $followup->next_contact_at?->toDateTimeString(), 'next_plan' => $followup->next_plan,
            ];
            $followup->save();
            // 补录或更正旧联系只修复历史事实，不能用旧快照覆盖今天的客户推进。
            $latest = CrmFollowup::query()->where('subject_type', $type)->where('subject_id', $subjectId)
                ->orderByDesc('contacted_at')->orderByDesc('id')->value('id');
            if ((int) $latest === (int) $followup->id) {
                $currentProgress = array_intersect_key($progress, array_flip(array_keys($data)));
                unset($currentProgress['expected_pay_date']);
                if (array_intersect(array_keys($data), ['nine_step_stage', 'stage', 'lifecycle_stage'])) $currentProgress['nine_step_stage'] = $progress['nine_step_stage'];
                if ($currentProgress) app(CrmProgressApplicationService::class)->applyLocked($type, $target, $currentProgress, $actor);
            }
            if (array_key_exists('attachment_ids', $data)) app(CrmAttachmentApplicationService::class)->bind($data['attachment_ids'] ?? [], 'followup', $followup->id, $actor);
            $after = $followup->fresh()->attributesToArray() + ['attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('followup', $followup->id)];
            $summary = $id ? '修改跟进记录 #'.$followup->id : '记录跟进 #'.$followup->id;
            if ($opportunity) {
                app(CrmOpportunityApplicationService::class)->log($opportunity, $id ? 'followup_edit' : 'followup_create', $summary, $before, $after, $data['reason'] ?? null, $actor);
            } elseif ($lead) {
                $this->leads->log($lead, $id ? 'followup_edit' : 'followup_create', $summary, $before, $after, $data['reason'] ?? null, $actor);
            } else {
                $this->logs->write($customer->id, $id ? 'followup_edit' : 'followup_create', $summary, $before, $after, $data['reason'] ?? null, $actor);
            }
            $this->refreshContactFacts($customer->id, $lead?->id, true, $opportunity?->id);
            return $followup->fresh(['attachments']);
        }, 3);
    }

    private function progressPayload(string $type, object $target, array $data): array
    {
        $stageField = $type === 'opportunity' ? 'stage' : ($type === 'customer' ? 'lifecycle_stage' : 'nine_step_stage');
        $stage = CrmNineStepCatalog::normalizeStage($type, $data['nine_step_stage'] ?? $data['stage'] ?? $data['lifecycle_stage'] ?? $target->{$stageField});
        abort_unless($stage === '' || in_array($stage, array_column(CrmNineStepCatalog::stages($type), 'value'), true), 422, '九步阶段不适用于当前跟进对象。');
        $probability = array_key_exists('win_probability', $data) ? $data['win_probability'] : $target->win_probability;
        if (!array_key_exists('win_probability', $data) && $stage !== ''
            && $stage !== CrmNineStepCatalog::normalizeStage($type, $target->{$stageField})) {
            $probability = CrmNineStepCatalog::probability($stage);
        }
        abort_unless($probability === null || $probability === '' || (preg_match('/^(\d+(?:\.\d+)?)(?:%.*)?$/u', (string) $probability, $probabilityParts)
            && (float) $probabilityParts[1] <= 100), 422, '成交概率应为0到100。');
        return [
            'customer_pool' => app(CrmCustomerPoolService::class)->assertSelectable(array_key_exists('customer_pool', $data) ? $data['customer_pool'] : $target->customer_pool, $type, $target->customer_pool),
            'nine_step_stage' => $stage, 'win_probability' => $probability !== null ? (string) $probability : null,
            'current_blocker' => array_key_exists('current_blocker', $data) ? $data['current_blocker'] : $target->current_blocker,
            'customer_promise' => array_key_exists('customer_promise', $data) ? $data['customer_promise'] : $target->customer_promise,
            'expected_pay_date' => array_key_exists('expected_pay_date', $data) ? $data['expected_pay_date'] : ($type === 'opportunity' ? $target->expected_close_date?->toDateString() : null),
        ];
    }

    public function present(CrmFollowup $followup, object $actor): array
    {
        $row = $followup->toArray();
        unset($row['legacy_payload']);
        $row['attachments'] = $followup->attachments->map(fn ($file) => app(CrmAttachmentApplicationService::class)->present($file))->all();
        $row['delegated'] = false;
        $row['delegated_owner'] = null;
        $canFollow = false;
        if ($followup->subject_type === 'opportunity' && $this->access->can($actor, 'crm.opportunity.view')) {
            $query = CrmOpportunity::query()->whereKey($followup->subject_id);
            $opportunities = app(CrmOpportunityApplicationService::class);
            $opportunities->applyVisible($query, $actor);
            $opportunity = $query->first();
            $canFollow = $opportunity && $opportunities->abilities($opportunity, $actor)['follow'];
            $row['delegated'] = $opportunity && $opportunities->delegated($opportunity, $actor);
            if ($row['delegated']) $row['delegated_owner'] = ['id' => (int) $opportunity->owner_legacy_id, 'name' => $opportunity->owner_name];
        } elseif ($followup->lead_id && $this->access->can($actor, 'crm.lead.view')) {
            $query = CrmLead::query()->whereKey($followup->lead_id);
            $this->leads->applyVisible($query, $actor);
            $lead = $query->first();
            $canFollow = $lead && $this->leads->abilities($lead, $actor)['follow'];
            $row['delegated'] = $lead && $this->leads->delegated($lead, $actor);
            if ($row['delegated']) $row['delegated_owner'] = ['id' => (int) $lead->owner_legacy_id, 'name' => $lead->owner_name];
        } elseif ($followup->subject_type === 'customer') {
            $customer = SalesCustomer::query()->find($followup->customer_id);
            $canFollow = $customer && $this->access->abilities($customer, $actor)['follow'];
        }
        $row['can_edit'] = (int) $followup->operator_legacy_id === (int) $actor->legacy_id && $this->access->can($actor, 'crm.followup.edit')
            && $this->access->can($actor, $followup->subject_type === 'opportunity' ? 'crm.opportunity.follow' : 'crm.lead.follow') && $canFollow;
        return $row;
    }

    /** 线索和商机各自保留待办；完成一条需求的联系，不能消掉同客户其他需求的计划。 */
    public function refreshContactFacts(int $customerId, ?int $leadId = null, bool $recomputeLead = true, ?int $opportunityId = null): void
    {
        if ($opportunityId) {
            $opportunity = CrmOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
            $contacts = CrmFollowup::query()->where('subject_type', 'opportunity')->where('subject_id', $opportunityId);
            $latest = (clone $contacts)->orderByDesc('contacted_at')->orderByDesc('id')->first();
            $scheduled = $latest && ($latest->followup_schedule_mode === 'scheduled' || $latest->followup_schedule_mode === null) && $latest->next_contact_at;
            if ($latest) $opportunity->update([
                'first_contact_at' => (clone $contacts)->min('contacted_at'), 'last_contact_at' => (clone $contacts)->max('contacted_at'),
                'next_contact_at' => $scheduled ? $latest->next_contact_at : null, 'next_plan' => $scheduled ? $latest->next_plan : null,
                'latest_followup_summary' => mb_substr((string) $latest->content, 0, 2000), 'version' => $opportunity->version + 1,
            ]);
        }
        if ($leadId && $recomputeLead) {
            $lead = CrmLead::query()->lockForUpdate()->findOrFail($leadId);
            $contacts = CrmFollowup::query()->where('lead_id', $leadId);
            $latest = (clone $contacts)->orderByDesc('contacted_at')->orderByDesc('id')->first();
            $scheduled = $latest && ($latest->followup_schedule_mode === 'scheduled' || $latest->followup_schedule_mode === null) && $latest->next_contact_at;
            if ($latest) $lead->update([
                'first_contact_at' => (clone $contacts)->min('contacted_at'), 'last_contact_at' => (clone $contacts)->max('contacted_at'),
                'next_contact_at' => $scheduled ? $latest->next_contact_at : null, 'next_plan' => $scheduled ? $latest->next_plan : null,
                'latest_followup_summary' => mb_substr((string) $latest->content, 0, 2000), 'confirmed_at' => $lead->confirmed_at ?? now(), 'version' => $lead->version + 1,
            ]);
        }
        $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
        $contacts = CrmFollowup::query()->where('customer_id', $customerId);
        $direct = (clone $contacts)->where('subject_type', 'customer')->orderByDesc('contacted_at')->orderByDesc('id')->first();
        // 客户排期只表达直接客情维系；各需求的计划由线索/商机保留，不能重新制造客户假待办。
        $scheduled = $direct && ($direct->followup_schedule_mode === 'scheduled' || $direct->followup_schedule_mode === null) && $direct->next_contact_at;
        $customerFacts = ['first_contact_at' => (clone $contacts)->min('contacted_at'), 'last_contact_at' => (clone $contacts)->max('contacted_at'),
            'next_contact_at' => $scheduled ? $direct->next_contact_at : null, 'next_plan' => $scheduled ? $direct->next_plan : null];
        if ($direct) $customerFacts['latest_followup_summary'] = mb_substr((string) $direct->content, 0, 2000);
        $customer->update($customerFacts);
    }
}
