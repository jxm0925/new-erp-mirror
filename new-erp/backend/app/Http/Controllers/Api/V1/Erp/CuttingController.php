<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, CuttingConfirmationService, CuttingInputService, CuttingReadService, CuttingRecordService};
use Illuminate\Http\Request;

final class CuttingController extends Controller
{
    public function index(Request $r, CuttingReadService $s)
    { return response()->json($s->orders($this->filters($r), ...$this->context($r))); }
    public function execution(Request $r, int $id, CuttingReadService $s)
    { return response()->json(['data'=>$s->execution($id,$this->filters($r), ...$this->context($r))]); }
    public function outputs(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->allowedOutputs($id,$this->filters($r), ...$this->context($r))); }
    public function inputs(Request $r, int $id, CuttingReadService $s)
    { return response()->json($s->inputCandidates($id,$this->filters($r), ...$this->context($r))); }
    public function publish(Request $r, CuttingRecordService $s)
    { $this->validateCommand($r,0); return response()->json(['data'=>$s->publish($r->all(), ...$this->context($r))],201); }
    public function registerPhysical(Request $r, CuttingInputService $s)
    { $this->validateCommand($r,0); [$u,$p] = $this->context($r); return response()->json(['data'=>$s->registerPhysical($r->all(),$u,$p)],201); }
    public function reserve(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->reserve($id,$r->all(), ...$this->context($r))]); }
    public function release(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->release($id,$r->all(), ...$this->context($r))]); }
    public function issue(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->issue($id,$r->all(), ...$this->context($r))],201); }
    public function firstCut(Request $r, int $id, CuttingInputService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->markFirstCut($id,$r->all(), ...$this->context($r))]); }
    public function save(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'加工记录草稿已保存','data'=>$s->saveResults($id,$r->all(), ...$this->context($r))]); }
    public function split(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['data'=>$s->splitRoutes($id,$r->all(), ...$this->context($r))]); }
    public function submit(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); $data = $s->submit($id,$r->all(), ...$this->context($r)); return response()->json(['message'=>'加工结果已提交','data'=>$data]); }
    public function inspect(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'质量检验已记录','data'=>$s->inspect($id,$r->all(), ...$this->context($r))]); }
    public function confirm(Request $r, int $id, CuttingConfirmationService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'用料核算已确认','data'=>$s->confirm($id,$r->all(), ...$this->context($r))]); }
    public function returnForEdit(Request $r, int $id, CuttingRecordService $s)
    { $this->validateCommand($r); return response()->json(['message'=>'加工记录已退回修改','data'=>$s->returnForEdit($id,$r->all(), ...$this->context($r))]); }

    private function validateCommand(Request $r, int $minimum = 1): void
    { $r->validate(['client_command_id'=>'required|string|max:120','expected_version'=>'required|integer|min:'.$minimum]); }
    private function filters(Request $r): array
    { return $r->validate(['page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100','keyword'=>'nullable|string|max:100',
        'category_id'=>'nullable|integer|min:1','status'=>'nullable|string|max:24','input_type'=>'nullable|in:physical,quantity',
        'material_form'=>'nullable|in:FULL_STOCK,REMNANT','shape'=>'nullable|in:RECTANGLE,IRREGULAR']); }
    private function context(Request $r): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($r);
        if (! $user) throw new WorkOrderDomainException('unauthenticated','请先登录ERP。',401);
        return [$user,$auth->permissionCodes($user),$auth->isSuperAdmin($user)];
    }
}
