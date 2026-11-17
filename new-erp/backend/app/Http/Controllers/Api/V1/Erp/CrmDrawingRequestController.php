<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmDrawingRequestService;
use Illuminate\Http\Request;

final class CrmDrawingRequestController extends Controller
{
    public function __construct(private readonly CrmDrawingRequestService $service) {}

    public function metadata(Request $request)
    {
        return response()->json(['data' => $this->service->metadata($this->actor($request), $request->validate($this->subjectRules()))]);
    }

    public function index(Request $request)
    {
        $data = $request->validate($this->selectorRules() + $this->subjectRules() + ['tab' => 'nullable|in:mine', 'grouped' => 'nullable|boolean', 'status' => 'nullable|in:todo,pending,changes_requested,delivered,confirmed']);
        return response()->json($this->service->paginate($data, $this->actor($request)));
    }

    public function technicians(Request $request)
    {
        return response()->json($this->service->technicians($request->validate($this->selectorRules()), $this->actor($request)));
    }

    public function references(Request $request)
    {
        return response()->json($this->service->references($request->validate($this->selectorRules() + $this->subjectRules(true) + ['request_id' => 'nullable|integer|min:1']), $this->actor($request)));
    }

    public function confirmedRevisions(Request $request)
    {
        return response()->json($this->service->confirmedCandidates($request->validate($this->selectorRules() + ['customer_id' => 'required|integer|min:1',
            'opportunity_id' => 'nullable|integer|min:1', 'lead_id' => 'nullable|integer|min:1']), $this->actor($request)));
    }

    public function show(Request $request, int $id)
    {
        $actor = $this->actor($request);
        return response()->json(['data' => $this->service->present($this->service->visible($id, $actor), $actor)]);
    }

    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, int $id) { return $this->save($request, $id); }

    public function destroy(Request $request, int $id)
    {
        $row = $this->service->remove($id, $request->validate(['version' => 'required|integer|min:1']), $this->actor($request));
        return response()->json(['message' => '图纸需求已删除', 'data' => $row->only(['id', 'status', 'version'])]);
    }

    private function save(Request $request, ?int $id = null)
    {
        $actor = $this->actor($request);
        $row = $this->service->save($id, $request->all(), $actor);
        return response()->json(['message' => $id ? '绘图需求已更新' : '绘图需求已提交', 'data' => $this->service->present($row, $actor)], $id ? 200 : 201);
    }

    public function deliver(Request $request, int $id)
    {
        abort_if($request->file('file') !== null && $request->file('files') !== null, 422, '图纸文件请使用单文件或整套上传方式之一。');
        return response()->json(['message' => '技术交图已保存', 'data' => $this->service->deliver($id, $request->except(['file', 'files']), $this->actor($request), $request->file('files') ?? $request->file('file'))], 201);
    }

    public function confirm(Request $request, int $id, int $revisionId)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $actor = $this->actor($request);
        $this->service->confirm($id, $revisionId, $data, $actor);
        return response()->json(['message' => '已登记客户确认的图纸版本', 'data' => $this->service->present($this->service->visible($id, $actor), $actor)]);
    }

    public function returnRevision(Request $request, int $id, int $revisionId)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $actor = $this->actor($request);
        $this->service->returnRevision($id, $revisionId, $data, $actor);
        return response()->json(['message' => '修改意见已退回承接技术', 'data' => $this->service->present($this->service->visible($id, $actor), $actor)]);
    }

    public function downloadReference(Request $request, int $id, int $attachmentId)
    {
        return $this->service->downloadReference($id, $attachmentId, $this->actor($request));
    }

    private function actor(Request $request): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '请先登录ERP。');
        return $actor;
    }

    private function subjectRules(bool $required = false): array
    {
        return ['subject_type' => ($required ? 'required' : 'nullable').'|in:lead,opportunity', 'subject_id' => 'required_with:subject_type|integer|min:1'];
    }

    private function selectorRules(): array
    {
        return ['keyword' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'ids' => 'nullable|array|max:100', 'ids.*' => 'required|integer|min:1|distinct'];
    }
}
