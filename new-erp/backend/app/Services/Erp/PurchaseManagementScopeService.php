<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\{PurchaseOrder, PurchasePlan, PurchasePlanItem, PurchaseRequest, PurchaseRequestItem};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseManagementScopeService
{
    public function assertScope(mixed $value, string $field = 'management_scope'): string
    {
        if (!is_string($value) || !in_array($value, ['factory', 'office'], true)) {
            throw ValidationException::withMessages([$field => '请选择工厂物料或办公用品，管理范围不能为空。']);
        }
        return $value;
    }

    public function requestScope(Request $request): ?string
    {
        return array_key_exists('management_scope', $request->query())
            ? $this->assertScope($request->query('management_scope')) : null;
    }

    public function applyFilter(Builder|QueryBuilder $query, ?string $scope, string $column = 'management_scope'): void
    {
        if ($scope !== null) {
            $query->where($query instanceof Builder ? $query->getModel()->qualifyColumn($column) : $column,
                $this->assertScope($scope));
        }
    }

    /**
     * All positive business callers execute this inside their existing transaction.
     * Missing headers from older clients are inferred only from every actual Item;
     * neither a picker filter nor a material name is proof of document ownership.
     * A null historical header may be corrected in a draft edit, but it cannot be
     * inherited by a new downstream document or advanced by assertDocumentScope.
     */
    public function resolveDocumentScope(
        iterable $lines,
        array $payload = [],
        ?Model $existing = null,
        ?Model $source = null,
        ?string $requiredScope = null,
    ): string {
        $this->assertTransaction();
        $constraints = [];
        if (array_key_exists('management_scope', $payload)) $constraints[] = $this->assertScope($payload['management_scope']);
        if ($existing && $existing->management_scope !== null) $constraints[] = $this->assertScope($existing->management_scope);
        if ($source) $constraints[] = $this->assertDocumentScope($source);
        if ($requiredScope !== null) $constraints[] = $this->assertScope($requiredScope);
        $rows = collect($lines)->values();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['items' => '必须有可确定管理范围的物料明细。']);
        }
        $ids = $rows->map(fn ($line) => (int) data_get($line, 'item_id', 0));
        if ($ids->contains(fn ($id) => $id < 1)) {
            throw ValidationException::withMessages(['items' => '物料明细缺少有效物料，不能确定管理范围。']);
        }
        // A consistent ID order prevents two documents containing the same Items
        // from acquiring their material locks in opposite order.
        $items = Item::query()->whereIn('id', $ids->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($rows as $index => $line) {
            $item = $items->get((int) data_get($line, 'item_id'));
            if (!$item) throw ValidationException::withMessages(['items.'.$index.'.item_id' => '物料不存在，不能确定管理范围。']);
            $constraints[] = $this->assertScope($item->management_scope, 'items.'.$index.'.item_id');
        }
        $scopes = array_values(array_unique($constraints));
        if (count($scopes) !== 1) {
            throw ValidationException::withMessages(['management_scope' => '办公用品和工厂物料不能混在同一张采购单据中；请分开建立单据，并保持来源单据范围一致。']);
        }
        return $scopes[0];
    }

    public function assertItemScope(Item|int $item, string $scope, string $field = 'items'): Item
    {
        $this->assertTransaction();
        $scope = $this->assertScope($scope);
        $locked = Item::query()->whereKey($item instanceof Item ? $item->id : $item)->lockForUpdate()->first();
        if (!$locked || $locked->management_scope !== $scope) {
            throw ValidationException::withMessages([$field => '物料管理范围与采购单据不一致，办公用品和工厂物料必须分开。']);
        }
        return $locked;
    }

    public function assertDocumentScope(Model $document): string
    {
        $this->assertTransaction();
        $scope = $document->management_scope;
        if (!is_string($scope) || !in_array($scope, ['factory', 'office'], true)) {
            throw ValidationException::withMessages(['management_scope' => '历史单据的管理范围未确定或存在混合明细，只能查看或纠错；请分开重建后再办理后续采购业务。']);
        }
        $lines = method_exists($document, 'items') ? $document->items()->orderBy('id')->lockForUpdate()->get() : [];
        if (count($lines) === 0 && $document->item_id) $lines = [['item_id' => $document->item_id]];
        $resolved = $this->resolveDocumentScope($lines, ['management_scope' => $scope], requiredScope:
            $document instanceof PurchaseRequest ? $this->requiredScopeForSource($document->source_type) : null);
        // Every linked source remains authoritative when a plan or order advances.
        // The head alone cannot hide a historical mixed source or a changed source.
        if ($document instanceof PurchasePlan || $document instanceof PurchaseOrder) {
            $sourceLineIds = collect($lines)->pluck('request_item_id')->filter()->unique()->sort()->values();
            $sourceLines = PurchaseRequestItem::query()->whereIn('id', $sourceLineIds)->orderBy('id')->lockForUpdate()->get();
            if ($sourceLines->count() !== $sourceLineIds->count()) {
                throw ValidationException::withMessages(['items' => '来源采购需求明细已失效，不能继续办理后续采购。']);
            }
            foreach (collect($lines)->pluck('request_id')->merge($sourceLines->pluck('request_id'))->filter()->unique()->sort() as $id) {
                $source = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
                if ($this->assertDocumentScope($source) !== $resolved) {
                    throw ValidationException::withMessages(['management_scope' => '采购需求与后续单据管理范围不一致。']);
                }
            }
        }
        if ($document instanceof PurchaseOrder) {
            $sourceLineIds = collect($lines)->pluck('plan_item_id')->filter()->unique()->sort()->values();
            $sourceLines = PurchasePlanItem::query()->whereIn('id', $sourceLineIds)->orderBy('id')->lockForUpdate()->get();
            if ($sourceLines->count() !== $sourceLineIds->count()) {
                throw ValidationException::withMessages(['items' => '来源采购计划明细已失效，不能继续办理后续采购。']);
            }
            foreach (collect($lines)->pluck('plan_id')->merge($sourceLines->pluck('plan_id'))->push($document->plan_id)->filter()->unique()->sort() as $id) {
                $source = PurchasePlan::query()->lockForUpdate()->findOrFail($id);
                if ($this->assertDocumentScope($source) !== $resolved) {
                    throw ValidationException::withMessages(['management_scope' => '采购计划与采购订单管理范围不一致。']);
                }
            }
        }
        return $resolved;
    }

    public function requiredScopeForSource(?string $sourceType): ?string
    {
        return $sourceType !== null && (str_starts_with($sourceType, 'production')
            || str_starts_with($sourceType, 'sales') || str_starts_with($sourceType, 'work_order')) ? 'factory' : null;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Purchase scope validation requires the caller business transaction.');
    }
}
