<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\ApprovalFormApplicationService;
use App\Services\Erp\AuthContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalFormController extends Controller
{
    public function index(Request $request, ApprovalFormApplicationService $service)
    {
        $this->context($request, 'approval.form.view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'enabled', 'disabled'])],
            'business_module' => ['nullable', 'string', 'max:80'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($service->paginate($filters));
    }

    public function show(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        $this->context($request, 'approval.form.view');
        return response()->json(['data' => $service->show($id)]);
    }

    public function summary(Request $request, ApprovalFormApplicationService $service)
    {
        $this->context($request, 'approval.form.view');
        return response()->json(['data' => $service->summary()]);
    }

    public function store(Request $request, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.edit');
        return response()->json(['message' => '自定义表单草稿已保存。', 'data' => $service->saveDraft(null, $this->payload($request), $this->operator($user))], 201);
    }

    public function update(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.edit');
        return response()->json(['message' => '自定义表单草稿已保存。', 'data' => $service->saveDraft($id, $this->payload($request, $id), $this->operator($user))]);
    }

    public function publish(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.publish');
        $payload = $request->validate(['schema' => ['nullable', 'array']]);
        return response()->json(['message' => '自定义表单新版本已发布。', 'data' => $service->publish($id, $payload, $this->operator($user))]);
    }

    public function toggle(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.toggle');
        $payload = $request->validate(['enabled' => ['required', 'boolean']]);
        return response()->json(['message' => $payload['enabled'] ? '自定义表单已启用。' : '自定义表单已停用。', 'data' => $service->toggle($id, $payload['enabled'], $this->operator($user))]);
    }

    public function destroy(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        $this->context($request, 'approval.form.edit');
        $service->delete($id);
        return response()->json(['message' => '自定义表单草稿已删除。']);
    }

    public function copy(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.edit');
        return response()->json(['message' => '自定义表单已复制为新草稿。', 'data' => $service->copy($id, $this->operator($user))], 201);
    }

    public function validateSchema(Request $request, ApprovalFormApplicationService $service)
    {
        $this->context($request, 'approval.form.view');
        $payload = $request->validate(['schema' => ['required', 'array']]);
        return response()->json(['data' => $service->validateSchema($payload['schema'])]);
    }

    public function submit(Request $request, int $id, ApprovalFormApplicationService $service)
    {
        [$user] = $this->context($request, 'approval.form.submit');
        $payload = $request->validate([
            'subject' => ['nullable', 'string', 'max:240'],
            'form_data' => ['required', 'array'],
        ]);
        return response()->json(['message' => '表单申请已提交审核。', 'data' => $service->submit($id, $payload, $user)], 201);
    }

    private function payload(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'form_code' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]+$/', Rule::unique('erp_approval_form_templates', 'form_code')->ignore($id)],
            'form_name' => ['required', 'string', 'max:160'],
            'business_module' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'schema' => ['required', 'array'],
        ]);
    }

    private function context(Request $request, string $permission): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        $codes = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        abort_unless($super || in_array($permission, $codes, true), 403, '无按钮权限：'.$permission);
        return [$user, $codes, $super];
    }

    private function operator(object $user): string
    {
        return (string) ($user->nickname ?: $user->username ?: '系统');
    }
}
