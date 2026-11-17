<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\{CrmAttachment, CrmDrawingRequest, CrmDrawingRequestRevision, CrmFollowup, CrmLead, CrmOpportunity, CrmOpportunityFile, SalesCustomer, TechnicalDrawingRevision, TechnicalDrawingUsage};
use App\Services\Erp\{TechnicalDrawingApplicationService, TechnicalDrawingSourceService};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/** 销售提交客户要求，承接技术交图；确认事实属于本需求，订单只消费明确选中的确认版本。 */
final class CrmDrawingRequestService
{
    public function __construct(private readonly CrmCustomerAccessService $customers) {}

    public function isTechnician(object $actor): bool
    {
        return $this->customers->can($actor, 'production.tech.manage');
    }

    public function metadata(object $actor, array $filters = []): array
    {
        $create = false;
        if (!empty($filters['subject_type']) && !empty($filters['subject_id'])) {
            $subject = $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
            $create = $this->canManageSubject($subject, $actor);
        }
        return ['current_user' => ['id' => (int) $actor->legacy_id, 'name' => $this->name($actor)],
            'abilities' => ['create' => $create, 'deliver' => $this->isTechnician($actor)],
            'statuses' => [['value' => 'todo', 'label' => '待我处理'], ['value' => 'pending', 'label' => '待交图'], ['value' => 'changes_requested', 'label' => '待技术修改'],
                ['value' => 'delivered', 'label' => '待客户确认'], ['value' => 'confirmed', 'label' => '客户已确认']]];
    }

