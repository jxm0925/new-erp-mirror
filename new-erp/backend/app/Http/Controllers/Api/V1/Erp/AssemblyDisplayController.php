<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\Manufacturing\AssemblyDisplayQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssemblyDisplayController extends Controller
{
    public function __construct(private readonly AssemblyDisplayQueryService $queries) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'in:all,produce,assemble,testing,stock'], 'status' => ['sometimes', 'in:all,wait,accept,complete,cancel'],
            'create_from' => ['nullable', 'date_format:Y-m-d'], 'create_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:create_from'],
            'customer' => ['nullable', 'string', 'max:200'], 'product_name' => ['nullable', 'string', 'max:200'],
            'order_no' => ['nullable', 'string', 'max:80'], 'order_type' => ['sometimes', 'in:all,ordinary,delayed,urgent,customized'],
        ]);
        return response()->json(['data' => $this->queries->paginate($filters)])->header('Cache-Control', 'no-store');
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->detail($id)])->header('Cache-Control', 'no-store');
    }

    public function history(int $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->history($id)])->header('Cache-Control', 'no-store');
    }

    public function change(int $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->change($id)])->header('Cache-Control', 'no-store');
    }
}
