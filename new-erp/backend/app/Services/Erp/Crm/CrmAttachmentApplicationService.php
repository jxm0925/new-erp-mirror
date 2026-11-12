<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmAttachment;
use App\Models\Erp\CrmFollowup;
use App\Models\Erp\CrmOpportunityFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** CRM 附件保存在私有磁盘，下载始终重新检查所属业务对象范围。 */
final class CrmAttachmentApplicationService
{
    public function upload(UploadedFile $file, object $actor): CrmAttachment
    {
        $path = Storage::disk('local')->putFileAs('crm/'.now()->format('Ym'), $file, Str::uuid().'.'.$file->extension());
        abort_unless($path, 500, '附件保存失败，请重试。');
        try {
            return CrmAttachment::create(['name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(), 'storage_disk' => 'local', 'storage_path' => $path,
                'uploaded_by_legacy_id' => $actor->legacy_id, 'uploaded_by_name' => ($actor->nickname ?? null) ?: ($actor->username ?? null)]);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function bind(array $ids, string $type, int $subjectId, object $actor, string $category = 'communication'): void
    {
        foreach (array_unique($ids) as $id) {
            $file = CrmAttachment::query()->lockForUpdate()->findOrFail($id);
            if ($file->subject_type === $type && (int) $file->subject_id === $subjectId) {
                abort_unless($file->category === $category, 422, '沟通附件与分配附件不能互相替换。');
                $file->update(['is_active' => true, 'removed_at' => null, 'removed_by_legacy_id' => null]);
                continue;
            }
            abort_unless(!$file->subject_type && (int) $file->uploaded_by_legacy_id === (int) $actor->legacy_id, 403, '不能引用其他业务或其他人员的附件。');
            $file->update(['subject_type' => $type, 'subject_id' => $subjectId, 'category' => $category]);
        }
        // 移除仅停用关联，保留文件和原归属供修改记录追溯；不同动作的附件不能一起移除。
        CrmAttachment::query()->where('subject_type', $type)->where('subject_id', $subjectId)->where('category', $category)
            ->where('is_active', true)->whereNotIn('id', $ids ?: [0])->update(['is_active' => false, 'removed_at' => now(), 'removed_by_legacy_id' => $actor->legacy_id]);
    }

    public function ids(string $type, int $subjectId, string $category = 'communication'): array
    {
        return CrmAttachment::query()->where('subject_type', $type)->where('subject_id', $subjectId)->where('category', $category)
            ->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function download(int $id, object $actor)
    {
        $file = CrmAttachment::query()->findOrFail($id);
        if (!$file->subject_type) {
            abort_unless((int) $file->uploaded_by_legacy_id === (int) $actor->legacy_id, 403, '无权下载该附件。');
        } elseif ($file->subject_type === 'lead') {
            app(CrmLeadApplicationService::class)->permission($actor, 'crm.lead.view');
            app(CrmLeadApplicationService::class)->visible((int) $file->subject_id, $actor);
        } elseif ($file->subject_type === 'followup') {
            $followup = CrmFollowup::query()->findOrFail($file->subject_id);
            app(CrmFollowupApplicationService::class)->subject($followup->subject_type, $followup->subject_id, $actor);
        } elseif ($file->subject_type === 'opportunity_file') {
            app(CrmLeadApplicationService::class)->permission($actor, 'crm.opportunity.view');
            $version = CrmOpportunityFile::query()->findOrFail($file->subject_id);
            abort_unless((int) $version->attachment_id === (int) $file->id, 403, '附件归属无效。');
            app(CrmOpportunityApplicationService::class)->visible((int) $version->opportunity_id, $actor);
        } else {
            abort(403, '附件归属无效。');
        }
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404, '附件文件不存在。');
        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->name, ['Content-Type' => $file->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function present(CrmAttachment $file): array
    {
        return $file->only(['id', 'name', 'mime_type', 'size', 'category', 'uploaded_by_name', 'created_at'])
            + ['download_url' => '/api/v1/erp/crm/attachments/'.$file->id.'/download'];
    }
}
