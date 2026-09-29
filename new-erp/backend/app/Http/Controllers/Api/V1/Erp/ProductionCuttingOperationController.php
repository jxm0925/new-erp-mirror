<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, ProductionCuttingOperationService};
use Illuminate\Http\Request;

final class ProductionCuttingOperationController extends Controller
{
    public function show(Request $r, int $taskId, string $targetType, int $targetId, ProductionCuttingOperationService $service)
    {
        $filters = $r->validate(['settlement_batch_id'=>'nullable|integer|min:1','batch_page'=>'nullable|integer|min:1']);
        return response()->json(['data'=>$service->view($taskId,$targetType,$targetId,$filters,...$this->context($r))]);
    }

    public function materials(Request $r, int $taskId, string $targetType, int $targetId, ProductionCuttingOperationService $service)
    {
        $filters = $r->validate(['mode'=>'nullable|in:categories','keyword'=>'nullable|string|max:160','category_id'=>'nullable|integer|min:1','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:20']);
        return response()->json($service->materials($taskId,$targetType,$targetId,$filters,...$this->context($r)));
    }

    public function prepare(Request $r, int $taskId, string $targetType, int $targetId, ProductionCuttingOperationService $service)
    {
        return response()->json(['data'=>$service->prepare($taskId,$targetType,$targetId,$this->payload($r),...$this->context($r))]);
    }

    public function useMaterial(Request $r, int $taskId, string $targetType, int $targetId, ProductionCuttingOperationService $service)
    {
        $payload = $this->payload($r,['production_input_holding_id'=>'required|integer|min:1','physical_material_id'=>'nullable|integer|min:1','quantity'=>'nullable|numeric|gt:0']);
        return response()->json(['data'=>$service->useMaterial($taskId,$targetType,$targetId,$payload,...$this->context($r))]);
    }

    public function save(Request $r, int $taskId, string $targetType, int $targetId, int $batchId, ProductionCuttingOperationService $service)
    {
        $payload = $this->payload($r,['actual_qty'=>'required|numeric|gte:0','other_results'=>'nullable|array|max:99','other_results.*'=>'array']);
        return response()->json(['data'=>$service->save($taskId,$targetType,$targetId,$batchId,$payload,...$this->context($r))]);
    }

    public function finish(Request $r, int $taskId, string $targetType, int $targetId, ProductionCuttingOperationService $service)
    {
        return response()->json(['data'=>$service->finish($taskId,$targetType,$targetId,$this->payload($r,['disposition'=>'nullable|in:direct_handover,warehouse']),...$this->context($r))]);
    }

    private function payload(Request $r, array $extra = []): array
    {
        return $r->validate(['client_command_id'=>'required|string|max:120','expected_version'=>'required|integer|min:1']+$extra);
    }

    private function context(Request $r): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($r);
        if (! $user) throw new WorkOrderDomainException('unauthenticated','请先登录ERP。',401);
        return [$user,$auth->permissionCodes($user),$auth->isSuperAdmin($user)];
    }
}
