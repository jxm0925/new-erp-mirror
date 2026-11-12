<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmOrderAmountQueryService;
use Illuminate\Http\Request;

final class CrmOrderAmountController extends Controller
{
    public function __construct(private readonly CrmOrderAmountQueryService $amounts) {}

    public function index(Request $request)
    {
        return response()->json($this->amounts->orders($this->filters($request), $this->user($request)));
    }

    public function people(Request $request)
    {
        return response()->json($this->amounts->people($this->filters($request), $this->user($request)));
    }

    public function customer(Request $request, int $id)
    {
        return response()->json($this->amounts->customerOrders($id, $this->filters($request), $this->user($request)));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'sales_user_legacy_id' => 'nullable|integer|min:1', 'sales_department_id' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|integer|min:1', 'keyword' => 'nullable|string|max:160',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function user(Request $request): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '未登录');
        return $actor;
    }
}
