<?php

namespace App\Services\Erp;

use App\Models\Erp\FinanceAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceAttachmentApplicationService
{
    public function upload(UploadedFile $file, string $documentType, int $documentId, string $attachmentType, ?int $operatorId): FinanceAttachment
    {
        $disk = 'oss';
        $directory = 'erp/finance-attachment/'.date('Ymd');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = Storage::disk($disk)->putFileAs($directory, $file, Str::uuid().'.'.$extension, ['visibility' => 'public']);
        abort_if(!$path, 500, '财务附件上传失败');
        try {
            return FinanceAttachment::create([
                'document_type' => $documentType, 'document_id' => $documentId, 'attachment_type' => $attachmentType,
                'original_name' => $file->getClientOriginalName(), 'storage_disk' => $disk, 'storage_path' => $path,
                'url' => Storage::disk($disk)->url($path), 'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(), 'uploaded_by' => $operatorId, 'uploaded_at' => now(), 'status' => 'active',
            ]);
        } catch (\Throwable $error) {
            Storage::disk($disk)->delete($path);
            throw $error;
        }
    }
}
