<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\TechService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 定制技术：定制工单的设计图、定制 BOM、下发车间。 */
class MfgTechController extends Controller
{
    private const DRAWING_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'dwg', 'dxf', 'step', 'stp', 'zip'];

    public function index(Request $request, TechService $tech)
    {
        $this->user($request, ['production.tech.manage', 'production.work_order.view']);
        $filters = $request->validate(['status' => 'nullable|in:pending,released', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($tech->paginate($filters));
    }

    public function show(Request $request, int $id, TechService $tech)
    {
        [, $canManage] = $this->user($request, ['production.tech.manage', 'production.work_order.view']);
        $data = $tech->detail($id);
        $data['can_manage'] = $canManage;
        return response()->json(['data' => $data]);
    }

    public function saveBom(Request $request, int $id, TechService $tech)
    {
        [$user] = $this->user($request, ['production.tech.manage']);
        $data = $request->validate(['lines' => 'required|array|min:1|max:300', 'lines.*.component_item_id' => 'required|integer|min:1',
            'lines.*.base_line_id' => 'nullable|integer|min:1', 'lines.*.qty' => 'required|numeric|min:0', 'lines.*.loss_rate' => 'nullable|numeric|min:0|max:99.99',
            'lines.*.fixed_qty' => 'nullable|numeric|min:0', 'lines.*.target_routing_operation_id' => 'nullable|integer|min:1', 'lines.*.remark' => 'nullable|string|max:200',
            'confirmed' => 'nullable|boolean', 'remark' => 'nullable|string|max:500']);
        $tech->saveBom($id, $data['lines'], (bool) ($data['confirmed'] ?? false), $data['remark'] ?? null, $user);
        return response()->json(['message' => ! empty($data['confirmed']) ? '定制 BOM 已确认。' : '定制 BOM 已保存。', 'data' => $tech->detail($id)]);
    }

    public function upload(Request $request, int $id, TechService $tech)
    {
        [$user] = $this->user($request, ['production.tech.manage']);
        $data = $request->validate(['file' => 'required|file|max:51200', 'drawing_name' => 'nullable|string|max:200', 'replace_drawing_id' => 'nullable|integer|min:1']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::DRAWING_EXT, true)) throw new WorkOrderDomainException('file_type', '图纸只支持 PDF、图片、DWG/DXF、STEP 或 ZIP。');
        $path = $file->storeAs('erp/production/drawings/'.date('Ymd'), Str::uuid().'.'.$ext, 'public');
        $drawing = $tech->uploadDrawing($id, [
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'storage_path' => $path, 'url' => MfgTaskController::publicUrl($request, $path),
            'mime_type' => $file->getMimeType(), 'file_size' => (int) $file->getSize(), 'file_hash' => hash_file('sha256', $file->getRealPath()) ?: null,
        ], $data['drawing_name'] ?? null, isset($data['replace_drawing_id']) ? (int) $data['replace_drawing_id'] : null, $user);
        return response()->json(['message' => '图纸已上传（V'.$drawing->version_no.'）。', 'data' => $tech->detail($id)]);
    }

    public function deleteDrawing(Request $request, int $drawingId, TechService $tech)
    {
        [$user] = $this->user($request, ['production.tech.manage']);
        $tech->deleteDrawing($drawingId, $user);
        return response()->json(['message' => '图纸已删除。']);
    }

    public function release(Request $request, int $id, TechService $tech)
    {
        [$user] = $this->user($request, ['production.tech.manage']);
        $remark = $request->validate(['remark' => 'nullable|string|max:500'])['remark'] ?? null;
        $tech->release($id, $remark, $user);
        return response()->json(['message' => '已下发车间，工单已下达、已按台生成序列号。', 'data' => $tech->detail($id)]);
    }

    /** @return array{0: object, 1: bool} 当前用户、能否编辑 */
    private function user(Request $request, array $anyOf): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        if (! $super && ! array_intersect($anyOf, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有定制技术权限。', 403);
        return [$user, $super || in_array('production.tech.manage', $codes, true)];
    }
}
