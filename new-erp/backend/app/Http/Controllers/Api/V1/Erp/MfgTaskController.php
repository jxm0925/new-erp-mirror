<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\ReceiptService;
use App\Services\Erp\Manufacturing\TaskQueryService;
use App\Services\Erp\Manufacturing\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** 车间报工（小程序）、生产作业看板、报废确认、成品入库、工序“能做的人”。 */
class MfgTaskController extends Controller
{
    public function index(Request $request, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.task.view']);
        $data = $request->validate(['tab' => 'nullable|in:mine,available,done', 'keyword' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($query->tasks($user, $data['tab'] ?? 'mine', $data, $codes));
    }

    public function show(Request $request, int $id, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.task.view']);
        return response()->json(['data' => $query->task($id, $user, $codes)]);
    }

    public function action(Request $request, int $id, TaskService $tasks, TaskQueryService $query)
    {
        $action = $request->validate(['action' => 'required|in:claim,start,pause,pass,reject,collaborators,assign'])['action'];
        [$user, $codes] = $this->user($request, [$action === 'assign' ? 'production.task.assign' : 'production.task.execute']);
        $message = match ($action) {
            'claim' => $this->run(fn () => $tasks->claim($id, $user), '接单成功。'),
            'start' => $this->run(fn () => $tasks->start($id, $user), '开始计时。'),
            'pause' => $this->run(fn () => $tasks->pause($id, $user), '已暂停计时。'),
            'assign' => $this->run(fn () => $tasks->assign($id, (int) $request->validate(['employee_legacy_id' => 'required|integer|min:1'])['employee_legacy_id'], $user), '已派工。'),
            'collaborators' => $this->collaborators($request, $id, $tasks, $user),
            'pass' => $this->pass($request, $id, $tasks, $user),
            'reject' => $this->reject($request, $id, $tasks, $user),
        };
        return response()->json(['message' => $message, 'data' => $query->task($id, $user, $codes)]);
    }

    public function people(Request $request, int $id, TaskQueryService $query)
    {
        $this->user($request, ['production.task.execute', 'production.task.assign']);
        return response()->json(['data' => $query->people($id, $request->query('keyword'))]);
    }

    public function photo(Request $request)
    {
        $this->user($request, ['production.task.execute']);
        $request->validate(['file' => 'required|image|max:10240']);
        $file = $request->file('file');
        $path = $file->storeAs('erp/production/photos/'.date('Ymd'), Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg'), 'public');
        return response()->json(['data' => ['url' => self::publicUrl($request, $path), 'path' => $path]]);
    }

    public function board(Request $request, TaskQueryService $query)
    {
        [, $codes] = $this->user($request, ['production.board', 'production.task.view']);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'owner_legacy_id' => 'nullable|integer|min:1',
            'urgent_only' => 'nullable|boolean', 'custom_only' => 'nullable|boolean']);
        $data = $query->board($filters);
        $data['permissions'] = ['assign' => in_array('production.task.assign', $codes, true), 'scrap' => in_array('production.scrap.approve', $codes, true),
            'receipt' => in_array('production.receipt.execute', $codes, true)];
        return response()->json(['data' => $data]);
    }

    public function reviewScrap(Request $request, int $id, TaskService $tasks)
    {
        [$user] = $this->user($request, ['production.scrap.approve']);
        $data = $request->validate(['approve' => 'required|boolean', 'remark' => 'nullable|string|max:500']);
        $tasks->reviewScrap($id, (bool) $data['approve'], $data['remark'] ?? null, $user);
        return response()->json(['message' => $data['approve'] ? '已确认报废。' : '已驳回，这台继续返工。']);
    }

    public function receive(Request $request, int $id, ReceiptService $receipts)
    {
        [$user] = $this->user($request, ['production.receipt.execute']);
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1',
            'warehouse_id' => 'required|integer|exists:erp_warehouses,id', 'location_id' => 'required|integer|exists:erp_locations,id', 'remark' => 'nullable|string|max:500']);
        $receipt = $receipts->receive($id, $data['unit_ids'], (int) $data['warehouse_id'], (int) $data['location_id'], $data['remark'] ?? null, $user);
        return response()->json(['message' => '已入库 '.(int) $receipt->qty.' 台（'.$receipt->receipt_no.'）。', 'data' => ['id' => $receipt->id, 'receipt_no' => $receipt->receipt_no]]);
    }

    public function workers(Request $request, int $id, TaskQueryService $query)
    {
        $this->user($request, ['production.operation.view']);
        return response()->json(['data' => $query->operationWorkers($id)]);
    }

    public function saveWorkers(Request $request, int $id, TaskService $tasks)
    {
        [$user] = $this->user($request, ['production.operation.edit']);
        $data = $request->validate(['employee_legacy_ids' => 'present|array|max:200', 'employee_legacy_ids.*' => 'integer|min:1']);
        return response()->json(['message' => '能做的人已保存。', 'data' => $tasks->saveWorkers($id, $data['employee_legacy_ids'], $user)]);
    }

    public static function publicUrl(Request $request, string $path): string
    {
        $url = Storage::disk('public')->url($path);
        return preg_match('#^https?://#i', $url)
            ? preg_replace('#^https?://[^/]+#i', $request->getSchemeAndHttpHost(), $url)
            : rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($url, '/');
    }

    private function collaborators(Request $request, int $id, TaskService $tasks, object $user): string
    {
        $data = $request->validate(['collaborators' => 'present|array|max:20', 'collaborators.*.employee_legacy_id' => 'required|integer|min:1',
            'collaborators.*.share_percent' => 'nullable|numeric|min:0|max:100', 'owner_share_percent' => 'nullable|numeric|min:0|max:100']);
        $tasks->collaborators($id, $data['collaborators'], isset($data['owner_share_percent']) ? (float) $data['owner_share_percent'] : null, $user);
        return '协同人已保存。';
    }

    private function pass(Request $request, int $id, TaskService $tasks, object $user): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1']);
        $result = $tasks->pass($id, $data['unit_ids'], $user);
        if ($result['next_operation_name']) return $result['passed'].' 台合格，已交'.$result['next_operation_name'].'。';
        return $result['passed'].' 台合格，生产完成，'.($result['staged_qty'] ? '已自动记入成品待发区（'.$result['staged_receipt_no'].'），等发货试水。' : '等入库。');
    }

    private function reject(Request $request, int $id, TaskService $tasks, object $user): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1', 'mode' => 'required|in:return,scrap',
            'return_operation_id' => 'required_if:mode,return|nullable|integer|min:1', 'reason_tags' => 'nullable|array|max:10', 'reason_tags.*' => 'string|max:30',
            'reason' => 'nullable|string|max:500', 'photos' => 'nullable|array|max:9', 'photos.*' => 'string|max:1000']);
        $result = $tasks->reject($id, $data['unit_ids'], $data, $user);
        return $result['mode'] === 'scrap' ? $result['count'].' 台已申请报废，等主管确认。' : $result['count'].' 台已退回'.$result['return_operation_name'].'。';
    }

    private function run(\Closure $fn, string $message): string
    {
        $fn();
        return $message;
    }

    /** @return array{0: object, 1: array} 当前用户、权限编码（满足任一所需权限即可） */
    private function user(Request $request, array $anyOf): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        if (! $auth->isSuperAdmin($user) && ! array_intersect($anyOf, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有这项生产权限。', 403);
        return [$user, $codes];
    }
}
