<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmTeamApplicationService;
use Illuminate\Http\Request;

final class CrmTeamController extends Controller
{
    public function __construct(private readonly CrmTeamApplicationService $team, private readonly AuthContextService $auth) {}

    public function metadata(Request $request) { return response()->json(['data' => $this->team->metadata($this->actor($request))]); }
    public function staff(Request $request) { return response()->json($this->team->staff($this->actor($request), $this->filters($request))); }
    public function showStaff(Request $request, int $userId) { return response()->json(['data' => $this->team->showStaff($this->actor($request), $userId)]); }
    public function users(Request $request) { return response()->json($this->team->users($this->actor($request), $this->filters($request))); }
    public function grants(Request $request) { return response()->json($this->team->grants($this->actor($request), $this->filters($request))); }
    public function saveCapability(Request $request, int $userId) { return response()->json(['data' => $this->team->saveCapability($this->actor($request), $userId, $request->all())]); }
    public function saveGrant(Request $request, ?int $id = null) { return response()->json(['data' => $this->team->saveGrant($this->actor($request), $id, $request->all())]); }

    private function actor(Request $request): object
    {
        $actor = $this->auth->currentUser($request);
        abort_unless($actor, 401, '未登录');
        return $actor;
    }

    private function filters(Request $request): array
    {
        return $request->validate(['keyword' => 'nullable|string|max:80', 'department_id' => 'nullable|integer|min:1',
            'department_ids' => 'nullable|array|max:100', 'department_ids.*' => 'required|integer|min:1',
            'accepting' => 'nullable|boolean', 'status' => 'nullable|in:active,disabled', 'per_page' => 'nullable|integer|min:1|max:100',
            'ids' => 'nullable|array|max:1000', 'ids.*' => 'required|integer|min:1']);
    }
}
