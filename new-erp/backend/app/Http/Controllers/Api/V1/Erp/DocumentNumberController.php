<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\DocumentNumberRule;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\DocumentNumberRuleService;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Http\Request;

class DocumentNumberController extends Controller
{
    public function rules(Request $request, DocumentNumberRuleService $service)
    {
        $this->authorizePermission($request, 'document_number_rule.view');
        return response()->json($service->paginate($request->only(['document_type', 'keyword', 'enabled', 'per_page'])));
    }

    public function ruleTypes(Request $request, DocumentNumberRuleService $service)
    {
        $this->authorizePermission($request, 'document_number_rule.view');
        $configured = DocumentNumberRule::query()
            ->pluck('document_type')
            ->flip();

        return response()->json([
            'data' => array_map(
                static fn (array $type): array => $type + [
                    'configured' => $configured->has($type['code']),
                ],
                $service->businessTypes()
            ),
        ]);
    }

    public function previewRule(Request $request, DocumentNumberRuleService $service)
    {
        $this->authorizePermission($request, 'document_number_rule.view');
        return response()->json(['data' => $service->preview($this->validatedRule($request, false))]);
    }

    public function storeRule(Request $request, DocumentNumberRuleService $service)
    {
        $user = $this->authorizePermission($request, 'document_number_rule.manage');
        return response()->json([
            'message' => '编号规则已新增，仅影响后续新生成编号。',
            'data' => $service->create($this->validatedRule($request, false), $user),
        ], 201);
    }

    public function updateRule(Request $request, int $id, DocumentNumberRuleService $service)
    {
        $user = $this->authorizePermission($request, 'document_number_rule.manage');
        return response()->json([
            'message' => '编号规则已保存，仅影响后续新生成编号。',
            'data' => $service->update(DocumentNumberRule::findOrFail($id), $this->validatedRule($request, true), $user),
        ]);
    }

    public function enableRule(Request $request, int $id, DocumentNumberRuleService $service)
    {
        $user = $this->authorizePermission($request, 'document_number_rule.manage');
        return response()->json([
            'message' => '编号规则已启用。',
            'data' => $service->setEnabled(DocumentNumberRule::findOrFail($id), true, $user),
        ]);
    }

    public function disableRule(Request $request, int $id, DocumentNumberRuleService $service)
    {
        $user = $this->authorizePermission($request, 'document_number_rule.manage');
        return response()->json([
            'message' => '编号规则已停用，历史编号和已有预留不受影响。',
            'data' => $service->setEnabled(DocumentNumberRule::findOrFail($id), false, $user),
        ]);
    }

    public function reserve(Request $request, DocumentNumberService $service)
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validate([
            'document_type' => 'required|string|max:60',
            'creation_session_id' => 'required|uuid',
            'page' => 'nullable|string|max:160',
        ]);
        abort_unless(
            DocumentNumberRule::where('document_type', $data['document_type'])->where('enabled', true)->exists(),
            422,
            '该业务未配置或未启用编号规则。'
        );
        return response()->json(['data' => $service->reserve(
            $data['document_type'], $data['creation_session_id'], (int) $user->legacy_id, $data['page'] ?? null
        )]);
    }

    /** Internal diagnostics only: deliberately has no frontend route or menu. */
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'document_number.manage');
        $query = DocumentNumberReservation::query()->latest('created_at');
        foreach (['document_type', 'status', 'reserved_by_legacy_id'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('document_no', 'like', "%{$keyword}%")
                ->orWhere('creation_session_id', 'like', "%{$keyword}%"));
        }
        return response()->json($query->paginate(min(100, max(5, $request->integer('per_page', 20)))));
    }

    /** Internal scheduled/diagnostic action; not exposed in the ERP UI. */
    public function expire(Request $request, DocumentNumberService $service)
    {
        $this->authorizePermission($request, 'document_number.manage');
        return response()->json(['message' => '过期预留编号已自动标记为作废。', 'expired_count' => $service->expire()]);
    }

    private function authenticatedUser(Request $request): object
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        return $user;
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $this->authenticatedUser($request);
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无编号规则操作权限。');
        return $user;
    }

    private function validatedRule(Request $request, bool $editing): array
    {
        return $request->validate([
            'document_type' => ($editing ? 'sometimes' : 'required').'|string|max:60',
            'name' => 'required|string|max:120',
            'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9-]*$/'],
            'date_format' => 'required|in:none,YYYY,YYYYMM,YYYYMMDD,Y,Ym,Ymd',
            'sequence_length' => 'required|integer|min:1|max:12',
            'reset_cycle' => 'required|in:none,daily,monthly,yearly',
            'enabled' => 'required|boolean',
            'change_reason' => 'nullable|string|max:200',
        ]);
    }
}