    public function technicians(array $filters, object $actor): array
    {
        abort_unless($this->isTechnician($actor) || $this->customers->can($actor, 'crm.lead.view') || $this->customers->can($actor, 'crm.opportunity.view'), 403, '没有查看技术人员的权限。');
        $query = DB::table('erp_legacy_admin_users as u')->whereNull('u.deleted_at')->whereIn(DB::raw('LOWER(TRIM(u.status))'), ['normal', 'active'])
            ->where(function ($eligible) {
                $eligible->where('u.username', 'admin')->orWhere('u.auth_group_names', 'like', '%"Admin group"%')
                    ->orWhereExists(fn ($q) => $q->from('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                        ->whereColumn('ur.user_legacy_id', 'u.legacy_id')->where('r.enabled', true)->where('r.code', 'admin'))
                    ->orWhereExists(fn ($q) => $q->from('erp_rbac_user_roles as ur')->join('erp_rbac_roles as r', 'r.id', '=', 'ur.role_id')
                        ->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')
                        ->whereColumn('ur.user_legacy_id', 'u.legacy_id')->where('r.enabled', true)->where('p.enabled', true)->where('p.code', 'production.tech.manage'));
            });
        if (!empty($filters['ids'])) $query->whereIn('u.legacy_id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('u.nickname', 'like', '%'.$keyword.'%')->orWhere('u.username', 'like', '%'.$keyword.'%'));
        return $query->orderBy('u.legacy_id')->paginate($filters['per_page'] ?? 10, ['u.legacy_id', 'u.nickname', 'u.username'])
            ->through(function ($row) { $department = DB::table('erp_department_users as du')->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')->where('du.user_legacy_id', $row->legacy_id)->orderBy('du.id')->value('d.name');
                return ['id' => (int) $row->legacy_id, 'name' => $row->nickname ?: $row->username, 'department_name' => $department]; })->toArray();
    }

    public function applyVisible(Builder $query, object $actor, bool $includeDeleted = false): void
    {
        if (!$includeDeleted) $query->where('status', '!=', 'deleted');
        $query->where(function ($visible) use ($actor) {
            $visible->whereRaw('1 = 0');
            if ($this->isTechnician($actor)) $visible->orWhere('technician_legacy_id', $actor->legacy_id);
            if ($this->customers->can($actor, 'crm.lead.view')) {
                $leads = CrmLead::query()->select('id');
                app(CrmLeadApplicationService::class)->applyVisible($leads, $actor);
                $visible->orWhere(fn ($q) => $q->whereNull('opportunity_id')->whereIn('lead_id', $leads));
            }
            if ($this->customers->can($actor, 'crm.opportunity.view')) {
                $opportunities = CrmOpportunity::query()->select('id');
                app(CrmOpportunityApplicationService::class)->applyVisible($opportunities, $actor);
                $visible->orWhereIn('opportunity_id', $opportunities);
            }
        });
    }

    public function visible(int $id, object $actor, bool $lock = false, bool $includeDeleted = false): CrmDrawingRequest
    {
        $query = CrmDrawingRequest::query();
        $this->applyVisible($query, $actor, $includeDeleted);
        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function paginate(array $filters, object $actor): array
    {
        $query = CrmDrawingRequest::query();
        $mine = ($filters['tab'] ?? '') === 'mine';
        if ($mine) {
            abort_unless($this->isTechnician($actor), 403, '没有技术交图权限。');
            $query->where('technician_legacy_id', $actor->legacy_id)->where('status', '!=', 'deleted');
        } else $this->applyVisible($query, $actor);
        if (!empty($filters['subject_type'])) {
            // 承接需求已授予技术读取权，展开同单只按本人和准确来源收窄，不追加销售的来源查看权限。
            if (!$mine) $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
            $query->where($filters['subject_type'] === 'lead' ? 'lead_id' : 'opportunity_id', $filters['subject_id']);
            if ($mine && $filters['subject_type'] === 'lead') $query->whereNull('opportunity_id');
        }
        if (($filters['status'] ?? null) === 'todo') $query->whereIn('status', ['pending', 'changes_requested']);
        elseif (!empty($filters['status'])) $query->where('status', $filters['status']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('request_no', 'like', '%'.$keyword.'%')->orWhere('title', 'like', '%'.$keyword.'%'));
        if (!empty($filters['grouped'])) {
            abort_unless($mine, 422, '请从待我绘制查看分组需求。');
            return $this->groupedRequests($query, $filters);
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $contexts = $this->sourceContexts($page->getCollection(), $actor);
        $reviews = $this->reviewStates($page->getCollection(), $actor);
        return $page->through(fn ($row) => $this->present($row, $actor, false, $contexts['drawing_request:'.$row->id], $reviews[$row->id]))->toArray();
    }

    private function groupedRequests(Builder $query, array $filters): array
    {
        // 只分页来源组；同一只读快照内统计并读取标题，转换商机时不会拼出空组或混合来源。
        return DB::transaction(function () use ($query, $filters) {
            $page = $query->toBase()->selectRaw("IF(opportunity_id IS NULL, 'lead', 'opportunity') AS subject_type, COALESCE(opportunity_id, lead_id) AS subject_id, COUNT(*) AS request_count, MAX(id) AS latest_request_id")
                ->groupByRaw("IF(opportunity_id IS NULL, 'lead', 'opportunity'), COALESCE(opportunity_id, lead_id)")
                ->orderByDesc('latest_request_id')->paginate($filters['per_page'] ?? 10);
            $groups = $page->getCollection();
            $opportunities = CrmOpportunity::query()->whereIn('id', $groups->where('subject_type', 'opportunity')->pluck('subject_id'))
                ->get(['id', 'opportunity_no', 'name', 'customer_id', 'customer_name'])->keyBy('id');
            $leads = CrmLead::query()->whereIn('id', $groups->where('subject_type', 'lead')->pluck('subject_id'))
                ->get(['id', 'lead_no', 'customer_id', 'customer_name'])->keyBy('id');
            $customers = SalesCustomer::query()->whereIn('id', $opportunities->pluck('customer_id')->merge($leads->pluck('customer_id'))->filter()->unique())->pluck('customer_name', 'id');
            return $page->through(function ($group) use ($opportunities, $leads, $customers) {
                $opportunity = $group->subject_type === 'opportunity';
                $subject = ($opportunity ? $opportunities : $leads)->get($group->subject_id);
                $customerName = $customers->get($subject?->customer_id) ?: $subject?->customer_name;
                return ['subject_type' => $group->subject_type, 'subject_id' => (int) $group->subject_id, 'subject_label' => $opportunity ? '商机' : '线索',
                    'subject_no' => $opportunity ? $subject?->opportunity_no : $subject?->lead_no, 'subject_name' => $opportunity ? $subject?->name : $customerName,
                    'customer_name' => $customerName, 'request_count' => (int) $group->request_count, 'latest_request_id' => (int) $group->latest_request_id];
            })->toArray();
        });
    }

    public function save(?int $id, array $data, object $actor): CrmDrawingRequest
    {
        $data = Validator::make($data, ['subject_type' => 'required|in:lead,opportunity', 'subject_id' => 'required|integer|min:1',
            'title' => 'required|string|max:200', 'requirement' => 'required|string|max:10000', 'technician_legacy_id' => 'required|integer|min:1',
            'reference_attachment_ids' => 'nullable|array|max:100', 'reference_attachment_ids.*' => 'required|integer|min:1|distinct',
            'version' => 'nullable|integer|min:1', 'request_key' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/'])->validate();
        $data['title'] = trim($data['title']); $data['requirement'] = trim($data['requirement']);
        abort_if($data['title'] === '' || $data['requirement'] === '', 422, '请填写需求名称和具体要求。');
        if (!$id || isset($data['reference_attachment_ids'])) {
            $data['reference_attachment_ids'] = array_map('intval', $data['reference_attachment_ids'] ?? []);
        }
        $hash = hash('sha256', json_encode(array_diff_key($data, ['version' => true]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!$id && ($existing = CrmDrawingRequest::query()->where('request_key', $data['request_key'])->first())) {
            abort_unless((int) $existing->created_by_legacy_id === (int) $actor->legacy_id && hash_equals($existing->request_hash, $hash), 422, '提交标识已用于其他需求，请重新打开窗口。');
            return $this->visible((int) $existing->id, $actor);
        }
        try {
            return DB::transaction(function () use ($id, $data, $actor, $hash) {
                $subject = $this->subject($data['subject_type'], (int) $data['subject_id'], $actor, true);
                abort_unless($this->canManageSubject($subject, $actor), 403, '无权提交或修改该业务的绘图需求。');
                if (!$id && ($existing = CrmDrawingRequest::query()->where('request_key', $data['request_key'])->lockForUpdate()->first())) {
                    abort_unless((int) $existing->created_by_legacy_id === (int) $actor->legacy_id && hash_equals($existing->request_hash, $hash), 422, '提交标识已用于其他需求，请重新打开窗口。');
                    return $this->visible((int) $existing->id, $actor);
                }
                $request = $id ? $this->visible($id, $actor, true) : new CrmDrawingRequest();
                $before = $id ? $request->only(['title', 'requirement', 'technician_legacy_id', 'reference_attachment_ids', 'version']) : null;
                if ($id) {
                    $this->version($request, $data);
                    abort_unless($request->status === 'pending' && !$request->revisions()->exists(), 422, '已有交图的需求请保留原记录，需求变化请另提新需求。');
                    abort_unless($data['subject_type'] === ($request->opportunity_id ? 'opportunity' : 'lead')
                        && (int) ($request->opportunity_id ?: $request->lead_id) === (int) $subject->id, 422, '需求所属业务已变化，请刷新后从当前商机或线索重新维护。');
                }
                $technician = DB::table('erp_legacy_admin_users')->where('legacy_id', $data['technician_legacy_id'])->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->lockForUpdate()->first();
                abort_unless($technician && $this->isTechnician($technician), 422, '承接人员须为在职且具有技术资料管理权限的员工。');
                $oldRefs = $request->reference_attachment_ids ?? [];
                // 仅调整指派时沿用原资料，明确提交空清单才移除全部引用。
                $references = $data['reference_attachment_ids'] ?? $oldRefs;
                $newIds = [];
                foreach ($references as $attachmentId) {
                    $attachment = $this->assertReference($attachmentId, $data['subject_type'], (int) $subject->id, $actor, $id, $oldRefs);
                    if (!$attachment->subject_type) $newIds[] = (int) $attachment->id;
                }
                $values = ['title' => $data['title'], 'requirement' => $data['requirement'], 'technician_legacy_id' => $technician->legacy_id,
                    'technician_name' => $this->name($technician), 'reference_attachment_ids' => $references,
                    'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)];
                if (!$id) $values += ['request_no' => 'DR-'.strtoupper((string) Str::ulid()), 'request_key' => $data['request_key'], 'request_hash' => $hash,
                    'customer_id' => $subject->customer_id, 'lead_id' => $data['subject_type'] === 'lead' ? $subject->id : ($subject->source_lead_id ?: null),
                    'opportunity_id' => $data['subject_type'] === 'opportunity' ? $subject->id : null, 'status' => 'pending', 'version' => 1,
                    'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->name($actor)];
                else $values['version'] = $request->version + 1;
                $request->fill($values)->save();
                if ($subject instanceof CrmOpportunity) {
                    $version = (int) $subject->version;
                    foreach ($newIds as $attachmentId) app(CrmOpportunityApplicationService::class)->saveFile((int) $subject->id,
                        ['version' => $version++, 'attachment_id' => $attachmentId, 'category' => 'requirement', 'status' => 'pending', 'note' => '图纸需求 '.$request->request_no.' 的客户参考资料'], $actor);
                } elseif ($newIds) {
                    $files = app(CrmAttachmentApplicationService::class);
                    $files->bind(array_merge($files->ids('lead', (int) $subject->id), $newIds), 'lead', (int) $subject->id, $actor);
                    $subject->version++;
                    $subject->save();
                    app(CrmLeadApplicationService::class)->log($subject, 'drawing_reference_add', '登记客户参考资料', null,
                        ['request_id' => $request->id, 'attachment_ids' => $newIds], null, $actor);
                }
                // 需求只维护引用；移除选择不能停用商机、线索或其他需求仍在使用的原资料。
                $this->log($request, $id ? 'drawing_request_edit' : 'drawing_request_create', $id ? '修改绘图需求' : '提交绘图需求', $actor,
                    ['request_id' => $request->id, 'before' => $before, 'after' => $request->only(['title', 'requirement', 'technician_legacy_id', 'reference_attachment_ids', 'version'])]);
                return $request;
            }, 3);
        } catch (QueryException $error) {
            if ($id || ($error->errorInfo[1] ?? null) !== 1062) throw $error;
            $existing = CrmDrawingRequest::query()->where('request_key', $data['request_key'])->first();
            abort_unless($existing && (int) $existing->created_by_legacy_id === (int) $actor->legacy_id && hash_equals($existing->request_hash, $hash), 422, '提交标识已用于其他需求。');
            return $this->visible((int) $existing->id, $actor);
        }
    }

    public function remove(int $id, array $data, object $actor): CrmDrawingRequest
    {
        $data = Validator::make($data, ['version' => 'required|integer|min:1'])->validate();
        $origin = $this->visible($id, $actor, includeDeleted: true);
        $type = $origin->opportunity_id ? 'opportunity' : 'lead';
        $subjectId = (int) ($origin->opportunity_id ?: $origin->lead_id);
        return DB::transaction(function () use ($id, $data, $actor, $type, $subjectId) {
            // 与编辑、线索转商机同序锁来源再锁需求；保留记录及提交标识，旧建单请求不能复活已删除需求。
            $subject = $this->subject($type, $subjectId, $actor, true);
            $request = $this->visible($id, $actor, true, true);
            abort_unless($type === ($request->opportunity_id ? 'opportunity' : 'lead')
                && $subjectId === (int) ($request->opportunity_id ?: $request->lead_id), 409, '需求所属业务已变化，请刷新后重试。');
            abort_unless($this->canManageSubject($subject, $actor, true), 403, '无权删除该业务的绘图需求。');
            if ($request->status === 'deleted') return $request;
            $this->version($request, $data);
            abort_unless($request->status === 'pending' && !$request->revisions()->lockForUpdate()->exists(), 422, '只能删除从未交图的待交图需求，已有交图记录须保留。');
            $before = $request->only(['request_no', 'title', 'requirement', 'technician_legacy_id', 'reference_attachment_ids', 'status', 'version']);
            $request->update(['status' => 'deleted', 'version' => $request->version + 1,
                'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
            // 只删除需求，客户资料可能被商机和其他需求共享，不能停用或删除原文件。
            $this->log($request, 'drawing_request_delete', '删除绘图需求', $actor,
                ['request_id' => $request->id, 'before' => $before, 'after' => $request->only(array_keys($before))]);
            return $request;
        }, 3);
    }

    public function abilities(CrmDrawingRequest $request, object $actor, ?bool $hasRevisions = null): array
    {
        $manage = false;
        try { $manage = $this->canManageSubject($this->requestSubject($request, $actor), $actor); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {}
        return ['create' => $manage, 'edit' => $manage && $request->status === 'pending',
            'delete' => $manage && $request->status === 'pending' && !($hasRevisions ?? $request->revisions()->exists()),
            'deliver' => $this->isTechnician($actor) && (int) $request->technician_legacy_id === (int) $actor->legacy_id,
            'confirm' => $manage];
    }

    public function present(CrmDrawingRequest $request, object $actor, bool $detail = true, ?array $sourceContext = null, ?array $review = null): array
    {
        $sourceContext ??= $this->sourceContexts([$request], $actor)['drawing_request:'.$request->id];
        $review ??= $this->reviewStates([$request], $actor)[$request->id];
        $row = $request->attributesToArray(); unset($row['request_hash'], $row['request_key']);
        $row += ['customer_name' => SalesCustomer::query()->whereKey($request->customer_id)->value('customer_name'),
            'subject_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'subject_id' => $request->opportunity_id ?: $request->lead_id,
            'subject_label' => $request->opportunity_id ? '商机' : '线索', 'abilities' => $review['abilities'],
            'status_label' => ['pending' => '待交图', 'changes_requested' => '待技术修改', 'delivered' => '待客户确认', 'confirmed' => '客户已确认'][$request->status] ?? $request->status,
            'source_context' => $sourceContext];
        $confirmed = $review['rows']->whereNotNull('confirmed_at')->sortByDesc(fn ($revision) => $revision->confirmed_at->format('Y-m-d H:i:s').str_pad((string) $revision->id, 20, '0', STR_PAD_LEFT))->first();
        $row['confirmed_revision'] = $confirmed ? $this->revisionRow($confirmed, $request, $actor, $sourceContext, $review) : null;
        $row['pending_modifications'] = $review['rows']->filter(fn ($revision) => isset($review['returns'][$revision->id]) && !isset($review['resolutions'][$revision->id]))
            ->map(fn ($revision) => ['id' => $revision->id, 'request_id' => $request->id, 'revision_id' => $revision->revision_id,
                'drawing_id' => $revision->revision->drawing_id, 'drawing_no' => $revision->revision->drawing->drawing_no,
                'drawing_name' => $revision->revision->drawing->drawing_name, 'version_no' => $revision->revision->version_no] + $this->reviewRow($revision, $review))->values()->all();
        if ($detail) {
            $row['reference_attachments'] = $this->referenceRows(CrmAttachment::query()->whereIn('id', $request->reference_attachment_ids ?? [])->orderBy('id')->get(), $request);
            $row['revisions'] = $review['rows']->map(fn ($revision) => $this->revisionRow($revision, $request, $actor, $sourceContext, $review))->values()->all();
        }
        return $row;
    }

    public function deliver(int $id, array $data, object $actor, UploadedFile|array|null $file): array
    {
        $data['source'] = ['type' => 'drawing_request', 'id' => $id, 'version' => $data['version'] ?? null];
        unset($data['version']);
        if (($data['action'] ?? null) !== 'reuse') {
            abort_if(!empty($data['designer_legacy_id']) && (int) $data['designer_legacy_id'] !== (int) $actor->legacy_id, 403, '绘制人只能是当前交图的技术人员本人。');
            $data['designer_legacy_id'] = (int) $actor->legacy_id;
        }
        return app(TechnicalDrawingApplicationService::class)->register($data, $actor, $file);
    }

    public function technicalSource(int $id, object $actor, bool $write, bool $lock, array $source): array
    {
        $request = $this->visible($id, $actor, $lock);
        if ($write) {
            abort_unless($this->abilities($request, $actor)['deliver'], 403, '只有本需求的承接技术人员可以交图。');
            $this->version($request, $source);
        }
        return ['type' => 'drawing_request', 'id' => $request->id, 'file_id' => null, 'position_uid' => '',
            'no' => $request->request_no, 'name' => $request->title, 'version' => $request->version, 'file' => null];
    }

    public function bindDelivery(TechnicalDrawingUsage $usage, TechnicalDrawingRevision $revision, object $actor, array $data): void
    {
        $request = $this->visible((int) $usage->source_id, $actor, true);
        $review = $this->reviewStates([$request], $actor)[$request->id];
        abort_unless($review['abilities']['deliver'], 403, '只有本需求的承接技术人员可以交图。');
        abort_unless(in_array($data['action'] ?? null, ['new', 'modify', 'reuse'], true), 422, '请从正式交图入口提交图纸。');
        $pendingReturnId = $review['pending_by_drawing'][$revision->drawing_id] ?? null;
        $returnId = (int) ($data['return_request_revision_id'] ?? 0);
        if ($returnId) {
            abort_unless($data['action'] === 'modify' && $pendingReturnId === $returnId && $revision->action === 'modify'
                && (int) $revision->previous_revision_id === (int) ($data['expected_revision_id'] ?? 0), 409, '修改意见或图纸版本已变化，请刷新需求后按原图号重新提交。');
        } else abort_if($pendingReturnId, 422, '该图号有待处理的修改意见，请使用“按意见修改”提交新版，不能沿用或跳过退回意见。');
        $row = CrmDrawingRequestRevision::create(['request_id' => $request->id, 'revision_id' => $revision->id, 'usage_id' => $usage->id,
            'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->name($actor)]);
        $pending = $review['pending_by_drawing'];
        if ($returnId) unset($pending[$revision->drawing_id]);
        $request->update(['status' => $pending ? 'changes_requested' : 'delivered', 'version' => $request->version + 1, 'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
        $this->log($request, 'drawing_deliver', '技术交图 '.$revision->drawing->drawing_no.' V'.$revision->version_no.($returnId ? '（回应修改意见）' : ''), $actor,
            ['request_id' => $request->id, 'request_revision_id' => $row->id, 'revision_id' => $revision->id, 'usage_id' => $usage->id,
                'return_request_revision_id' => $returnId ?: null, 'returned_revision_id' => $review['returns'][$returnId]['revision_id'] ?? null,
                'base_revision_id' => $revision->previous_revision_id]);
    }

    public function confirm(int $id, int $requestRevisionId, array $data, object $actor): CrmDrawingRequestRevision
    {
        return DB::transaction(function () use ($id, $requestRevisionId, $data, $actor) {
            $request = $this->visible($id, $actor, true);
            abort_unless($this->abilities($request, $actor)['confirm'], 403, '无权登记本需求的客户确认。');
            $reason = trim((string) ($data['reason'] ?? ''));
            abort_if($reason === '' || mb_strlen($reason) > 1000, 422, '请填写客户确认说明。');
            $row = $request->revisions()->lockForUpdate()->findOrFail($requestRevisionId);
            $review = $this->reviewStates([$request], $actor)[$request->id];
            abort_if(isset($review['returns'][$row->id]), 422, '该交图版本已要求修改，不能再登记客户确认。');
            if ($row->confirmed_at) return $row;
            $this->version($request, $data);
            abort_unless($this->reviewRow($row, $review)['can_confirm'], 422, '该图号仍有待处理的修改意见，请先提交修改版。');
            $row->update(['confirmed_at' => now(), 'confirmed_by_legacy_id' => $actor->legacy_id, 'confirmed_by_name' => $this->name($actor), 'confirmation_note' => $reason]);
            // 全局确认只用于绘图统计；可开单资格始终取本需求的这条确认事实。
            app(TechnicalDrawingApplicationService::class)->confirmLinked('drawing_request', $id, 0, $actor, $reason, (int) $row->revision_id);
            $request->update(['status' => $review['pending_by_drawing'] ? 'changes_requested' : 'confirmed', 'version' => $request->version + 1, 'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
            $this->log($request, 'drawing_confirm', '客户确认图纸版本', $actor, ['request_id' => $request->id, 'request_revision_id' => $row->id, 'revision_id' => $row->revision_id, 'reason' => $reason]);
            return $row;
        }, 3);
    }

    public function returnRevision(int $id, int $requestRevisionId, array $data, object $actor): CrmDrawingRequestRevision
    {
        return DB::transaction(function () use ($id, $requestRevisionId, $data, $actor) {
            $request = $this->visible($id, $actor, true);
            $review = $this->reviewStates([$request], $actor)[$request->id];
            abort_unless($review['abilities']['confirm'], 403, '无权要求修改本需求的图纸。');
            $reason = trim((string) ($data['reason'] ?? ''));
            abort_if($reason === '' || mb_strlen($reason) > 1000, 422, '请填写修改意见，最多1000字。');
            $row = $request->revisions()->lockForUpdate()->findOrFail($requestRevisionId);
            if ($returned = $review['returns'][$row->id] ?? null) {
                abort_unless((int) $returned['returned_by_legacy_id'] === (int) $actor->legacy_id && $returned['reason'] === $reason, 409, '该版本已要求修改，请刷新查看原修改意见。');
                return $row;
            }
            abort_if($row->confirmed_at, 422, '客户已确认的版本不能退回修改，请保留原确认记录。');
            $this->version($request, $data);
            abort_unless($this->reviewRow($row, $review)['can_request_changes'], 422, '只能要求修改此图号当前待确认的交图版本，请刷新后重选。');
            $request->update(['status' => 'changes_requested', 'version' => $request->version + 1,
                'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
            $this->log($request, 'drawing_return', '要求修改图纸 '.$row->revision->drawing->drawing_no.' V'.$row->revision->version_no, $actor,
                ['request_id' => $request->id, 'request_revision_id' => $row->id, 'revision_id' => $row->revision_id, 'drawing_id' => $row->revision->drawing_id,
                    'reason' => $reason, 'returned_at' => now()->toDateTimeString(), 'returned_by_legacy_id' => $actor->legacy_id, 'returned_by_name' => $this->name($actor)]);
            return $row;
        }, 3);
    }

    public function confirmedForOrder(int $requestRevisionId, object $actor, int $customerId, ?int $opportunityId = null, bool $lock = false): array
    {
        $row = CrmDrawingRequestRevision::query()->findOrFail($requestRevisionId);
        $request = $this->visible((int) $row->request_id, $actor, $lock);
        $row = $request->revisions()->with('revision.drawing')->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($requestRevisionId);
        $review = $this->reviewStates([$request], $actor)[$request->id];
        abort_if(isset($review['returns'][$row->id]), 422, '已要求修改的交图版本不能用于订单，请选择已确认的其他版本。');
        abort_unless($row->confirmed_at && (int) $request->customer_id === $customerId, 422, '只能关联本客户已确认的图纸版本。');
        if ($opportunityId) {
            abort_unless((int) $request->opportunity_id === $opportunityId, 422, '图纸需求不属于来源商机。');
            app(CrmOpportunityApplicationService::class)->orderCustomerContext($opportunityId, $customerId, $actor, $lock);
        } else $this->customers->assert(SalesCustomer::query()->findOrFail($customerId), $actor, 'select', '无权为该客户选用图纸。');
        $revision = $row->revision;
        $file = app(TechnicalDrawingApplicationService::class)->revisionFile($revision);
        return $this->revisionRow($row, $request, $actor, null, $review) + ['request_id' => $request->id, 'request_no' => $request->request_no, 'title' => $request->title, 'customer_id' => $request->customer_id,
            'lead_id' => $request->lead_id, 'opportunity_id' => $request->opportunity_id,
            'source_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'source_id' => $request->opportunity_id ?: $request->lead_id,
            'source_no' => $request->request_no,
            'storage_disk' => $file['storage_disk'], 'storage_path' => $file['storage_path'], 'file_hash' => $file['file_hash']];
    }

    public function confirmedCandidates(array $filters, object $actor): array
    {
        $query = CrmDrawingRequestRevision::query()->with('revision.drawing', 'request')->whereNotNull('confirmed_at')->whereHas('request', function ($q) use ($filters, $actor) {
            $this->applyVisible($q, $actor); $q->where('customer_id', $filters['customer_id']);
            if (!empty($filters['opportunity_id'])) $q->where('opportunity_id', $filters['opportunity_id']);
            if (!empty($filters['lead_id'])) $q->where('lead_id', $filters['lead_id']);
        });
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->whereHas('revision.drawing', fn ($q) => $q->where(fn ($w) => $w->where('drawing_no', 'like', '%'.$keyword.'%')->orWhere('drawing_name', 'like', '%'.$keyword.'%')));
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $contexts = $this->sourceContexts($page->getCollection()->pluck('request'), $actor);
        $reviews = $this->reviewStates($page->getCollection()->pluck('request'), $actor);
        return $page->through(fn ($row) => $this->revisionRow($row, $row->request, $actor, $contexts['drawing_request:'.$row->request_id], $reviews[$row->request_id]))->toArray();
    }

    public function references(array $filters, object $actor): array
    {
        $subject = $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
        $request = null;
        if (!empty($filters['request_id'])) {
            $request = $this->visible((int) $filters['request_id'], $actor);
            abort_unless($filters['subject_type'] === ($request->opportunity_id ? 'opportunity' : 'lead')
                && (int) ($request->opportunity_id ?: $request->lead_id) === (int) $subject->id, 422, '需求所属业务已变化，请刷新后重新选择参考资料。');
        }
        $query = $this->referenceQuery($filters['subject_type'], (int) $subject->id, $actor);
        if ($request) {
            $availableIds = $query->select('id');
            $query = CrmAttachment::query()->where(fn ($q) => $q->whereIn('id', $availableIds)->orWhereIn('id', $request->reference_attachment_ids ?? []));
        }
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('name', 'like', '%'.$keyword.'%');
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $page->setCollection(collect($this->referenceRows($page->getCollection(), $request)));
        return $page->toArray();
    }

    public function downloadReference(int $id, int $attachmentId, object $actor)
    {
        $request = $this->visible($id, $actor);
        abort_unless(in_array($attachmentId, $request->reference_attachment_ids ?? [], true), 404, '该附件不属于本绘图需求。');
        $file = CrmAttachment::query()->findOrFail($attachmentId);
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404, '附件文件不存在。');
        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->name, ['Content-Type' => $file->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function sourceContexts(iterable $requests, object $actor): array
    {
        $sources = [];
        foreach ($requests as $request) $sources[] = ['source_type' => 'drawing_request', 'source_id' => (int) $request->id,
            'source_no' => $request->request_no, 'source_name' => $request->title];
        return app(TechnicalDrawingSourceService::class)->contexts($sources, $actor);
    }

    public function versionReviews(iterable $sources, object $actor): array
    {
        $ids = collect($sources)->where('source_type', 'drawing_request')->pluck('source_id')->unique();
        if ($ids->isEmpty()) return [];
        $query = CrmDrawingRequest::query()->whereIn('id', $ids);
        $this->applyVisible($query, $actor);
        $result = [];
        foreach ($this->reviewStates($query->get(), $actor) as $requestId => $review) {
            foreach ($review['rows'] as $row) {
                $fields = $this->reviewRow($row, $review);
                unset($fields['can_confirm']);
                if ($fields['review_status'] === 'returned') $fields['status_label'] = $fields['review_status_label'];
                $result[$requestId.':'.$row->revision_id] = $fields;
            }
        }
        return $result;
    }

    private function reviewStates(iterable $requests, object $actor): array
    {
        $requests = collect($requests)->keyBy('id');
        if ($requests->isEmpty()) return [];
        $rows = CrmDrawingRequestRevision::query()->with('revision.drawing')->whereIn('request_id', $requests->keys())->orderByDesc('id')->get();
        $byId = $rows->keyBy('id');
        $deliveredRequestIds = $rows->pluck('request_id')->flip();
        $currentVersions = TechnicalDrawingRevision::query()->whereIn('id', $rows->pluck('revision.drawing.current_revision_id')->filter()->unique())->pluck('version_no', 'id')->all();
        $states = [];
        foreach ($requests as $request) $states[$request->id] = ['rows' => $rows->where('request_id', $request->id)->values(),
            'abilities' => $this->abilities($request, $actor, $deliveredRequestIds->has($request->id)), 'returns' => [], 'resolutions' => [], 'pending_by_drawing' => [], 'latest_by_drawing' => [], 'current_versions' => $currentVersions];
        foreach ($rows as $row) $states[$row->request_id]['latest_by_drawing'][$row->revision->drawing_id] ??= (int) $row->id;

        $resolutions = [];
        // 线索转商机不会搬日志；两侧均按父单索引读取完整事实，不依赖界面的日志分页。
        foreach (['erp_crm_lead_logs' => 'lead_id', 'erp_crm_opportunity_stage_logs' => 'opportunity_id'] as $table => $subject) {
            $ids = $requests->pluck($subject)->filter()->unique();
            if ($ids->isEmpty()) continue;
            $logs = DB::table($table)->whereIn($subject, $ids)->whereIn('action', ['drawing_return', 'drawing_deliver'])
                ->whereIn('after_snapshot->request_id', $requests->keys())->orderBy('id')->get(['action', 'after_snapshot', 'operator_legacy_id', 'operator_name', 'created_at']);
            foreach ($logs as $log) {
                $facts = json_decode($log->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $requestId = (int) ($facts['request_id'] ?? 0);
                $row = $byId->get((int) ($facts['request_revision_id'] ?? 0));
                if (!$row || (int) $row->request_id !== $requestId || (int) $row->revision_id !== (int) ($facts['revision_id'] ?? 0)) continue;
                if ($log->action === 'drawing_return') {
                    $states[$requestId]['returns'][$row->id] ??= $facts + ['returned_at' => $log->created_at,
                        'returned_by_legacy_id' => $log->operator_legacy_id, 'returned_by_name' => $log->operator_name];
                } elseif (!empty($facts['return_request_revision_id'])) $resolutions[] = $facts;
            }
        }
        foreach ($resolutions as $facts) {
            $requestId = (int) $facts['request_id'];
            $returnedId = (int) ($facts['return_request_revision_id'] ?? 0);
            $returned = $byId->get($returnedId);
            $replacement = $byId->get((int) $facts['request_revision_id']);
            if ($returned && isset($states[$requestId]['returns'][$returnedId]) && $replacement->revision->action === 'modify'
                && (int) $returned->request_id === $requestId && (int) $returned->revision->drawing_id === (int) $replacement->revision->drawing_id
                && $replacement->revision->version_no > $returned->revision->version_no) $states[$requestId]['resolutions'][$returnedId] = $facts;
        }
        foreach ($states as &$state) {
            foreach ($state['returns'] as $rowId => $facts) if (!isset($state['resolutions'][$rowId])) $state['pending_by_drawing'][$byId->get($rowId)->revision->drawing_id] = (int) $rowId;
        }
        unset($state);
        return $states;
    }

    private function reviewRow(CrmDrawingRequestRevision $row, array $review): array
    {
        $revision = $row->revision;
        $returned = $review['returns'][$row->id] ?? null;
        $resolved = $review['resolutions'][$row->id] ?? null;
        $latest = ($review['latest_by_drawing'][$revision->drawing_id] ?? null) === (int) $row->id;
        $pending = isset($review['pending_by_drawing'][$revision->drawing_id]);
        $status = $returned ? 'returned' : ($row->confirmed_at ? 'confirmed' : ($latest ? 'pending' : 'history'));
        $canConfirm = !$returned && !$row->confirmed_at && !$pending && $review['abilities']['confirm'];
        return ['review_status' => $status,
            'review_status_label' => $returned ? ($resolved ? '已提交修改版' : '待技术修改') : ['confirmed' => '客户已确认', 'pending' => '待客户确认', 'history' => '历史交版'][$status],
            'review_confirmed_at' => $row->confirmed_at?->toDateTimeString(),
            'return_reason' => $returned['reason'] ?? null, 'returned_at' => $returned['returned_at'] ?? null,
            'returned_by_legacy_id' => $returned['returned_by_legacy_id'] ?? null, 'returned_by_name' => $returned['returned_by_name'] ?? null,
            'return_request_revision_id' => $returned ? (int) $row->id : null,
            'return_resolved_revision_id' => isset($resolved['revision_id']) ? (int) $resolved['revision_id'] : null,
            'current_revision_id' => (int) $revision->drawing->current_revision_id,
            'current_version' => (int) ($review['current_versions'][$revision->drawing->current_revision_id] ?? $revision->version_no),
            'can_confirm' => $canConfirm, 'can_request_changes' => $canConfirm && $latest,
            'can_modify' => (bool) ($returned && !$resolved && $review['abilities']['deliver'])];
    }

    private function revisionRow(CrmDrawingRequestRevision $row, CrmDrawingRequest $request, object $actor, ?array $sourceContext = null, ?array $review = null): array
    {
        $sourceContext ??= $this->sourceContexts([$request], $actor)['drawing_request:'.$request->id];
        $review ??= $this->reviewStates([$request], $actor)[$request->id];
        $revision = $row->revision; $drawing = $revision->drawing;
        $files = app(TechnicalDrawingApplicationService::class)->presentFiles($revision, (int) $row->usage_id);
        return ['id' => $row->id, 'request_revision_id' => $row->id, 'request_id' => $request->id, 'revision_id' => $revision->id, 'usage_id' => $row->usage_id,
            'request_no' => $request->request_no, 'title' => $request->title, 'customer_id' => $request->customer_id,
            'lead_id' => $request->lead_id, 'opportunity_id' => $request->opportunity_id,
            'source_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'source_id' => $request->opportunity_id ?: $request->lead_id, 'source_no' => $request->request_no,
            'source_context' => $sourceContext,
            'drawing_id' => $drawing->id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name, 'drawing_type' => $drawing->drawing_type,
            'version_no' => $revision->version_no, 'designer_legacy_id' => $revision->designer_legacy_id, 'designer_name' => $revision->designer_name,
            'completed_date' => $revision->completed_date?->toDateString(), 'original_name' => $revision->original_name, 'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size,
            'files' => $files, 'file_count' => count($files), 'preview_url' => $files[0]['preview_url'],
            'confirmed_at' => $row->confirmed_at?->toDateTimeString(), 'confirmed_by_legacy_id' => $row->confirmed_by_legacy_id, 'confirmed_by_name' => $row->confirmed_by_name,
            'confirmation_note' => $row->confirmation_note, 'can_download' => true,
            'download_url' => app(TechnicalDrawingSourceService::class)->fileUrl((int) $revision->id, (int) $row->usage_id)] + $this->reviewRow($row, $review);
    }

    private function referenceRows(iterable $files, ?CrmDrawingRequest $request = null): array
    {
        $files = collect($files);
        if ($files->isEmpty()) return [];
        $versions = CrmOpportunityFile::query()->with('opportunity:id,opportunity_no')->whereIn('id', $files->where('subject_type', 'opportunity_file')->pluck('subject_id'))->get()->keyBy('id');
        $leads = CrmLead::query()->whereIn('id', $files->where('subject_type', 'lead')->pluck('subject_id'))->pluck('lead_no', 'id');
        $followups = CrmFollowup::query()->whereIn('id', $files->where('subject_type', 'followup')->pluck('subject_id'))->get(['id', 'subject_type'])->keyBy('id');
        $owners = CrmDrawingRequest::query()->whereIn('id', $files->where('subject_type', 'drawing_request')->pluck('subject_id'))->get(['id', 'request_no', 'reference_attachment_ids'])->keyBy('id');
        return $files->map(function ($file) use ($versions, $leads, $followups, $owners, $request) {
            $row = app(CrmAttachmentApplicationService::class)->present($file);
            $version = null;
            $sourceLabel = '未归档资料';
            $sourceNo = null;
            $status = $file->is_active ? 'unversioned' : 'inactive';
            $statusLabel = $file->is_active ? '未分版本' : '已移除';
            if ($file->subject_type === 'opportunity_file') {
                $version = $versions->get($file->subject_id);
                if ($version && (int) $version->attachment_id === (int) $file->id) {
                    $sourceLabel = '商机'.(CrmOpportunityApplicationService::FILE_CATEGORIES[$version->category] ?? $version->category).'资料';
                    $sourceNo = $version->opportunity?->opportunity_no;
                    if ($file->is_active) { $status = $version->status; $statusLabel = CrmOpportunityApplicationService::FILE_STATUSES[$version->status] ?? $version->status; }
                } else $version = null;
            } elseif ($file->subject_type === 'lead') {
                $sourceLabel = '线索资料'; $sourceNo = $leads->get($file->subject_id);
            } elseif ($file->subject_type === 'followup') {
                $followup = $followups->get($file->subject_id);
                $sourceLabel = ($followup?->subject_type === 'lead' ? '线索' : ($followup?->subject_type === 'opportunity' ? '商机' : '')).'跟进资料';
            } elseif ($file->subject_type === 'drawing_request') {
                $owner = $owners->get($file->subject_id);
                $sourceLabel = '需求历史资料'; $sourceNo = $owner?->request_no;
                if ($owner && in_array((int) $file->id, $owner->reference_attachment_ids ?? [], true)) $row['download_url'] = '/api/v1/erp/crm/drawing-requests/'.$owner->id.'/references/'.$file->id.'/download';
            }
            if ($request && in_array((int) $file->id, $request->reference_attachment_ids ?? [], true)) $row['download_url'] = '/api/v1/erp/crm/drawing-requests/'.$request->id.'/references/'.$file->id.'/download';
            return $row + ['source_label' => $sourceLabel, 'source_no' => $sourceNo, 'version_no' => $version?->version_no,
                'version_label' => $version ? 'V'.$version->version_no : null, 'status' => $status, 'status_label' => $statusLabel];
        })->values()->all();
    }

    private function referenceQuery(string $type, int $id, object $actor): Builder
    {
        return CrmAttachment::query()->where('is_active', true)->where(function ($q) use ($type, $id, $actor) {
            if ($type === 'lead') $q->where(fn ($r) => $r->where('subject_type', 'lead')->where('subject_id', $id)->where('category', 'communication'));
            else $q->where(fn ($r) => $r->where('subject_type', 'opportunity_file')->whereIn('subject_id', DB::table('erp_crm_opportunity_files')->where('opportunity_id', $id)->where('category', 'requirement')->whereColumn('attachment_id', 'erp_crm_attachments.id')->select('id')));
            $q->orWhere(fn ($r) => $r->where('subject_type', 'followup')->where('category', 'communication')->whereIn('subject_id', DB::table('erp_crm_followups')->where('subject_type', $type)->where('subject_id', $id)->select('id')));
            $requests = CrmDrawingRequest::query()->where($type === 'lead' ? 'lead_id' : 'opportunity_id', $id);
            if ($type === 'lead') $requests->whereNull('opportunity_id');
            $this->applyVisible($requests, $actor);
            $q->orWhere(fn ($r) => $r->where('subject_type', 'drawing_request')->where('category', 'reference')->whereIn('subject_id',
                $requests->select('id')->whereRaw('JSON_CONTAINS(erp_crm_drawing_requests.reference_attachment_ids, CAST(erp_crm_attachments.id AS JSON))')));
            if ($type === 'opportunity' && $this->customers->can($actor, 'crm.lead.view')) {
                $sourceLead = CrmOpportunity::query()->whereKey($id)->value('source_lead_id');
                $leads = CrmLead::query()->whereKey($sourceLead ?: 0);
                app(CrmLeadApplicationService::class)->applyVisible($leads, $actor);
                if ($sourceLead && $leads->exists()) $q->orWhere(fn ($r) => $r->whereIn('id', $this->referenceQuery('lead', (int) $sourceLead, $actor)->select('id')));
            }
        });
    }

    private function assertReference(int $attachmentId, string $type, int $id, object $actor, ?int $requestId, array $oldRefs): CrmAttachment
    {
        $file = CrmAttachment::query()->lockForUpdate()->findOrFail($attachmentId);
        if (!$file->subject_type) { abort_unless((int) $file->uploaded_by_legacy_id === (int) $actor->legacy_id, 403, '只能使用本人新上传的客户参考资料。'); return $file; }
        if ($requestId && in_array($attachmentId, $oldRefs, true)) return $file;
        abort_unless($file->is_active, 422, '该资料已移除，不能重新选择。');
        abort_unless($this->referenceQuery($type, $id, $actor)->whereKey($attachmentId)->exists(), 403, '客户来图须属于当前线索或商机。');
        return $file;
    }

    private function subject(string $type, int $id, object $actor, bool $lock = false): CrmLead|CrmOpportunity
    {
        if ($type === 'lead') { $service = app(CrmLeadApplicationService::class); $service->permission($actor, 'crm.lead.view'); return $service->visible($id, $actor, $lock); }
        abort_unless($type === 'opportunity', 422, '请选择线索或商机。');
        $service = app(CrmOpportunityApplicationService::class); $service->permission($actor, 'crm.opportunity.view'); return $service->visible($id, $actor, $lock);
    }

    private function requestSubject(CrmDrawingRequest $request, object $actor): CrmLead|CrmOpportunity
    {
        return $this->subject($request->opportunity_id ? 'opportunity' : 'lead', (int) ($request->opportunity_id ?: $request->lead_id), $actor);
    }

    private function canManageSubject(CrmLead|CrmOpportunity $subject, object $actor, bool $lock = false): bool
    {
        if ($subject instanceof CrmLead) { $abilities = app(CrmLeadApplicationService::class)->abilities($subject, $actor); return $subject->status === 'active' && ($abilities['edit'] || $abilities['follow']); }
        return app(CrmOpportunityApplicationService::class)->abilities($subject, $actor, $lock)['file'];
    }

    private function version(CrmDrawingRequest $request, array $data): void
    {
        abort_unless(isset($data['version']) && ctype_digit((string) $data['version']) && (int) $data['version'] === (int) $request->version, 409, '绘图需求已变化，请刷新后重试。');
    }

    private function log(CrmDrawingRequest $request, string $action, string $summary, object $actor, array $facts): void
    {
        if ($request->opportunity_id) app(CrmOpportunityApplicationService::class)->log(CrmOpportunity::query()->findOrFail($request->opportunity_id), $action, $summary, null, $facts, null, $actor);
        else app(CrmLeadApplicationService::class)->log(CrmLead::query()->findOrFail($request->lead_id), $action, $summary, null, $facts, null, $actor);
    }

    private function name(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? '')); }
}
