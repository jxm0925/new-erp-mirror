<?php

namespace App\Services\Erp;

use App\Models\Erp\PurchaseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseAttachmentApplicationService
{
    public function upload(UploadedFile $file, array $attributes, ?object $user = null): PurchaseAttachment
    {
        $disk = (string) config('erp.purchase_attachment_disk', env('ERP_PURCHASE_ATTACHMENT_DISK', 'oss'));
        $directory = trim((string) config('erp.purchase_attachment_prefix', 'erp/purchase-attachment'), '/').'/'.date('Ymd');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = Str::uuid().'.'.$extension;
        $path = Storage::disk($disk)->putFileAs($directory, $file, $storedName, ['visibility' => 'public']);
        abort_if(!$path, 500, '采购附件上传失败');

        try {
            return DB::transaction(fn () => PurchaseAttachment::create([
                ...$attributes,
                'original_name' => $file->getClientOriginalName(),
                'stored_name' => $storedName,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'url' => Storage::disk($disk)->url($path),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by_legacy_id' => $user->legacy_id ?? null,
                'uploaded_by' => $user ? ($user->nickname ?: $user->username) : null,
                'uploaded_at' => now(),
                'status' => 'active',
            ]));
        } catch (\Throwable $error) {
            Storage::disk($disk)->delete($path);
            throw $error;
        }
    }

    public function bindDraft(string $documentType, int $documentId, ?string $draftToken): void
    {
        if (!$draftToken) return;

        DB::transaction(fn () => PurchaseAttachment::query()
            ->where('draft_token', $draftToken)
            ->where('document_type', $documentType)
            ->whereNull('document_id')
            ->where('status', 'active')
            ->update(['document_id' => $documentId, 'updated_at' => now()]));
    }

    public function softDelete(PurchaseAttachment $attachment, string $operator): void
    {
        DB::transaction(fn () => $attachment->update([
            'status' => 'deleted',
            'deleted_by' => $operator,
            'deleted_at' => now(),
        ]));
    }
}
