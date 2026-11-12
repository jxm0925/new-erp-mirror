<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmSavedViewApplicationService;
use Illuminate\Http\Request;

final class CrmSavedViewController extends Controller
{
    public function __construct(private readonly CrmSavedViewApplicationService $views, private readonly AuthContextService $auth) {}

    public function metadata(Request $request) { return response()->json(['data' => $this->views->metadata($this->actor($request), $this->resource($request))]); }
    public function index(Request $request)
    {
        $resource = $this->resource($request);
        $filters = $request->validate(['keyword' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->views->index($this->actor($request), $resource, $filters));
    }
    public function show(Request $request, int $id) { return response()->json(['data' => $this->views->show($this->actor($request), $id)]); }
    public function store(Request $request) { return response()->json(['data' => $this->views->save($this->actor($request), null, $request->all())], 201); }
    public function update(Request $request, int $id) { return response()->json(['data' => $this->views->save($this->actor($request), $id, $request->all())]); }
    public function destroy(Request $request, int $id)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $this->views->destroy($this->actor($request), $id, (int) $data['expected_version']);
        return response()->json(['message' => '视图已删除。']);
    }

    private function resource(Request $request): string
    {
        return $request->validate(['resource' => 'required|in:customers,leads,opportunities,dashboard'])['resource'];
    }
    private function actor(Request $request): object
    {
        $actor = $this->auth->currentUser($request);
        abort_unless($actor, 401, '未登录');
        return $actor;
    }
}
