<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmLead;
use App\Models\Erp\SalesCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 跟进按实际联系时间形成事实；每个对象只保留最新联系对应的下一次计划。 */
final class CrmFollowupApplicationService
{
    public const EDITABLE = ['contact_id', 'contact_name', 'method', 'contacted_at', 'content', 'result', 'next_contact_at', 'next_plan'];

    public function __construct(
        private readonly CrmLeadApplicationService $leads,
        private readonly CrmCustomerAccessService $access,
        private readonly CrmCustomerLogService $logs,
        private readonly CrmSalesStaffService $staff,
    ) {}

    public function subject(string $type, int $id, object $actor, bool $write = false, bool $lock = false): array
    {
        if ($type === 'lead') {
            $this->leads->permission($actor, 'crm.lead.view');
            $lead = $this->leads->visible($id, $actor, $lock);
            if ($write) abort_unless($this->leads->abilities($lead, $actor)['follow'], 403, '无权为该线索填写跟进。');
            if (!$lead->customer_id && !$write) return [null, $lead];
            abort_unless($lead->customer_id, 422, '草稿和待核实线索不能填写正式跟进。');
            return [SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($lead->customer_id), $lead];
        }
        abort_unless($type === 'customer', 422, '当前支持客户和线索跟进。');
        $this->leads->permission($actor, 'sales.customer');
        $customer = SalesCustomer::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        abort_unless($this->access->relation($customer, $actor) !== null, 403, '无权查看该客户。');
        if ($write) {
            $this->leads->permission($actor, 'crm.lead.follow');
            $this->access->assert($customer, $actor, 'follow', '无权为该客户填写跟进。');
        }
        return [$customer, null];
    }

    public function listing(array $filters, object $actor)
    {
        [$customer, $lead] = $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
        $query = CrmFollowup::query()->with('attachments');
        if ($lead) {
            $query->where('lead_id', $lead->id);
        } else {
            $visibleLeads = CrmLead::query()->select('id');
            $this->leads->applyVisible($visibleLeads, $actor);
            $canSeeLeads = $this->access->can($actor, 'crm.lead.view');
            $query->where('customer_id', $customer->id)->where(function ($q) use ($visibleLeads, $canSeeLeads) {
                $q->whereNull('lead_id');
                if ($canSeeLeads) $q->orWhereIn('lead_id', $visibleLeads);
            });
        }
        return $query->orderByDesc('contacted_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10))
            ->through(fn ($followup) => $this->present($followup, $actor));
    }

    public function save(?int $id, array $data, object $actor): CrmFollowup
    {
        $this->leads->permission($actor, $id ? 'crm.followup.edit' : 'crm.lead.follow');
        return DB::transaction(function () use ($id, $data, $actor) {
            // 对象先上锁，所有跟进共用同一顺序，更新记录与重新汇总计划属于一个事务。
            $existing = $id ? CrmFollowup::query()->findOrFail($id) : null;
            $type = $existing?->subject_type ?? $data['subject_type'];
            $subjectId = $existing?->subject_id ?? (int) $data['subject_id'];
            [$customer, $lead] = $this->subject($type, (int) $subjectId, $actor, true, true);
            abort_if(in_array($customer->status, ['disabled', 'blacklisted'], true), 422, '该客户已停用或列入黑名单。');
            $followup = $id ? CrmFollowup::query()->lockForUpdate()->findOrFail($id) : new CrmFollowup;
            if ($id) {
                abort_unless((int) $followup->operator_legacy_id === (int) $actor->legacy_id, 403, '只能修改本人填写的跟进记录。');
                if ((int) ($data['version'] ?? 0) !== (int) $followup->version) {
                    throw ValidationException::withMessages(['version' => '跟进记录已修改，请刷新后重试。']);
                }
                abort_if(trim((string) ($data['reason'] ?? '')) === '', 422, '修改跟进必须填写原因。');
            }
            $before = $id ? $followup->attributesToArray() + ['attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('followup', $followup->id)] : null;
            $followup->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            abort_if(!$followup->contacted_at || $followup->contacted_at->isFuture(), 422, '实际联系时间不能晚于当前时间。');
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
            $followup->save();
            if (array_key_exists('attachment_ids', $data)) app(CrmAttachmentApplicationService::class)->bind($data['attachment_ids'] ?? [], 'followup', $followup->id, $actor);
            $after = $followup->fresh()->attributesToArray() + ['attachment_ids' => app(CrmAttachmentApplicationService::class)->ids('followup', $followup->id)];
            $summary = $id ? '修改跟进记录 #'.$followup->id : '记录客户跟进 #'.$followup->id;
            if ($lead) {
                $this->leads->log($lead, $id ? 'followup_edit' : 'followup_create', $summary, $before, $after, $data['reason'] ?? null, $actor);
            } else {
                $this->logs->write($customer->id, $id ? 'followup_edit' : 'followup_create', $summary, $before, $after, $data['reason'] ?? null, $actor);
            }
            $this->refreshContactFacts($customer->id, $lead?->id);
            return $followup->fresh(['attachments']);
        }, 3);
    }

    public function present(CrmFollowup $followup, object $actor): array
    {
        $row = $followup->toArray();
        unset($row['legacy_payload']);
        $row['attachments'] = $followup->attachments->map(fn ($file) => app(CrmAttachmentApplicationService::class)->present($file))->all();
        $canFollow = false;
        if ($followup->lead_id && $this->access->can($actor, 'crm.lead.view')) {
            $query = CrmLead::query()->whereKey($followup->lead_id);
            $this->leads->applyVisible($query, $actor);
            $lead = $query->first();
            $canFollow = $lead && $this->leads->abilities($lead, $actor)['follow'];
        } elseif (!$followup->lead_id) {
            $customer = SalesCustomer::query()->find($followup->customer_id);
            $canFollow = $customer && $this->access->abilities($customer, $actor)['follow'];
        }
        $row['can_edit'] = (int) $followup->operator_legacy_id === (int) $actor->legacy_id && $this->access->can($actor, 'crm.followup.edit')
            && $this->access->can($actor, 'crm.lead.follow') && $canFollow;
        return $row;
    }

    /** 不同线索各自保留待办；完成某条线索联系，不能消掉同客户另一条需求的计划。 */
    public function refreshContactFacts(int $customerId, ?int $leadId = null, bool $recomputeLead = true): void
    {
        if ($leadId && $recomputeLead) {
            $lead = CrmLead::query()->findOrFail($leadId);
            $contacts = CrmFollowup::query()->where('lead_id', $leadId);
            $latest = (clone $contacts)->orderByDesc('contacted_at')->orderByDesc('id')->first();
            if ($latest) $lead->update([
                'first_contact_at' => (clone $contacts)->min('contacted_at'), 'last_contact_at' => (clone $contacts)->max('contacted_at'),
                'next_contact_at' => $latest->next_contact_at, 'next_plan' => $latest->next_plan, 'version' => $lead->version + 1,
            ]);
        }
        $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
        $contacts = CrmFollowup::query()->where('customer_id', $customerId);
        $direct = (clone $contacts)->whereNull('lead_id')->orderByDesc('contacted_at')->orderByDesc('id')->first();
        $leadNext = CrmLead::query()->where('customer_id', $customerId)->where('status', 'active')->min('next_contact_at');
        $next = collect([$direct?->next_contact_at?->toDateTimeString(), $leadNext])->filter()->sort()->first();
        $customer->update(['first_contact_at' => (clone $contacts)->min('contacted_at'), 'last_contact_at' => (clone $contacts)->max('contacted_at'), 'next_contact_at' => $next]);
    }
}
