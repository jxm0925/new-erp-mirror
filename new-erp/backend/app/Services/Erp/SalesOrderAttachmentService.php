<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrderAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SalesOrderAttachmentService
{
    public function upload(UploadedFile $file, array $attributes, ?object $user = null): SalesOrderAttachment
    {
        $disk = (string) config('erp.sales_order_attachment_disk', env('ERP_ORDER_ATTACHMENT_DISK', 'oss'));
        $directory = trim((string) config('erp.sales_order_attachment_prefix', 'erp/sales-order'), '/').'/'.date('Ymd');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = Str::uuid().'.'.$extension;
        $path = Storage::disk($disk)->putFileAs($directory, $file, $storedName, ['visibility' => 'public']);
        abort_if(!$path, 500, '订单附件上传失败');

        $version = 1;
        if (!empty($attributes['replaced_attachment_id'])) {
            $previous = SalesOrderAttachment::findOrFail($attributes['replaced_attachment_id']);
            $version = (int) $previous->version_no + 1;
            $previous->update(['status' => 'replaced']);
        }

        return SalesOrderAttachment::create([
            ...$attributes,
            'version_no' => $version,
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
        ]);
    }

    public function softDelete(SalesOrderAttachment $attachment, string $operator): void
    {
        $attachment->update([
            'status' => 'deleted',
            'deleted_by' => $operator,
            'deleted_at' => now(),
        ]);
    }

    public function bindOrderDraft(int $orderId, ?string $draftToken): void
    {
        if (!$draftToken) return;
        SalesOrderAttachment::query()
            ->where('draft_token', $draftToken)
            ->where('attachment_scope', 'order')
            ->whereNull('sales_order_id')
            ->where('status', 'active')
            ->update(['sales_order_id' => $orderId, 'updated_at' => now()]);
    }

    public function bindLineDraft(int $orderId, int $lineId, ?string $draftToken, ?string $lineUuid): void
    {
        if (!$draftToken || !$lineUuid) return;
        SalesOrderAttachment::query()
            ->where('draft_token', $draftToken)
            ->where('line_uuid', $lineUuid)
            ->whereNull('sales_order_line_id')
            ->where('status', 'active')
            ->update([
                'sales_order_id' => $orderId,
                'sales_order_line_id' => $lineId,
                'updated_at' => now(),
            ]);
    }
}
