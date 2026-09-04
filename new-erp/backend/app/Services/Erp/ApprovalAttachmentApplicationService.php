<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprovalAttachmentApplicationService
{
    public function upload(int $taskId, UploadedFile $file, object $user): ApprovalTaskAttachment
    {
        $task = ApprovalTask::query()->with('nodes')->findOrFail($taskId);
        if ($task->task_status !== 'PENDING') throw ValidationException::withMessages(['task' => '已结束的审核任务不能继续上传附件。']);
        $node = $task->nodes->firstWhere('node_status', 'PENDING');
        $disk = (string) config('erp.approval_attachment_disk', env('ERP_APPROVAL_ATTACHMENT_DISK', 'oss'));
        $directory = 'erp/approval/'.date('Ymd');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = Storage::disk($disk)->putFileAs($directory, $file, Str::uuid().'.'.$extension, ['visibility' => 'public']);
        abort_if(!$path, 500, '审核附件上传失败。');
        try {
            return DB::transaction(fn () => ApprovalTaskAttachment::create([
                'approval_task_id' => $task->id, 'approval_task_node_id' => $node?->id,
                'original_name' => $file->getClientOriginalName(), 'storage_disk' => $disk, 'storage_path' => $path,
                'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $user->legacy_id, 'uploaded_by_name' => $user->nickname ?: $user->username,
                'uploaded_at' => now(),
            ]));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); throw $e;
        }
    }

    public function delete(ApprovalTaskAttachment $attachment, object $user, bool $isSuperAdmin): void
    {
        if (!$isSuperAdmin && (int) $attachment->uploaded_by !== (int) $user->legacy_id) {
            throw ValidationException::withMessages(['attachment' => '只能删除本人上传的审核附件。']);
        }
        DB::transaction(function () use ($attachment) {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
            $attachment->delete();
        });
    }
}
