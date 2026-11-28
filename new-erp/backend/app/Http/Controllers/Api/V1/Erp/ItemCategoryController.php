<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\ItemCategory;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CategoryTreeEngine;
use App\Services\Erp\ItemCategoryApplicationService;
use App\Services\Erp\ItemManagementScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'item_category.view');
        $scope = app(ItemManagementScopeService::class)->requestScope($request);
        $all = $this->allCategories($scope);
        $tree = new CategoryTreeEngine($all);
        $query = ItemCategory::query()->where('category_type', 'item');
        app(ItemManagementScopeService::class)->applyScope($query, $scope);
        if ($request->boolean('root_only')) $query->whereNull('parent_id');
        elseif ($request->filled('parent_id')) $query->where('parent_id', $request->integer('parent_id'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn (Builder $q) => $q->where('category_code', 'like', "%{$keyword}%")
                ->orWhere('category_name', 'like', "%{$keyword}%"));
        }

        $paginator = $query->orderBy('sort_order')->orderBy('id')
            ->paginate(min(100, max(5, $request->integer('per_page', 10))));
        $paginator->setCollection($paginator->getCollection()->map(fn (ItemCategory $category) => $tree->node((int) $category->id)));

        return response()->json($paginator);
    }

    public function tree(Request $request)
    {
        $this->authorizePermission($request, 'item_category.view');
        $all = $this->allCategories(app(ItemManagementScopeService::class)->requestScope($request));
        return response()->json(['data' => (new CategoryTreeEngine($all))->tree()]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'item_category.view');
        $all = $this->allCategories(app(ItemManagementScopeService::class)->requestScope($request));
        $category = (new CategoryTreeEngine($all))->node($id);
        abort_unless($category, 404);

        return response()->json(['data' => $category]);
    }

    public function store(Request $request, ItemCategoryApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'item_category.manage');
        $scope = app(ItemManagementScopeService::class)->requestScope($request);
        $data = $request->validate($this->rules());
        if ($scope !== null) {
            abort_if(isset($data['management_scope']) && $data['management_scope'] !== $scope, 422, '类目管理范围与当前入口不一致。');
            $data['management_scope'] = $scope;
        }
        $category = $service->create(
            $data,
            (int) $user->legacy_id
        );

        return response()->json(['message' => 'Item 类目已保存。', 'data' => $category], 201);
    }

    public function update(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($category, app(ItemManagementScopeService::class)->requestScope($request));
        $updated = $service->update($category, $request->validate($this->rules($category)));

        return response()->json(['message' => 'Item 类目已保存。', 'data' => $updated]);
    }

    public function disable(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($category, app(ItemManagementScopeService::class)->requestScope($request));

        return response()->json(['message' => 'Item 类目已停用，历史关联保持不变。', 'data' => $service->disable($category)]);
    }

    public function enable(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($category, app(ItemManagementScopeService::class)->requestScope($request));

        return response()->json(['message' => 'Item 类目已启用。', 'data' => $service->enable($category)]);
    }

    public function destroy(Request $request, int $id, ItemCategoryApplicationService $service)
    {
        $this->authorizePermission($request, 'item_category.manage');
        $category = ItemCategory::where('category_type', 'item')->findOrFail($id);
        app(ItemManagementScopeService::class)->assertContext($category, app(ItemManagementScopeService::class)->requestScope($request));
        $service->deleteUnused($category);

        return response()->json(['message' => 'Item 类目已删除']);
    }

    private function rules(?ItemCategory $category = null): array
    {
        return [
            'category_code' => $category
                ? ['required', 'string', 'max:60', Rule::in([(string) $category->category_code])]
                : ['nullable', 'string', 'max:60', Rule::unique('erp_item_categories', 'category_code')],
            'category_name' => 'required|string|max:120',
            'management_scope' => 'sometimes|required|in:factory,office',
            'parent_id' => 'nullable|integer|exists:erp_item_categories,id',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'required|in:enabled,disabled',
            'remark' => 'nullable|string|max:1000',
            'reservation_token' => $category ? 'prohibited' : 'required|uuid',
            'creation_session_id' => $category ? 'prohibited' : 'required|uuid',
        ];
    }

    private function allCategories(?string $scope = null)
    {
        return app(ItemManagementScopeService::class)->applyScope(ItemCategory::where('category_type', 'item'), $scope)
            ->withCount([
                'items as direct_item_count' => fn ($query) => $query->whereColumn('erp_items.management_scope', 'erp_item_categories.management_scope'),
                'supplierCapabilities as direct_supplier_count' => fn ($query) => $query->where('status', 'active'),
                'children as direct_child_count' => fn ($query) => $query->where('category_type', 'item'),
            ])->orderBy('sort_order')->orderBy('id')->get();
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
