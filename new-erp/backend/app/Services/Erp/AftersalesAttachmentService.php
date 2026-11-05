<?php

namespace App\Services\Erp;

use App\Models\Erp\AftersalesAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 售后附件：沿用订单附件的 storage_disk/storage_path 落盘方式。
 * 新建售后单/索赔尚无 ID 时先记 draft_token，保存成功后归并。
 */
final class AftersalesAttachmentService
{
    // 图片 + 常见视频类型，与销售订单附件同为 50MB 上限。
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/mpeg', 'application/octet-stream'];
    private const VIDEO_EXTS = ['mp4', 'mov', 'avi', 'webm', 'mpg', 'mpeg', 'wmv', 'mkv'];

    public function upload(UploadedFile $file, array $attributes, object $actor): AftersalesAttachment
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($mime, self::ALLOWED_MIMES, true)
            || ($mime === 'application/octet-stream' && ! in_array($ext, self::VIDEO_EXTS, true))) {
            throw ValidationException::withMessages(['file' => '只允许图片或常见视频格式。']);
        }
        $disk = (string) config('erp.sales_order_attachment_disk', env('ERP_ORDER_ATTACHMENT_DISK', 'oss'));
        // public 磁盘有 Web 直连入口，文件权限不能替代售后单授权。
        if ($disk === 'public') $disk = 'local';
        $directory = trim((string) config('erp.aftersales_attachment_prefix', 'erp/aftersales'), '/').'/'.date('Ymd');
        $storedName = Str::uuid().'.'.($ext ?: $file->extension() ?: 'bin');
        $path = Storage::disk($disk)->putFileAs($directory, $file, $storedName, ['visibility' => 'private']);
        abort_if(!$path, 500, '售后附件上传失败');

        return AftersalesAttachment::create($attributes + [
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'url' => null,
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by_legacy_id' => (int) $actor->legacy_id,
            'uploaded_by' => (string) $actor->name,
            'uploaded_at' => now(),
            'status' => 'active',
        ]);
    }

    /** 草稿附件归并：case_id 由空改到售后单，或 claim 级 draft 改到 claim_id。 */
    public function bindDraft(int $caseId, ?string $draftToken, object $actor, ?int $claimId = null): void
    {
        if (! $draftToken) return;
        AftersalesAttachment::query()
            ->where('draft_token', $draftToken)->where('status', 'active')
            ->where('uploaded_by_legacy_id', (int) $actor->legacy_id)
            ->where('attachment_scope', $claimId ? 'claim' : 'case')
            ->whereNull('claim_id')
            ->where(fn ($q) => $q->whereNull('case_id')->orWhere('case_id', $caseId))
            ->update(['case_id' => $caseId, 'claim_id' => $claimId, 'draft_token' => null, 'updated_at' => now()]);
    }

    public function softDelete(AftersalesAttachment $attachment): void
    {
        $attachment->update(['status' => 'deleted']);
    }
}
