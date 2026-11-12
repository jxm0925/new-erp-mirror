<?php

namespace App\Services\Erp;

use App\Models\Erp\{Bom, CrmAttachment, CrmOpportunity, CrmOpportunityFile, MfgDrawing, MfgWorkOrder, MfgWorkOrderTech, TechnicalDrawingRevision, TechnicalDrawingUsage};
use App\Services\Erp\Crm\CrmOpportunityApplicationService;
use App\Services\Erp\Manufacturing\WorkOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** 统一图纸只保存明确选中的文件引用，原 CRM 文件仍留在私有磁盘。 */
final class TechnicalDrawingSourceService
{
    private const PRODUCTION_READ = ['production.tech.manage', 'production.work_order.view', 'production.task.view', 'production.task.execute', 'production.board', 'sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality'];

    public function resolve(array $source, object $actor, bool $write = false, bool $lock = false): array
    {
        $type = $source['type'] ?? '';
        $id = (int) ($source['id'] ?? 0);
        $fileId = (int) ($source['file_id'] ?? 0);
        $position = trim((string) ($source['position_uid'] ?? ''));
        abort_unless(in_array($type, ['opportunity', 'bom', 'work_order'], true) && $id > 0, 422, '请选择有效的图纸使用位置。');
        if ($write && $type !== 'bom') abort_unless(isset($source['version']) && ctype_digit((string) $source['version']) && (int) $source['version'] > 0, 422, '请刷新后提交当前业务版本。');
        $file = null;
        if ($type === 'opportunity') {
            $service = app(CrmOpportunityApplicationService::class);
            $service->permission($actor, $write ? 'crm.opportunity.file' : 'crm.opportunity.view');
            $record = $service->visible($id, $actor, $lock);
            if ($write) {
                abort_unless($service->abilities($record, $actor)['file'], 403, '无权维护该商机技术图纸。');
                $service->version($record, $source);
            }
            abort_if($position !== '', 422, '商机图纸不关联 BOM 明细位置。');
            if ($fileId) {
                $origin = CrmOpportunityFile::query()->where('opportunity_id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($fileId);
                abort_unless($origin->category === 'solution', 422, '仅方案文件可明确登记为技术图纸。');
                if ($write) abort_if($origin->status === 'history', 422, '历史方案不能登记或替换技术图纸。');
                $attachment = $origin->attachment;
                abort_unless($attachment && $attachment->subject_type === 'opportunity_file' && (int) $attachment->subject_id === (int) $origin->id, 422, '方案附件归属无效。');
                $file = $this->file($attachment->storage_disk, $attachment->storage_path, $attachment->name, $attachment->mime_type, (int) $attachment->size);
            }
            $no = $record->opportunity_no;
            $name = $record->name;
            $version = (int) $record->version;
        } elseif ($type === 'bom') {
            $this->permission($actor, $write ? ['bom.manage.edit'] : ['bom.manage.view']);
            $record = Bom::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
            if ($write) {
                abort_unless(in_array($record->status, ['draft', 'inactive'], true), 422, '仅草稿或停用 BOM 可维护图纸引用。');
                abort_if($record->submitted_at && ($record->status === 'draft' || $record->audit_status === 'pending'), 422, 'BOM 已提交审核，请先驳回或复制版本。');
                abort_unless(isset($source['version']) && (string) $source['version'] === (string) $record->version, 409, 'BOM 版本已变化，请刷新后重试。');
            }
            abort_if($fileId, 422, 'BOM 图纸请直接选择统一图纸版本。');
            if ($position !== '') abort_unless($record->items()->where('position_uid', $position)->exists(), 422, '图纸关联的 BOM 位置已不存在，请刷新后重选。');
            $no = $record->bom_no;
            $name = $record->bom_name;
            $version = $record->version;
        } else {
            $this->permission($actor, $write ? ['production.tech.manage'] : self::PRODUCTION_READ);
            $record = MfgWorkOrder::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
            if ($write) {
                abort_if(in_array($record->status, ['completed', 'closed', 'cancelled'], true), 422, '工单已结束，不能维护技术图纸。');
                abort_unless(isset($source['version']) && (int) $source['version'] === (int) $record->business_version, 409, '工单已变化，请刷新后重试。');
            }
            if ($position !== '') abort_unless(collect((array) data_get($record->bom_snapshot, 'items', []))->contains(fn ($line) => (string) ($line['position_uid'] ?? '') === $position), 422, '图纸关联的工单 BOM 位置不存在。');
            if ($fileId) {
                $origin = MfgDrawing::query()->where('work_order_id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($fileId);
                if ($write) abort_unless($origin->status === 'active', 422, '图纸已删除或已被替换。');
                $file = $this->file($origin->storage_disk, $origin->storage_path, $origin->original_name, $origin->mime_type, (int) $origin->file_size, $origin->file_hash);
            }
            $no = $record->work_order_no;
            $name = $record->item_name;
            $version = (int) $record->business_version;
        }
        return ['type' => $type, 'id' => $id, 'file_id' => $fileId ?: null, 'position_uid' => $position, 'no' => $no, 'name' => $name, 'version' => $version, 'file' => $file];
    }

    public function applyVisible(Builder $query, object $actor): void
    {
        $query->where(function (Builder $visible) use ($actor) {
            $visible->whereRaw('1 = 0');
            if ($this->can($actor, ['crm.opportunity.view'])) {
                $opportunities = CrmOpportunity::query()->select('id');
                app(CrmOpportunityApplicationService::class)->applyVisible($opportunities, $actor);
                $visible->orWhere(fn ($q) => $q->where('source_type', 'opportunity')->whereIn('source_id', $opportunities));
            }
            if ($this->can($actor, ['bom.manage.view'])) $visible->orWhere(fn ($q) => $q->where('source_type', 'bom')->whereIn('source_id', Bom::query()->select('id')));
            if ($this->can($actor, self::PRODUCTION_READ)) $visible->orWhere(fn ($q) => $q->where('source_type', 'work_order')->whereIn('source_id', MfgWorkOrder::query()->select('id')));
        });
    }

    public function canRead(TechnicalDrawingUsage $usage, object $actor): bool
    {
        try {
            $this->resolve(['type' => $usage->source_type, 'id' => $usage->source_id], $actor);
            return true;
        } catch (HttpExceptionInterface|ModelNotFoundException $error) {
            return false;
        }
    }

    public function bind(TechnicalDrawingUsage $usage, TechnicalDrawingRevision $revision, array $resolved, object $actor): void
    {
        if ($resolved['file']) {
            abort_unless($resolved['file']['storage_disk'] === $revision->storage_disk && $resolved['file']['storage_path'] === $revision->storage_path, 422, '所选来源附件与图纸版本不一致，请选择正确的版本。');
        }
        $actorId = (int) ($actor->legacy_id ?? $actor->id);
        $actorName = ($actor->nickname ?? null) ?: ($actor->username ?? '');
        // 商机待客户确认的方案沿用原状态；BOM、工单的明确选版则立即替换本位置旧引用。
        if ($resolved['type'] !== 'opportunity') $this->activate($usage);
        if ($resolved['type'] === 'work_order') {
            $wo = MfgWorkOrder::query()->findOrFail($resolved['id']);
            $drawing = $resolved['file_id'] ? MfgDrawing::query()->findOrFail($resolved['file_id']) : MfgDrawing::create([
                'work_order_id' => $wo->id, 'drawing_name' => $revision->drawing->drawing_name, 'version_no' => $revision->version_no,
                'original_name' => $revision->original_name, 'storage_disk' => $revision->storage_disk, 'storage_path' => $revision->storage_path,
                'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size, 'file_hash' => $revision->file_hash, 'status' => 'active',
                'uploaded_by_legacy_id' => $actorId, 'uploaded_by' => $actorName, 'uploaded_at' => now(),
            ]);
            $usage->update(['source_file_id' => $drawing->id]);
            if (! $resolved['file_id']) $drawing->update(['url' => $this->fileUrl($revision->id, $usage->id)]);
            $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->first();
            if ($tech?->status === 'released') $tech->update(['drawings_updated_at' => now()]);
            $wo->increment('business_version');
            app(WorkOrderService::class)->log($wo, 'technical_drawing', null, null, '关联技术图纸 '.$revision->drawing->drawing_no.' V'.$revision->version_no, $actor, ['revision_id' => $revision->id]);
        } elseif ($resolved['type'] === 'opportunity') {
            $opportunity = CrmOpportunity::query()->findOrFail($resolved['id']);
            if (! $resolved['file_id']) {
                $attachment = CrmAttachment::create(['name' => $revision->original_name, 'mime_type' => $revision->mime_type, 'size' => $revision->file_size,
                    'storage_disk' => $revision->storage_disk, 'storage_path' => $revision->storage_path, 'category' => 'solution',
                    'uploaded_by_legacy_id' => $actorId, 'uploaded_by_name' => $actorName]);
                $file = CrmOpportunityFile::create(['opportunity_id' => $opportunity->id, 'attachment_id' => $attachment->id, 'category' => 'solution',
                    'version_no' => (int) $opportunity->files()->where('category', 'solution')->max('version_no') + 1, 'status' => 'pending',
                    'note' => '沿用技术图纸 '.$revision->drawing->drawing_no.' V'.$revision->version_no, 'created_by_legacy_id' => $actorId, 'created_by_name' => $actorName]);
                $attachment->update(['subject_type' => 'opportunity_file', 'subject_id' => $file->id]);
                $usage->update(['source_file_id' => $file->id]);
            }
            $opportunity->increment('version');
            $file = CrmOpportunityFile::query()->find($usage->source_file_id);
            if ($file?->status === 'current') $this->activate($usage);
            app(CrmOpportunityApplicationService::class)->log($opportunity, 'technical_drawing', '关联技术图纸 '.$revision->drawing->drawing_no.' V'.$revision->version_no, null, ['revision_id' => $revision->id], null, $actor, $usage->source_file_id);
        } else {
            $bom = Bom::query()->findOrFail($resolved['id']);
            DB::table('erp_bom_logs')->insert(['bom_id' => $bom->id, 'action' => 'technical_drawing', 'message' => '关联技术图纸 '.$revision->drawing->drawing_no.' V'.$revision->version_no, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function forSource(string $type, int $id): array
    {
        return TechnicalDrawingUsage::query()->with('revision.drawing')->where('source_type', $type)->where('source_id', $id)->where('active', true)->orderBy('id')->get()->map(fn ($usage) => $this->present($usage))->all();
    }

    public function forFile(string $type, int $fileId): ?array
    {
        $usage = TechnicalDrawingUsage::query()->with('revision.drawing')->where('source_type', $type)->where('source_file_id', $fileId)->orderByDesc('active')->orderByDesc('id')->first();
        return $usage ? $this->present($usage) : null;
    }

    private function present(TechnicalDrawingUsage $usage): array
    {
        $revision = $usage->revision;
        return ['usage_id' => $usage->id, 'drawing_id' => $usage->drawing_id, 'revision_id' => $usage->revision_id,
            'drawing_no' => $revision->drawing->drawing_no, 'drawing_name' => $revision->drawing->drawing_name, 'drawing_type' => $revision->drawing->drawing_type,
            'version_no' => $revision->version_no, 'designer_name' => $revision->designer_name, 'completed_date' => $revision->completed_date?->format('Y-m-d'),
            'confirmed_at' => $revision->confirmed_at?->toDateTimeString(), 'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid,
            'original_name' => $revision->original_name, 'mime_type' => $revision->mime_type, 'can_download' => true,
            'download_url' => $this->fileUrl($revision->id, $usage->id), 'url' => $this->fileUrl($revision->id, $usage->id)];
    }

    public function deactivateFile(string $type, int $id, int $fileId): void
    {
        TechnicalDrawingUsage::query()->where('source_type', $type)->where('source_id', $id)->where('source_file_id', $fileId)->update(['active' => false]);
    }

    /** 客户明确选择某个方案时，技术引用才跟随该文件切换，上传待确认方案不抢占当前方案。 */
    public function activateFile(string $type, int $id, int $fileId): void
    {
        foreach (TechnicalDrawingUsage::query()->where('source_type', $type)->where('source_id', $id)->where('source_file_id', $fileId)->get() as $usage) $this->activate($usage);
    }

    private function activate(TechnicalDrawingUsage $usage): void
    {
        $previous = TechnicalDrawingUsage::query()->where('source_type', $usage->source_type)->where('source_id', $usage->source_id)
            ->where('position_uid', $usage->position_uid)->where('drawing_id', $usage->drawing_id)->where('id', '!=', $usage->id)->where('active', true)->get();
        foreach ($previous as $old) {
            $old->update(['active' => false]);
            if ($old->source_type === 'work_order' && $old->source_file_id) MfgDrawing::query()->whereKey($old->source_file_id)->where('status', 'active')->update(['status' => 'replaced']);
        }
        if (! $usage->active) $usage->update(['active' => true]);
    }

    public function copyBom(Bom $source, Bom $target, array $positions, object $actor): void
    {
        foreach (TechnicalDrawingUsage::query()->where('source_type', 'bom')->where('source_id', $source->id)->where('active', true)->get() as $usage) {
            if ($usage->position_uid !== '' && ! isset($positions[$usage->position_uid])) continue;
            $this->copyUsage($usage, 'bom', $target->id, $target->bom_no, $target->bom_name, 0, $positions[$usage->position_uid] ?? '', $actor);
        }
    }

    public function retainBomPositions(Bom $bom, array $positions): void
    {
        TechnicalDrawingUsage::query()->where('source_type', 'bom')->where('source_id', $bom->id)->where('position_uid', '!=', '')
            ->whereNotIn('position_uid', $positions ?: [''])->where('active', true)->update(['active' => false]);
    }

    public function copyWorkOrder(MfgWorkOrder $source, MfgWorkOrder $target, array $files, object $actor): void
    {
        $frozen = [];
        $inheritedIds = array_column((array) data_get($source->bom_snapshot, 'technical_drawings', []), 'usage_id');
        foreach (TechnicalDrawingUsage::query()->where('source_type', 'work_order')->where('source_id', $source->id)->where('active', true)->get() as $usage) {
            if ($usage->source_file_id && ! isset($files[$usage->source_file_id])) continue;
            $copy = $this->copyUsage($usage, 'work_order', $target->id, $target->work_order_no, $target->item_name, $files[$usage->source_file_id] ?? 0, $usage->position_uid, $actor);
            if ($copy->source_file_id) MfgDrawing::query()->whereKey($copy->source_file_id)->update(['url' => $this->fileUrl($copy->revision_id, $copy->id)]);
            if (in_array($usage->id, $inheritedIds, true)) $frozen[] = $this->present($copy);
        }
        if (is_array($target->bom_snapshot)) $target->update(['bom_snapshot' => array_replace($target->bom_snapshot, ['technical_drawings' => $frozen])]);
    }

    /** 只有新建或明确换 BOM 才取主档版本，其余规划继续沿用工单冻结引用。 */
    public function freezeForWorkOrder(MfgWorkOrder $wo, ?array $bom, bool $refresh): ?array
    {
        if (! $bom) return $bom;
        if (! $refresh) {
            $bom['technical_drawings'] = $wo->bom_snapshot['technical_drawings'] ?? [];
            return $bom;
        }
        // 换 BOM 只退下之前从 BOM 继承的图纸，不动技术人员独立上传的加工图。
        Bom::query()->whereKey((int) ($bom['id'] ?? 0))->lockForUpdate()->first();
        foreach ((array) data_get($wo->bom_snapshot, 'technical_drawings', []) as $old) {
            $oldUsage = TechnicalDrawingUsage::query()->whereKey($old['usage_id'] ?? 0)->where('source_type', 'work_order')->where('source_id', $wo->id)->first();
            if (! $oldUsage) continue;
            $oldUsage->update(['active' => false]);
            if ($oldUsage->source_file_id) MfgDrawing::query()->whereKey($oldUsage->source_file_id)->where('status', 'active')->update(['status' => 'replaced']);
        }
        $bom['technical_drawings'] = [];
        $positions = collect((array) ($bom['items'] ?? []))->pluck('position_uid')->filter()->all();
        foreach (TechnicalDrawingUsage::query()->with('revision.drawing')->where('source_type', 'bom')->where('source_id', (int) ($bom['id'] ?? 0))->where('active', true)->lockForUpdate()->get() as $usage) {
            if ($usage->position_uid !== '' && ! in_array($usage->position_uid, $positions, true)) continue;
            $copy = $this->copyUsage($usage, 'work_order', $wo->id, $wo->work_order_no, $wo->item_name, 0, $usage->position_uid, (object) ['legacy_id' => $wo->created_by_legacy_id, 'nickname' => $wo->created_by_name]);
            $drawing = MfgDrawing::query()->whereKey($copy->source_file_id)->first();
            if (! $drawing) {
                $revision = $usage->revision;
                $drawing = MfgDrawing::create(['work_order_id' => $wo->id, 'drawing_name' => $revision->drawing->drawing_name, 'version_no' => $revision->version_no,
                    'original_name' => $revision->original_name, 'storage_disk' => $revision->storage_disk, 'storage_path' => $revision->storage_path,
                    'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size, 'file_hash' => $revision->file_hash, 'status' => 'active',
                    'uploaded_by_legacy_id' => $wo->created_by_legacy_id, 'uploaded_by' => $wo->created_by_name, 'uploaded_at' => now()]);
                $copy->update(['source_file_id' => $drawing->id, 'source_file_key' => 'work_order:'.$wo->id.':'.$drawing->id]);
                $drawing->update(['url' => $this->fileUrl($copy->revision_id, $copy->id)]);
            }
            $copy->update(['active' => true]);
            if ($drawing->status !== 'active') $drawing->update(['status' => 'active']);
            $bom['technical_drawings'][] = $this->present($copy);
        }
        return $bom;
    }

    private function copyUsage(TechnicalDrawingUsage $usage, string $type, int $id, string $no, ?string $name, int $fileId, string $position, object $actor): TechnicalDrawingUsage
    {
        return TechnicalDrawingUsage::query()->firstOrCreate(['revision_id' => $usage->revision_id, 'source_type' => $type, 'source_id' => $id, 'position_uid' => $position],
            ['drawing_id' => $usage->drawing_id, 'source_file_id' => $fileId, 'source_file_key' => $fileId ? $type.':'.$id.':'.$fileId : null,
                'source_no' => $no, 'source_name' => $name, 'active' => true,
                'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', implode('|', [$usage->id, $type, $id, $position])),
                'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => ($actor->nickname ?? null) ?: ($actor->username ?? '')]);
    }

    public function fileUrl(int $revisionId, int $usageId): string
    {
        return '/api/v1/erp/production/technical-drawings/revisions/'.$revisionId.'/download?usage_id='.$usageId;
    }

    private function file(string $disk, string $path, string $name, ?string $mime, int $size, ?string $hash = null): array
    {
        abort_unless(Storage::disk($disk)->exists($path), 404, '图纸文件不存在。');
        return ['storage_disk' => $disk, 'storage_path' => $path, 'original_name' => $name, 'mime_type' => $mime, 'file_size' => $size, 'file_hash' => $hash];
    }

    private function can(object $actor, array $codes): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($actor) || (bool) array_intersect($codes, $auth->permissionCodes($actor));
    }

    private function permission(object $actor, array $codes): void
    {
        abort_unless($this->can($actor, $codes), 403, '无权操作该业务的技术图纸。');
    }
}
