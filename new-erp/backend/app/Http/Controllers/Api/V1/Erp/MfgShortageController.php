<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\ShortageService;
use Illuminate\Http\Request;

/** 生产缺料：查看（purchase.production_shortage），转采购需求（purchase.production_shortage.convert）。 */
class MfgShortageController extends Controller
{
    private const VIEW = 'purchase.production_shortage';
    private const CONVERT = 'purchase.production_shortage.convert';

    public function index(Request $request, ShortageService $shortages)
    {
        [, $canConvert] = $this->user($request, [self::VIEW, self::CONVERT]);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'work_order_id' => 'nullable|integer|min:1',
            'only_short' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $filters['only_short'] = $request->has('only_short') ? $request->boolean('only_short') : true;
        return response()->json($shortages->paginate($filters) + ['can_convert' => $canConvert]);
    }

    public function store(Request $request, ShortageService $shortages)
    {
        [$user] = $this->user($request, self::CONVERT);
        $data = $request->validate(['lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|min:1',
            'lines.*.qty' => 'nullable|numeric|min:0.0001|max:100000000|decimal:0,4', 'lines.*.purchase_unit_id' => 'nullable|integer|min:1', 'lines.*.purchase_qty' => 'nullable|numeric|min:0.0001|max:100000000|decimal:0,4', 'work_order_id' => 'nullable|integer|min:1',
            'remark' => 'nullable|string|max:500']);
        $record = $shortages->createRequest($data['lines'], isset($data['work_order_id']) ? (int) $data['work_order_id'] : null, $data['remark'] ?? null, $user);
        return response()->json(['message' => "已生成采购需求 {$record->request_no}（草稿），请采购确认后转计划。",
            'data' => ['id' => $record->id, 'request_no' => $record->request_no]], 201);
    }

    /** @return array{0: object, 1: bool} 当前用户、能否转采购 */
    private function user(Request $request, string|array $permission): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $super = $auth->isSuperAdmin($user);
        $codes = $auth->permissionCodes($user);
        if (! $super && ! array_intersect((array) $permission, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有生产缺料权限。', 403);
        return [$user, $super || in_array(self::CONVERT, $codes, true)];
    }
}
