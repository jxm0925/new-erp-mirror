<?php

namespace App\Services\Erp;

use App\Models\Erp\ItemCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemCategoryApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly MasterDataApplicationService $masterData,
    )
    {
    }

    public function create(array $data, ?int $operatorLegacyId = null): ItemCategory
    {
        try {
            return DB::transaction(function () use ($data, $operatorLegacyId) {
                $all = $this->lockedCategories();
                $reservationToken = $data['reservation_token'] ?? null;
                $creationSessionId = $data['creation_session_id'] ?? null;
                unset($data['reservation_token'], $data['creation_session_id']);

                if ($reservationToken) {
                    $reservedCode = $this->numbers->reservedNumber(
                        $reservationToken,
                        'item_category',
                        $operatorLegacyId,
                        $creationSessionId
                    );
                    if (!empty($data['category_code']) && $data['category_code'] !== $reservedCode) {
                        throw ValidationException::withMessages([
                            'category_code' => '页面显示编号与预占编号不一致，请刷新编号后重新确认保存。',
                        ]);
                    }
                    $data['category_code'] = $reservedCode;
                }

                if (empty($data['category_code'])) {
                    throw ValidationException::withMessages([
                        'category_code' => '物料类目编号不能为空，请重新打开新增页面获取系统编号。',
                    ]);
                }

                if (ItemCategory::where('category_code', $data['category_code'])->exists()) {
                    throw ValidationException::withMessages([
                        'category_code' => '物料类目编号已被占用，请刷新系统编号后重新确认保存。',
                    ]);
                }

                $data['category_type'] = 'item';
                $this->assertParent($data['parent_id'] ?? null, null, $all);
                $data = app(ItemManagementScopeService::class)->prepareCategory($data);
                $this->assertStatus(null, $data['status'] ?? 'enabled', $data['parent_id'] ?? null, $all);
                $category = ItemCategory::create($data);

                if ($reservationToken) {
                    $this->numbers->consume(
                        $reservationToken,
                        'item_category',
                        (string) $category->category_code,
                        $operatorLegacyId,
                        'item_category',
                        (int) $category->getKey()
                    );
                }

                return $category->fresh('parent');
            }, 5);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000'
                || str_contains(strtolower($exception->getMessage()), 'category_code')) {
                throw ValidationException::withMessages([
                    'category_code' => '物料类目编号发生并发冲突，请刷新系统编号后重新确认保存。',
                ]);
            }
            throw $exception;
        }
    }

    public function update(ItemCategory $category, array $data): ItemCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $all = $this->lockedCategories();
            $locked = $all->find($category->id);
            abort_unless($locked, 404);
            if (array_key_exists('category_code', $data)
                && $data['category_code'] !== $locked->category_code) {
                throw ValidationException::withMessages([
                    'category_code' => '物料类目编码创建后不可修改。',
                ]);
            }
            unset($data['category_code'], $data['reservation_token'], $data['creation_session_id']);
            $data['category_type'] = 'item';
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $locked->parent_id;
            $this->assertParent($parentId, $locked->id, $all);
            $data = app(ItemManagementScopeService::class)->prepareCategory($data, $locked);
            $this->assertStatus($locked, $data['status'] ?? $locked->status, $parentId, $all);
            $locked->update($data);

            return $locked->fresh('parent');
        });
    }

    public function disable(ItemCategory $category): ItemCategory
    {
        return DB::transaction(function () use ($category) {
            $all = $this->lockedCategories();
            $locked = $all->find($category->id);
            abort_unless($locked, 404);
            $this->assertStatus($locked, 'disabled', $locked->parent_id, $all);
            $locked->update(['status' => 'disabled']);

            return $locked->fresh('parent');
        });
    }

    public function enable(ItemCategory $category): ItemCategory
    {
        return DB::transaction(function () use ($category) {
            $all = $this->lockedCategories();
            $locked = $all->find($category->id);
            abort_unless($locked, 404);
            $this->assertStatus($locked, 'enabled', $locked->parent_id, $all);
            $locked->update(['status' => 'enabled']);

            return $locked->fresh('parent');
        });
    }

    public function deleteUnused(ItemCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $locked = $this->lockedCategories()->find($category->id);
            abort_unless($locked, 404);
            abort_if($locked->status === 'enabled', 422, '启用状态的 Item 类目不能删除，请先停用。');
            abort_if($locked->children()->where('category_type', 'item')->exists(), 422, '该类目下仍有子类目，不能删除。');
            abort_if($locked->items()->exists(), 422, '该类目已被 Item 引用，只能停用，不能删除。');
            abort_if($locked->supplierCapabilities()->exists(), 422, '该类目已被供应商可供范围引用，只能停用，不能删除。');
            // 与通用主数据入口共用最终引用保护，涵盖产品和后续模块的外键，
            // 防止通过 Item 类目专用路由绕过历史关系保护。
            $this->masterData->deleteUnused('item_categories', $locked);
        });
    }

    private function lockedCategories()
    {
        // Serialize hierarchy changes in a stable lock order so simultaneous moves cannot form a cycle.
        return ItemCategory::where('category_type', 'item')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertParent(?int $parentId, ?int $selfId, $all): void
    {
        if (!$parentId) return;
        abort_if($selfId && $parentId === $selfId, 422, '类目不能选择自己作为父级。');
        $parent = $all->find($parentId);
        abort_unless($parent, 422, '父级必须是有效的 Item 类目。');
        abort_if($selfId && (new CategoryTreeEngine($all))->containsDescendant($selfId, $parentId), 422, '父级类目不能选择当前类目的下级。');
    }

    private function assertStatus(?ItemCategory $category, string $status, ?int $parentId, $all): void
    {
        if ($status === 'disabled') {
            abort_if($category && $all->contains(fn ($row) => (int) $row->parent_id === (int) $category->id && $row->status === 'enabled'),
                422, '当前类目下仍有启用的子类目，请先停用子类目。');
            return;
        }
        $parents = $all->keyBy('id');
        $visited = [];
        while ($parentId) {
            abort_if(isset($visited[$parentId]), 422, '上级类目存在循环，请先调整父子关系。');
            $visited[$parentId] = true;
            $parent = $parents->get($parentId);
            abort_unless($parent && $parent->status === 'enabled', 422, '上级类目未启用，不能启用当前类目。');
            $parentId = $parent->parent_id;
        }
    }

    public function assertEnabledAncestors(ItemCategory $category): void
    {
        $all = ItemCategory::where('category_type', 'item')->get(['id', 'parent_id', 'status']);
        $current = $all->find($category->id);
        abort_unless($current, 404);
        $this->assertStatus($current, 'enabled', $current->parent_id, $all);
    }

    public function descendantIds(int $rootId): array
    {
        $all = ItemCategory::where('category_type', 'item')->get(['id', 'parent_id', 'category_type', 'management_scope']);
        return (new CategoryTreeEngine($all))->descendantIds($rootId);
    }
}
