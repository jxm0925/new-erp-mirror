<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmDuplicateReviewApplicationService;
use Illuminate\Http\Request;

final class CrmDuplicateReviewController extends Controller
{
    public function __construct(private readonly CrmDuplicateReviewApplicationService $reviews, private readonly AuthContextService $auth) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:pending,linked,returned', 'subject_type' => 'nullable|in:lead,customer,order_draft',
            'subject_id' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->reviews->index($this->actor($request), $filters));
    }

    public function store(Request $request)
    {
        $actor = $this->actor($request);
        return response()->json(['data' => $this->reviews->present($this->reviews->create($request->all(), $actor), $actor)]);
    }

    public function show(Request $request, int $id) { return response()->json(['data' => $this->reviews->show($id, $this->actor($request))]); }
    public function resolve(Request $request, int $id) { return response()->json(['data' => $this->reviews->resolve($id, $request->all(), $this->actor($request))]); }

    private function actor(Request $request): object
    {
        $actor = $this->auth->currentUser($request);
        abort_unless($actor, 401, '未登录');
        return $actor;
    }
}
