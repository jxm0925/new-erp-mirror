<?php

namespace App\Services\Erp;

use App\Models\Erp\ItemCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemCategoryApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function create(array $data, ?int $operatorLegacyId = null): ItemCategory
    {
        try {
            return DB::transaction(function () use ($data, $operatorLegacyId) {
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
                        'category_code' => 'Item类目编号不能为空，请重新打开新增页面获取系统编号。',
                    ]);
                }

                if (ItemCategory::where('category_code', $data['category_code'])->exists()) {
                    throw ValidationException::withMessages([
                        'category_code' => 'Item类目编号已被占用，请刷新系统编号后重新确认保存。',
                    ]);
                }

                $data['category_type'] = 'item';
                $this->assertParent($data['parent_id'] ?? null);
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
                    'category_code' => 'Item类目编号发生并发冲突，请刷新系统编号后重新确认保存。',
                ]);
            }
            throw $exception;
        }
    }

    public function update(ItemCategory $category, array $data): ItemCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $locked = ItemCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('category_code', $data)
                && $data['category_code'] !== $locked->category_code) {
                throw ValidationException::withMessages([
                    'category_code' => 'Item类目编码创建后不可修改。',
                ]);
            }
            unset($data['category_code'], $data['reservation_token'], $data['creation_session_id']);
            $data['category_type'] = 'item';
            $parentId = $data['parent_id'] ?? null;
            $this->assertParent($parentId, $locked->id);
            abort_if($parentId && in_array($parentId, $this->descendantIds($locked->id), true), 422, '父级类目不能选择当前类目的下级。');
            $locked->update($data);

            return $locked->fresh('parent');
        });
    }

    public function disable(ItemCategory $category): ItemCategory
    {
        return DB::transaction(function () use ($category) {
            $locked = ItemCategory::whereKey($category->id)->where('category_type', 'item')->lockForUpdate()->firstOrFail();
            abort_if($locked->children()->where('category_type', 'item')->where('status', 'enabled')->exists(), 422, '当前类目下仍有启用的子类目，请先停用子类目。');
            $locked->update(['status' => 'disabled']);

            return $locked->fresh('parent');
        });
    }

    public function enable(ItemCategory $category): ItemCategory
    {
        return DB::transaction(function () use ($category) {
            $locked = ItemCategory::whereKey($category->id)->where('category_type', 'item')->lockForUpdate()->firstOrFail();
            $parentId = $locked->parent_id;
            while ($parentId) {
                $parent = ItemCategory::whereKey($parentId)->firstOrFail();
                abort_if($parent->status !== 'enabled', 422, '上级类目未启用，不能启用当前类目。');
                $parentId = $parent->parent_id;
            }
            $locked->update(['status' => 'enabled']);

            return $locked->fresh('parent');
        });
    }

    public function deleteUnused(ItemCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $locked = ItemCategory::whereKey($category->id)->where('category_type', 'item')->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'enabled', 422, '启用状态的 Item 类目不能删除，请先停用。');
            abort_if($locked->children()->where('category_type', 'item')->exists(), 422, '该类目下仍有子类目，不能删除。');
            abort_if($locked->items()->exists(), 422, '该类目已被 Item 引用，只能停用，不能删除。');
            abort_if($locked->supplierCapabilities()->exists(), 422, '该类目已被供应商可供范围引用，只能停用，不能删除。');
            $locked->delete();
        });
    }

    private function assertParent(?int $parentId, ?int $selfId = null): void
    {
        if (!$parentId) return;
        abort_if($selfId && $parentId === $selfId, 422, '类目不能选择自己作为父级。');
        $parent = ItemCategory::whereKey($parentId)->where('category_type', 'item')->first();
        abort_unless($parent, 422, '父级必须是有效的 Item 类目。');
    }

    private function descendantIds(int $rootId): array
    {
        $all = ItemCategory::where('category_type', 'item')->get(['id', 'parent_id']);
        $result = [];
        $frontier = [$rootId];
        while ($frontier) {
            $children = $all->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (!$children) break;
            $result = array_merge($result, $children);
            $frontier = $children;
        }

        return array_values(array_unique($result));
    }
}
