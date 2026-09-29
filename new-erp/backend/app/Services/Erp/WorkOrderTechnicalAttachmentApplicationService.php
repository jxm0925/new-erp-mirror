<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\WorkOrder;
use App\Models\Erp\WorkOrderTechnicalAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** 文件不可覆写；从新版本移除只改变版本引用，历史图纸仍可追溯。 */
final class WorkOrderTechnicalAttachmentApplicationService
{
    public function upload(int $id, UploadedFile $file, array $payload, object $user, array $permissions, bool $superAdmin = false): WorkOrderTechnicalAttachment
    {
        if (! $superAdmin && ! in_array('production.technical.prepare', $permissions, true)) {
            throw new WorkOrderDomainException('forbidden', '无权维护工单生产资料。', 403);
        }
        Validator::make($payload + ['file' => $file], [
            'client_command_id' => ['required', 'string', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:20480', 'extensions:pdf,png,jpg,jpeg,gif,webp,dwg,dxf,step,stp,igs,iges'],
        ])->validate();
        $actor = (int) ($user->legacy_id ?? $user->id);
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
        if ($name === '' || mb_strlen($name) > 255) throw new WorkOrderDomainException('invalid_filename', '附件名称不能为空且不能超过255字。', 422);
        $hash = hash_file('sha256', $file->getRealPath());
        $requestHash = hash('sha256', json_encode([$id, $actor, $name, $hash, (int) $payload['expected_version']]));
        $path = null;
        $disk = (string) config('erp.work_order_technical_attachment_disk', 'local');
        try {
            return DB::transaction(function () use ($id, $file, $payload, $user, $permissions, $superAdmin, $actor, $name, $hash, $requestHash, &$path, $disk) {
                WorkOrder::query()->lockForUpdate()->findOrFail($id);
                $workOrder = app(WorkOrderApplicationService::class)->showWorkOrder($id, $user, $permissions, $superAdmin);
                $existing = WorkOrderTechnicalAttachment::where('client_command_id', $payload['client_command_id'])->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) throw new WorkOrderDomainException('command_conflict', '上传请求标识已被其他附件使用。', 409);
                    return $existing;
                }
                if (! in_array($workOrder->status, ['DRAFT', 'WAIT_RELEASE'], true)) throw new WorkOrderDomainException('invalid_state', '工单发布后不能新增技术附件。', 422);
                if ((int) $workOrder->business_version !== (int) $payload['expected_version']) throw new WorkOrderDomainException('version_conflict', '工单已更新，请刷新后重新上传。', 409);
                // 上传与版本确认分开：未确认文件只对上传者可见；锁工单避免发布同时进入。
                $path = Storage::disk($disk)->putFileAs('erp/work-order-technical/'.$id, $file, Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), ['visibility' => 'private']);
                if (! $path) throw new WorkOrderDomainException('upload_failed', '附件上传失败，请重试。', 500);
                return WorkOrderTechnicalAttachment::create([
                    'work_order_id' => $id, 'client_command_id' => $payload['client_command_id'], 'request_hash' => $requestHash,
                    'original_name' => $name, 'storage_disk' => $disk, 'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize(), 'file_hash' => $hash,
                    'uploaded_by_legacy_id' => $actor, 'uploaded_by' => $user->nickname ?: $user->username,
                ]);
            });
        } catch (\Throwable $error) {
            // 数据库未记录成功的文件不能留成可引用附件；重试始终使用原命令号。
            if ($path) Storage::disk($disk)->delete($path);
            throw $error;
        }
    }

    public function snapshot(WorkOrder $workOrder, array $ids, object $user): array
    {
        Validator::make(['ids' => $ids], ['ids' => ['array', 'max:20'], 'ids.*' => ['integer', 'min:1', 'distinct']])->validate();
        $rows = WorkOrderTechnicalAttachment::where('work_order_id', $workOrder->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        if ($rows->count() !== count($ids)) throw new WorkOrderDomainException('attachment_scope_invalid', '附件不属于当前工单或已不存在。', 422);
        return array_map(function ($id) use ($rows, $user): array {
            $row = $rows->get($id);
            if (! $row->confirmed_at && (int) $row->uploaded_by_legacy_id !== (int) ($user->legacy_id ?? $user->id)) throw new WorkOrderDomainException('attachment_scope_invalid', '不能引用其他人员尚未确认的附件。', 403);
            if (! $row->confirmed_at) $row->forceFill(['confirmed_at' => now()])->save();
            return $this->metadata($row);
        }, $ids);
    }

    public function read(int $workOrderId, int $attachmentId, object $user, array $permissions, bool $superAdmin = false): WorkOrderTechnicalAttachment
    {
        app(WorkOrderApplicationService::class)->showWorkOrder($workOrderId, $user, $permissions, $superAdmin);
        $row = WorkOrderTechnicalAttachment::where('work_order_id', $workOrderId)->findOrFail($attachmentId);
        if (! $row->confirmed_at && ((int) $row->uploaded_by_legacy_id !== (int) ($user->legacy_id ?? $user->id)
            || (! $superAdmin && ! in_array('production.technical.prepare', $permissions, true)))) {
            throw new WorkOrderDomainException('forbidden', '无权查看尚未确认的技术附件。', 403);
        }
        return $row;
    }

    public function metadata(WorkOrderTechnicalAttachment $row): array
    {
        return ['id' => $row->id, 'original_name' => $row->original_name, 'file_size' => $row->file_size,
            'mime_type' => $row->mime_type, 'file_hash' => $row->file_hash,
            'uploaded_by' => $row->uploaded_by, 'uploaded_at' => $row->created_at->toISOString(),
            'previewable' => in_array($row->mime_type, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)];
    }
}
