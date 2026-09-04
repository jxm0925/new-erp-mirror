<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\ItemCategory;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ItemCategoryApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'item_category.view');
        $all = $this->allCategories();
        $query = ItemCategory::query()->where('category_type', 'item');
        if ($request->boolean('root_only')) $query->whereNull('parent_id');
        elseif ($request->filled('parent_id')) $query->where('parent_id', $request->integer('parent_id'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn (Builder $q) => $q->where('category_code', 'like', "%{$keyword}%")
                ->orWhere('category_name', 'like', "%{$keyword}%"));
        }

        $paginator = $query->orderBy('sort_order')->orderBy('id')
            ->paginate(min(100, max(5, $request->integer('per_page', 20))));
        $paginator->getCollection()->transform(fn (ItemCategory $category) => $this->serialize($category, $all));

        return response()->json($paginator);
    }

    public function tree(Request $request)
    {
        $this->authorizePermission($request, 'item_category.view');
        $all = $this->allCategories();
        $nodes = $all->sortBy([['sort_order', 'asc'], ['id', 'asc']]);
        $build = function (?int $parentId) use (&$build, $nodes, $all) {
            return $nodes->filter(fn ($row) => (int) ($row->parent_id ?? 0) === (int) ($parentId ?? 0))
                ->map(function (ItemCategory $category) use (&$build, $all) {
                    $row = $this->serialize($category, $all);
                    $row['children'] = $build((int) $category->id)->values()->all();
                    return $row;
                })->values();
        };

        return response()->json(['data' => $build(null)->values()]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'item_category.view');
        $all = $this->allCategories();
        $category = $all->firstWhere('id', $id);
        abort_unless($category, 404);

        return response()->json(['data' => $this->serialize($category, $all)]);
    }

    public function store(Request $request, ItemCategoryApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'item_category.manage');
        $category = $service->create(
            $request->validate($this->rules()),
            (int) $user->legacy_id
        );

        return response()->json(['message' => 'Item 类目已保存。', 'data' => $category], 201);
    }

    public function update(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);
        $updated = $service->update($category, $request->validate($this->rules($category)));

        return response()->json(['message' => 'Item 类目已保存。', 'data' => $updated]);
    }

    public function disable(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);

        return response()->json(['message' => 'Item 类目已停用，历史关联保持不变。', 'data' => $service->disable($category)]);
    }

    public function enable(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);

        return response()->json(['message' => 'Item 类目已启用。', 'data' => $service->enable($category)]);
    }

    public function destroy(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $service->deleteUnused(ItemCategory::where('category_type', 'item')->findOrFail($id));

        return response()->json(['message' => 'Item 类目已删除']);
    }

    private function rules(?ItemCategory $category = null): array
    {
        return [
            'category_code' => $category
                ? ['required', 'string', 'max:60', Rule::in([(string) $category->category_code])]
                : ['nullable', 'string', 'max:60', Rule::unique('erp_item_categories', 'category_code')],
            'category_name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:erp_item_categories,id',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'required|in:enabled,disabled',
            'remark' => 'nullable|string|max:1000',
            'reservation_token' => $category ? 'prohibited' : 'required|uuid',
            'creation_session_id' => $category ? 'prohibited' : 'required|uuid',
        ];
    }

    private function allCategories()
    {
        return ItemCategory::where('category_type', 'item')
            ->withCount([
                'items as direct_item_count',
                'supplierCapabilities as direct_supplier_count' => fn ($query) => $query->where('status', 'active'),
                'children as direct_child_count' => fn ($query) => $query->where('category_type', 'item'),
            ])->get();
    }

    private function serialize(ItemCategory $category, $all): array
    {
        $descendantIds = $this->descendantIds((int) $category->id, $all);
        $subtreeItemCount = \App\Models\Erp\Item::whereIn('category_id', array_merge([(int) $category->id], $descendantIds))->count();

        return array_merge($category->toArray(), [
            'full_path' => $this->fullPath($category, $all),
            'is_leaf' => (int) $category->direct_child_count === 0,
            'selectable' => $category->status === 'enabled' && (int) $category->direct_child_count === 0,
            'subtree_item_count' => $subtreeItemCount,
        ]);
    }

    private function descendantIds(int $rootId, $all): array
    {
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

    private function fullPath(ItemCategory $category, $all): string
    {
        $names = [$category->category_name];
        $parentId = $category->parent_id;
        $guard = [];
        while ($parentId && !in_array((int) $parentId, $guard, true)) {
            $guard[] = (int) $parentId;
            $parent = $all->firstWhere('id', (int) $parentId);
            if (!$parent) break;
            array_unshift($names, $parent->category_name);
            $parentId = $parent->parent_id;
        }
        return implode(' / ', $names);
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permission);
        return $user;
    }
}
