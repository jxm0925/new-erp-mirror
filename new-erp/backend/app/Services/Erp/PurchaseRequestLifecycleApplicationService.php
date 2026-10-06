<?php

namespace App\Services\Erp;

use App\Models\Erp\{PurchaseLog, PurchaseRequest};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseRequestLifecycleApplicationService
{
    public function query(): Builder
    {
        // Both header and line references exist in historical documents. Either one protects
        // the original request, even if a stale quantity/status no longer reports an allocation.
        return PurchaseRequest::query()->withExists(['planItems', 'planItemsViaLines', 'orderItems', 'orderItemsViaLines'])
            ->with('items');
    }

    public function present(PurchaseRequest $request): PurchaseRequest
    {
        $allowed = $this->canChange($request);
        $request->setAttribute('can_edit', $allowed);
        $request->setAttribute('can_delete', $allowed);
        return $request;
    }

    public function canChange(PurchaseRequest $request): bool
    {
        return !$request->trashed()
            && in_array($request->request_status, ['draft', 'confirmed', 'closed', 'cancelled'], true)
            && (float) $request->planned_qty <= 0
            && !$request->plan_items_exists && !$request->plan_items_via_lines_exists
            && !$request->order_items_exists && !$request->order_items_via_lines_exists
            && !$request->items->contains(fn ($line) => (float) $line->converted_qty > 0);
    }

    /** Called inside the edit transaction after acquiring the same parent lock as to-plan/delete. */
    public function prepareEdit(PurchaseRequest $request, ?string $operator): void
    {
        $this->assertCanChange($request);
        if ($request->request_status !== 'draft') {
            PurchaseLog::create([
                'target_type' => 'purchase_request', 'target_id' => $request->id,
                'action' => 'reopen_for_edit', 'operator' => $operator ?: '系统任务',
                'content' => "编辑未转计划的采购需求，原状态 {$request->request_status}；保存后回到草稿，须重新确认。",
            ]);
        }
        $request->fill(['request_status' => 'draft', 'status' => 'draft',
            'confirmed_by' => null, 'confirmed_at' => null, 'closed_at' => null, 'cancelled_at' => null]);
    }

    public function softDelete(int $id, ?string $operator, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($id, $operator, $operatorId) {
            $request = $this->query()->withTrashed()->lockForUpdate()->findOrFail($id);
            // Retrying the same delete must preserve its original actor/time and audit event.
            if ($request->trashed()) return;
            $this->assertCanChange($request);
            $request->fill(['deleted_by' => $operator ?: '系统任务', 'deleted_by_legacy_id' => $operatorId])->save();
            $request->delete();
            PurchaseLog::create([
                'target_type' => 'purchase_request', 'target_id' => $id, 'action' => 'soft_delete',
                'operator' => $operator ?: '系统任务', 'content' => '软删除未转计划的采购需求，保留单据明细及历史记录。',
            ]);
        }, 5);
    }

    private function assertCanChange(PurchaseRequest $request): void
    {
        if (!$this->canChange($request)) {
            throw ValidationException::withMessages(['request' => '该需求已转计划或存在下游占用，不能编辑或删除。']);
        }
    }
}
