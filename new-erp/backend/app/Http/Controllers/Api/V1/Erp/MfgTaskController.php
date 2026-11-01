<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\MaterialReturnService;
use App\Services\Erp\Manufacturing\ReceiptService;
use App\Services\Erp\Manufacturing\TaskQueryService;
use App\Services\Erp\Manufacturing\TaskService;
use App\Services\Erp\Manufacturing\WorkOrderService;
use App\Models\Erp\MfgWorkOrderOperation;
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
        $lane = $request->validate(['lane' => 'nullable|in:normal,rework'])['lane'] ?? null;
        return response()->json(['data' => $query->task($id, $user, $codes, $lane)]);
    }

    public function action(Request $request, int $id, TaskService $tasks, TaskQueryService $query)
    {
        $input = $request->validate(['action' => 'required|in:claim,start,pause,stop,pass,prepare,reject,collaborators,assign,semi_order',
            'lane' => 'nullable|in:normal,rework']);
        $action = $input['action'];
        $lane = TaskService::lane($input['lane'] ?? null);
        [$user, $codes] = $this->user($request, [in_array($action, ['assign', 'stop'], true) ? 'production.task.assign' : 'production.task.execute']);
        $message = match ($action) {
            'claim' => $this->run(fn () => $tasks->claim($id, $user, $lane), $lane === 'rework' ? '已接下返工。' : '接单成功。'),
            'start' => $this->run(fn () => $tasks->start($id, $user, $lane), $lane === 'rework' ? '返工开始计时（返工工时单独记，不算提成）。' : '开始计时。'),
            'pause' => $this->run(fn () => $tasks->pause($id, $user), '已暂停计时。'),
            'stop' => $this->run(fn () => $tasks->stopTiming($id, (int) $request->validate(['employee_legacy_id' => 'required|integer|min:1'])['employee_legacy_id'], $user), '已停止这个人的计时。'),
            'assign' => $this->run(fn () => $tasks->assign($id, (int) $request->validate(['employee_legacy_id' => 'required|integer|min:1'])['employee_legacy_id'], $user, $lane), '已派工。'),
            'collaborators' => $this->collaborators($request, $id, $tasks, $user, $lane),
            'pass' => $this->pass($request, $id, $tasks, $user, $lane),
            'prepare' => $this->prepare($request, $id, $tasks, $user),
            'reject' => $this->reject($request, $id, $tasks, $user, $lane),
            'semi_order' => $this->semiOrder($request, $id, $user),
        };
        return response()->json(['message' => $message, 'data' => $query->task($id, $user, $codes, $lane)]);
    }

    /** 待我审核（小程序）：报废申请、不良处置。工单生产负责人审自己工单的，有报废确认权限的都能审。 */
    public function reviews(Request $request, TaskQueryService $query)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.scrap.approve']);
        return response()->json(['data' => $query->reviews($user, $this->canApprove($user, $codes))]);
    }

    /** 审核员工提交的不良处置（返工 / 报废 / 判定可用）。 */
    public function reviewPlan(Request $request, int $id, MaterialReturnService $returns)
    {
        [$user, $codes] = $this->user($request, ['production.task.execute', 'production.scrap.approve']);
        $data = $request->validate(['approve' => 'required|boolean', 'remark' => 'nullable|string|max:500']);
        $return = $returns->reviewPlan($id, (bool) $data['approve'], $data['remark'] ?? null, $user, $this->canApprove($user, $codes));
        $plan = $return->disposition_plan ?: [];
        $text = ! $data['approve'] ? '已驳回，料还在原工序。' : match ($plan['action'] ?? '') {
            'rework' => '已通过，返工工单 '.($plan['rework_work_order_no'] ?? '').' 已开，从'.($plan['start_operation_name'] ?? '').'开始返工。',
            'scrap' => '已通过，不良件已报废。',
            default => '已通过，不良件判定可用、回到仓库可用库存。',
        };
        return response()->json(['message' => $text]);
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
        [$user, $codes] = $this->user($request, ['production.scrap.approve', 'production.task.execute']);
        $data = $request->validate(['approve' => 'required|boolean', 'remark' => 'nullable|string|max:500']);
        $tasks->reviewScrap($id, (bool) $data['approve'], $data['remark'] ?? null, $user, $this->canApprove($user, $codes));
        return response()->json(['message' => $data['approve'] ? '已确认报废。' : '已驳回，继续返工。']);
    }

    public function receive(Request $request, int $id, ReceiptService $receipts)
    {
        [$user] = $this->user($request, ['production.receipt.execute']);
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1',
            'warehouse_id' => 'required|integer|exists:erp_warehouses,id', 'location_id' => 'required|integer|exists:erp_locations,id', 'remark' => 'nullable|string|max:500']);
        $receipt = $receipts->receive($id, $data['unit_ids'], (int) $data['warehouse_id'], (int) $data['location_id'], $data['remark'] ?? null, $user);
        return response()->json(['message' => '已入库 '.(int) $receipt->qty.' '.$this->unitWord(null, $id).'（'.$receipt->receipt_no.'）。', 'data' => ['id' => $receipt->id, 'receipt_no' => $receipt->receipt_no]]);
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

    private function collaborators(Request $request, int $id, TaskService $tasks, object $user, string $lane): string
    {
        $data = $request->validate(['collaborators' => 'present|array|max:20', 'collaborators.*.employee_legacy_id' => 'required|integer|min:1',
            'collaborators.*.share_percent' => 'nullable|numeric|min:0|max:100', 'owner_share_percent' => 'nullable|numeric|min:0|max:100']);
        $tasks->collaborators($id, $data['collaborators'], isset($data['owner_share_percent']) ? (float) $data['owner_share_percent'] : null, $user, $lane);
        return '协同人已保存。';
    }

    private function pass(Request $request, int $id, TaskService $tasks, object $user, string $lane): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1'] + $this->completionRules());
        $result = $tasks->pass($id, $data['unit_ids'], $user, $lane, $data);
        $word = $this->unitWord($id);
        $paused = ! empty($result['timing_paused']) ? '本道暂时没有可做的'.($lane === 'rework' ? '返工' : '').'，计时已自动暂停。' : '';
        if (isset($result['completion_action'])) return $this->completionMessage($result, $word).$paused;
        if ($result['next_operation_name']) return $result['passed'].' '.$word.($lane === 'rework' ? '返工' : '').'合格，已交'.$result['next_operation_name'].'。'.$paused;
        if (($result['returned'] ?? 0) > 0) return $result['returned'].' '.$word.'返工合格，已解冻回仓库可用库存，总装那边重新收料就能用。'.$paused;
        if (($result['repaired'] ?? 0) > 0 && $result['repaired'] === $result['passed']) return $result['passed'].' 台设备返修合格，请仓库在发货单上把设备返回成品位后重新试水。'.$paused;
        return $result['passed'].' '.$word.'合格。'.$paused;
    }

    /** 已备料：集中下料提前切好的料，不计时直接交下道。 */
    private function prepare(Request $request, int $id, TaskService $tasks, object $user): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1', 'remark' => 'nullable|string|max:500',
            'cutting_run_id' => 'nullable|integer|min:1'] + $this->completionRules());
        $result = $tasks->prepare($id, $data['unit_ids'], $data['remark'] ?? null, $user, isset($data['cutting_run_id']) ? (int) $data['cutting_run_id'] : null, $data);
        if (isset($result['completion_action'])) return $this->completionMessage($result, $this->unitWord($id));
        if ($result['next_operation_name']) return $result['passed'].' '.$this->unitWord($id).'已备料，交'.$result['next_operation_name'].'。';
        return $result['passed'].' '.$this->unitWord($id).'已备料，生产完成。';
    }

    private function completionRules(): array
    {
        return ['completion_action' => 'nullable|in:store,handoff', 'completion_location_id' => 'nullable|integer|min:1', 'handoff_operation_id' => 'nullable|integer|min:1'];
    }

    private function completionMessage(array $result, string $word): string
    {
        $message = $result['received_qty'].' '.$word.($result['completion_action'] === 'handoff'
            ? '已交接给'.$result['handoff_operation_name'].(! empty($result['shipment_no']) ? '（'.$result['shipment_no'].'）' : '').'。'
            : '已入库（'.$result['receipt_no'].'）。');
        if (($result['repaired'] ?? 0) > 0) $message .= '另有 '.$result['repaired'].' 台原设备返修合格，请仓库在发货单上返回成品位后重新试水。';
        return $message;
    }

    private function reject(Request $request, int $id, TaskService $tasks, object $user, string $lane): string
    {
        $data = $request->validate(['unit_ids' => 'required|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1', 'mode' => 'required|in:return,scrap',
            'return_operation_id' => 'required_if:mode,return|nullable|integer|min:1', 'reason_tags' => 'nullable|array|max:10', 'reason_tags.*' => 'string|max:30',
            'reason' => 'nullable|string|max:500', 'photos' => 'nullable|array|max:9', 'photos.*' => 'string|max:1000']);
        $result = $tasks->reject($id, $data['unit_ids'], $data, $user, $lane);
        $word = $this->unitWord($id);
        $paused = ! empty($result['timing_paused']) ? '本道暂时没有可做的，计时已自动暂停。' : '';
        if ($result['mode'] === 'scrap') return $result['count'].' '.$word.($result['immediate'] ? '已报废（无需审核，直接生效）。' : '已申请报废，等 '.$result['reviewers'].' 审核。').$paused;
        return $result['count'].' '.$word.'已退回'.$result['return_operation_name'].'返工'.($result['rework_owner_name'] ? '，默认交给 '.$result['rework_owner_name'] : '，等人接返工').'。'.$paused;
    }

    /** 领子件那道：任何有报工权限的人都能按缺口给半成品下工单（不能超过缺口）。 */
    private function semiOrder(Request $request, int $id, object $user): string
    {
        $data = $request->validate(['component_item_id' => 'required|integer|min:1', 'qty' => 'required|integer|min:1|max:100000']);
        $woId = (int) MfgWorkOrderOperation::query()->whereKey($id)->value('work_order_id');
        if (! $woId) throw new WorkOrderDomainException('not_found', '工序不存在。', 404);
        $child = app(WorkOrderService::class)->createForParent($woId, (int) $data['component_item_id'], (float) $data['qty'], $user);
        $text = $child->status === 'released' ? '已下达给下料组' : '资料不全，停在计划：'.$child->block_reason;
        return "半成品工单 {$child->work_order_no}（{$child->item_name} ".(int) $data['qty'].($child->unit_name ?: '')."）已开，{$text}。";
    }

    private function canApprove(object $user, array $codes): bool
    {
        return app(AuthContextService::class)->isSuperAdmin($user) || in_array('production.scrap.approve', $codes, true);
    }

    /** 提示语里的量词：整机说“台设备”，电箱、架子等半成品按工单单位说（如“个”）。 */
    private function unitWord(?int $taskId, ?int $workOrderId = null): string
    {
        $wo = \Illuminate\Support\Facades\DB::table('erp_mfg_work_orders as w')->leftJoin('erp_items as i', 'i.id', '=', 'w.item_id')
            ->when($taskId, fn ($q) => $q->join('erp_mfg_work_order_operations as o', 'o.work_order_id', '=', 'w.id')->where('o.id', $taskId), fn ($q) => $q->where('w.id', $workOrderId))
            ->first(['i.item_type', 'w.unit_name']);
        if (! $wo || $wo->item_type === 'finished_product') return '台设备';
        return trim((string) $wo->unit_name) ?: '件';
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
