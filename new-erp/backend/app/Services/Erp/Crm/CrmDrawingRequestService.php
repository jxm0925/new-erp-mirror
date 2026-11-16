<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\{CrmAttachment, CrmDrawingRequest, CrmDrawingRequestRevision, CrmLead, CrmOpportunity, SalesCustomer, TechnicalDrawingRevision, TechnicalDrawingUsage};
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
            'statuses' => [['value' => 'pending', 'label' => '待交图'], ['value' => 'delivered', 'label' => '待客户确认'], ['value' => 'confirmed', 'label' => '客户已确认']]];
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

    public function applyVisible(Builder $query, object $actor): void
    {
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

    public function visible(int $id, object $actor, bool $lock = false): CrmDrawingRequest
    {
        $query = CrmDrawingRequest::query();
        $this->applyVisible($query, $actor);
        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function paginate(array $filters, object $actor): array
    {
        $query = CrmDrawingRequest::query();
        $this->applyVisible($query, $actor);
        if (!empty($filters['subject_type'])) {
            $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
            $query->where($filters['subject_type'] === 'lead' ? 'lead_id' : 'opportunity_id', $filters['subject_id']);
        }
        if (($filters['tab'] ?? '') === 'mine') {
            abort_unless($this->isTechnician($actor), 403, '没有技术交图权限。');
            $query->where('technician_legacy_id', $actor->legacy_id);
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('request_no', 'like', '%'.$keyword.'%')->orWhere('title', 'like', '%'.$keyword.'%'));
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->through(fn ($row) => $this->present($row, $actor, false))->toArray();
    }

    public function save(?int $id, array $data, object $actor): CrmDrawingRequest
    {
        $data = Validator::make($data, ['subject_type' => 'required|in:lead,opportunity', 'subject_id' => 'required|integer|min:1',
            'title' => 'required|string|max:200', 'requirement' => 'required|string|max:10000', 'technician_legacy_id' => 'required|integer|min:1',
            'reference_attachment_ids' => 'nullable|array|max:100', 'reference_attachment_ids.*' => 'required|integer|min:1|distinct',
            'version' => 'nullable|integer|min:1', 'request_key' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/'])->validate();
        $data['title'] = trim($data['title']); $data['requirement'] = trim($data['requirement']);
        abort_if($data['title'] === '' || $data['requirement'] === '', 422, '请填写需求名称和具体要求。');
        $data['reference_attachment_ids'] = array_map('intval', $data['reference_attachment_ids'] ?? []);
        $hash = hash('sha256', json_encode(array_diff_key($data, ['version' => true]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
                    abort_unless((int) ($data['subject_type'] === 'lead' ? $request->lead_id : $request->opportunity_id) === (int) $subject->id, 422, '不能更换需求所属业务。');
                }
                $technician = DB::table('erp_legacy_admin_users')->where('legacy_id', $data['technician_legacy_id'])->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->lockForUpdate()->first();
                abort_unless($technician && $this->isTechnician($technician), 422, '承接人员须为在职且具有技术资料管理权限的员工。');
                $oldRefs = $request->reference_attachment_ids ?? [];
                foreach ($data['reference_attachment_ids'] as $attachmentId) $this->assertReference($attachmentId, $data['subject_type'], (int) $subject->id, $actor, $id, $oldRefs);
                $values = ['title' => $data['title'], 'requirement' => $data['requirement'], 'technician_legacy_id' => $technician->legacy_id,
                    'technician_name' => $this->name($technician), 'reference_attachment_ids' => $data['reference_attachment_ids'],
                    'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)];
                if (!$id) $values += ['request_no' => 'DR-'.strtoupper((string) Str::ulid()), 'request_key' => $data['request_key'], 'request_hash' => $hash,
                    'customer_id' => $subject->customer_id, 'lead_id' => $data['subject_type'] === 'lead' ? $subject->id : ($subject->source_lead_id ?: null),
                    'opportunity_id' => $data['subject_type'] === 'opportunity' ? $subject->id : null, 'status' => 'pending', 'version' => 1,
                    'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->name($actor)];
                else $values['version'] = $request->version + 1;
                $request->fill($values)->save();
                $newIds = CrmAttachment::query()->whereIn('id', $data['reference_attachment_ids'])->where(fn ($q) => $q->whereNull('subject_type')->orWhere(fn ($r) => $r->where('subject_type', 'drawing_request')->where('subject_id', $request->id)))->pluck('id')->all();
                app(CrmAttachmentApplicationService::class)->bind($newIds, 'drawing_request', (int) $request->id, $actor, 'reference');
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

    public function abilities(CrmDrawingRequest $request, object $actor): array
    {
        $manage = false;
        try { $manage = $this->canManageSubject($this->requestSubject($request, $actor), $actor); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {}
        return ['create' => $manage, 'edit' => $manage && $request->status === 'pending',
            'deliver' => $this->isTechnician($actor) && (int) $request->technician_legacy_id === (int) $actor->legacy_id,
            'confirm' => $manage];
    }

    public function present(CrmDrawingRequest $request, object $actor, bool $detail = true): array
    {
        $row = $request->attributesToArray(); unset($row['request_hash'], $row['request_key']);
        $row += ['customer_name' => SalesCustomer::query()->whereKey($request->customer_id)->value('customer_name'),
            'subject_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'subject_id' => $request->opportunity_id ?: $request->lead_id,
            'subject_label' => $request->opportunity_id ? '商机' : '线索', 'abilities' => $this->abilities($request, $actor),
            'status_label' => ['pending' => '待交图', 'delivered' => '待客户确认', 'confirmed' => '客户已确认'][$request->status] ?? $request->status];
        $confirmed = $request->revisions()->with('revision.drawing')->whereNotNull('confirmed_at')->orderByDesc('confirmed_at')->orderByDesc('id')->first();
        $row['confirmed_revision'] = $confirmed ? $this->revisionRow($confirmed, $request, $actor) : null;
        if ($detail) {
            $row['reference_attachments'] = CrmAttachment::query()->whereIn('id', $request->reference_attachment_ids ?? [])->orderBy('id')->get()->map(fn ($file) => $this->referenceRow($file, $request))->all();
            $row['revisions'] = $request->revisions()->with('revision.drawing')->orderByDesc('id')->get()->map(fn ($revision) => $this->revisionRow($revision, $request, $actor))->all();
        }
        return $row;
    }

    public function deliver(int $id, array $data, object $actor, ?UploadedFile $file): array
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

    public function bindDelivery(TechnicalDrawingUsage $usage, TechnicalDrawingRevision $revision, object $actor): void
    {
        $request = $this->visible((int) $usage->source_id, $actor, true);
        abort_unless($this->abilities($request, $actor)['deliver'], 403, '只有本需求的承接技术人员可以交图。');
        CrmDrawingRequestRevision::create(['request_id' => $request->id, 'revision_id' => $revision->id, 'usage_id' => $usage->id,
            'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->name($actor)]);
        $request->update(['status' => 'delivered', 'version' => $request->version + 1, 'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
        $this->log($request, 'drawing_deliver', '技术交图 '.$revision->drawing->drawing_no.' V'.$revision->version_no, $actor, ['revision_id' => $revision->id, 'usage_id' => $usage->id]);
    }

    public function confirm(int $id, int $requestRevisionId, array $data, object $actor): CrmDrawingRequestRevision
    {
        return DB::transaction(function () use ($id, $requestRevisionId, $data, $actor) {
            $request = $this->visible($id, $actor, true);
            abort_unless($this->abilities($request, $actor)['confirm'], 403, '无权登记本需求的客户确认。');
            $reason = trim((string) ($data['reason'] ?? ''));
            abort_if($reason === '' || mb_strlen($reason) > 1000, 422, '请填写客户确认说明。');
            $row = $request->revisions()->lockForUpdate()->findOrFail($requestRevisionId);
            if ($row->confirmed_at) return $row;
            $this->version($request, $data);
            $row->update(['confirmed_at' => now(), 'confirmed_by_legacy_id' => $actor->legacy_id, 'confirmed_by_name' => $this->name($actor), 'confirmation_note' => $reason]);
            // 全局确认只用于绘图统计；可开单资格始终取本需求的这条确认事实。
            app(TechnicalDrawingApplicationService::class)->confirmLinked('drawing_request', $id, 0, $actor, $reason, (int) $row->revision_id);
            $request->update(['status' => 'confirmed', 'version' => $request->version + 1, 'updated_by_legacy_id' => $actor->legacy_id, 'updated_by_name' => $this->name($actor)]);
            $this->log($request, 'drawing_confirm', '客户确认图纸版本', $actor, ['request_revision_id' => $row->id, 'revision_id' => $row->revision_id, 'reason' => $reason]);
            return $row;
        }, 3);
    }

    public function confirmedForOrder(int $requestRevisionId, object $actor, int $customerId, ?int $opportunityId = null, bool $lock = false): array
    {
        $row = CrmDrawingRequestRevision::query()->findOrFail($requestRevisionId);
        $request = $this->visible((int) $row->request_id, $actor, $lock);
        $row = $request->revisions()->with('revision.drawing')->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($requestRevisionId);
        abort_unless($row->confirmed_at && (int) $request->customer_id === $customerId, 422, '只能关联本客户已确认的图纸版本。');
        if ($opportunityId) {
            abort_unless((int) $request->opportunity_id === $opportunityId, 422, '图纸需求不属于来源商机。');
            app(CrmOpportunityApplicationService::class)->orderCustomerContext($opportunityId, $customerId, $actor, $lock);
        } else $this->customers->assert(SalesCustomer::query()->findOrFail($customerId), $actor, 'select', '无权为该客户选用图纸。');
        $revision = $row->revision;
        return $this->revisionRow($row, $request, $actor) + ['request_id' => $request->id, 'request_no' => $request->request_no, 'title' => $request->title, 'customer_id' => $request->customer_id,
            'lead_id' => $request->lead_id, 'opportunity_id' => $request->opportunity_id,
            'source_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'source_id' => $request->opportunity_id ?: $request->lead_id,
            'source_no' => $request->request_no,
            'storage_disk' => $revision->storage_disk, 'storage_path' => $revision->storage_path, 'file_hash' => $revision->file_hash];
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
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->through(fn ($row) => $this->revisionRow($row, $row->request, $actor))->toArray();
    }

    public function references(array $filters, object $actor): array
    {
        $subject = $this->subject($filters['subject_type'], (int) $filters['subject_id'], $actor);
        $query = $this->referenceQuery($filters['subject_type'], (int) $subject->id, $actor);
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('name', 'like', '%'.$keyword.'%');
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->through(fn ($file) => app(CrmAttachmentApplicationService::class)->present($file) + ['source_label' => '客户来图'])->toArray();
    }

    public function downloadReference(int $id, int $attachmentId, object $actor)
    {
        $request = $this->visible($id, $actor);
        abort_unless(in_array($attachmentId, $request->reference_attachment_ids ?? [], true), 404, '该附件不属于本绘图需求。');
        $file = CrmAttachment::query()->findOrFail($attachmentId);
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404, '附件文件不存在。');
        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->name, ['Content-Type' => $file->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function revisionRow(CrmDrawingRequestRevision $row, CrmDrawingRequest $request, object $actor): array
    {
        $revision = $row->revision; $drawing = $revision->drawing;
        return ['id' => $row->id, 'request_revision_id' => $row->id, 'request_id' => $request->id, 'revision_id' => $revision->id, 'usage_id' => $row->usage_id,
            'request_no' => $request->request_no, 'title' => $request->title, 'customer_id' => $request->customer_id,
            'lead_id' => $request->lead_id, 'opportunity_id' => $request->opportunity_id,
            'source_type' => $request->opportunity_id ? 'opportunity' : 'lead', 'source_id' => $request->opportunity_id ?: $request->lead_id, 'source_no' => $request->request_no,
            'drawing_id' => $drawing->id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name, 'drawing_type' => $drawing->drawing_type,
            'version_no' => $revision->version_no, 'designer_legacy_id' => $revision->designer_legacy_id, 'designer_name' => $revision->designer_name,
            'completed_date' => $revision->completed_date?->toDateString(), 'original_name' => $revision->original_name, 'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size,
            'confirmed_at' => $row->confirmed_at?->toDateTimeString(), 'confirmed_by_legacy_id' => $row->confirmed_by_legacy_id, 'confirmed_by_name' => $row->confirmed_by_name,
            'confirmation_note' => $row->confirmation_note, 'can_download' => true, 'can_confirm' => !$row->confirmed_at && $this->abilities($request, $actor)['confirm'],
            'download_url' => app(TechnicalDrawingSourceService::class)->fileUrl((int) $revision->id, (int) $row->usage_id)];
    }

    private function referenceRow(CrmAttachment $file, CrmDrawingRequest $request): array
    {
        return array_replace(app(CrmAttachmentApplicationService::class)->present($file), ['download_url' => '/api/v1/erp/crm/drawing-requests/'.$request->id.'/references/'.$file->id.'/download']);
    }

    private function referenceQuery(string $type, int $id, object $actor): Builder
    {
        return CrmAttachment::query()->where('is_active', true)->where(function ($q) use ($type, $id, $actor) {
            if ($type === 'lead') $q->where(fn ($r) => $r->where('subject_type', 'lead')->where('subject_id', $id)->where('category', 'communication'));
            else $q->where(fn ($r) => $r->where('subject_type', 'opportunity_file')->whereIn('subject_id', DB::table('erp_crm_opportunity_files')->where('opportunity_id', $id)->where('category', 'requirement')->select('id')));
            $q->orWhere(fn ($r) => $r->where('subject_type', 'followup')->where('category', 'communication')->whereIn('subject_id', DB::table('erp_crm_followups')->where('subject_type', $type)->where('subject_id', $id)->select('id')));
            if ($type === 'opportunity' && $this->customers->can($actor, 'crm.lead.view')) {
                $sourceLead = CrmOpportunity::query()->whereKey($id)->value('source_lead_id');
                $leads = CrmLead::query()->whereKey($sourceLead ?: 0);
                app(CrmLeadApplicationService::class)->applyVisible($leads, $actor);
                if ($sourceLead && $leads->exists()) $q->orWhere(fn ($r) => $r->whereIn('id', $this->referenceQuery('lead', (int) $sourceLead, $actor)->select('id')));
            }
        });
    }

    private function assertReference(int $attachmentId, string $type, int $id, object $actor, ?int $requestId, array $oldRefs): void
    {
        $file = CrmAttachment::query()->lockForUpdate()->findOrFail($attachmentId);
        if (!$file->subject_type) { abort_unless((int) $file->uploaded_by_legacy_id === (int) $actor->legacy_id, 403, '只能使用本人新上传的客户来图。'); return; }
        if ($requestId && in_array($attachmentId, $oldRefs, true)) return;
        abort_unless($this->referenceQuery($type, $id, $actor)->whereKey($attachmentId)->exists(), 403, '客户来图须属于当前线索或商机。');
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

    private function canManageSubject(CrmLead|CrmOpportunity $subject, object $actor): bool
    {
        if ($subject instanceof CrmLead) { $abilities = app(CrmLeadApplicationService::class)->abilities($subject, $actor); return $subject->status === 'active' && ($abilities['edit'] || $abilities['follow']); }
        return app(CrmOpportunityApplicationService::class)->abilities($subject, $actor)['file'];
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
