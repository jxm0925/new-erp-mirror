<?php

namespace App\Services\Erp;

use App\Models\Erp\{Bom, BomLog};
use Illuminate\Support\Facades\DB;

class BomLifecycleApplicationService
{
    public function softDelete(int $id, ?int $operatorId): void
    {
        DB::transaction(function () use ($id, $operatorId): void {
            $bom = Bom::withTrashed()->lockForUpdate()->findOrFail($id);
            // 重复删除不覆盖首次删除时间，也不重复生成审计记录。
            if ($bom->trashed()) return;

            abort_if($bom->is_default || ($bom->audit_status === 'pending' && $bom->submitted_at), 422, '默认 BOM 或审批中的 BOM 不能删除。');
            abort_unless(
                $bom->status === 'inactive'
                || ($bom->status === 'draft' && $bom->audit_status === 'pending' && $bom->submitted_at === null),
                422,
                '只有停用 BOM 或从未提交审核的 BOM 草稿可以移入回收站。'
            );

            // 仅软删主记录，明细和下游引用保留原 ID；不能级联清理历史事实。
            $bom->update(['updated_by' => $operatorId]);
            $bom->delete();
            BomLog::create(['bom_id' => $id, 'action' => 'soft_delete', 'message' => 'BOM 已移入回收站，保留明细与历史引用。', 'created_by' => $operatorId]);
        }, 5);
    }

    public function restore(int $id, ?int $operatorId): Bom
    {
        return DB::transaction(function () use ($id, $operatorId): Bom {
            $bom = Bom::withTrashed()->lockForUpdate()->findOrFail($id);
            if (! $bom->trashed()) return $bom;

            // 明细的 deleted_at 表示编辑时被替换，恢复主记录绝不能复活旧版本明细。
            $bom->updated_by = $operatorId;
            $bom->restore();
            BomLog::create(['bom_id' => $id, 'action' => 'restore', 'message' => '从回收站恢复 BOM，保留原状态。', 'created_by' => $operatorId]);
            return $bom;
        }, 5);
    }
}
