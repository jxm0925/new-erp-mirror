<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Concerns\AuthorizesErpPermissions;
use App\Http\Controllers\Controller;
use App\Models\Erp\Item;
use App\Services\Erp\Inventory\StockMeasureService;
use App\Services\Erp\ItemManagementScopeService;
use Illuminate\Http\Request;

/** 管材米数、板材尺寸：查看仓库里的实物，修正入库时录错的长宽厚／每根长度。 */
class InventoryMeasureController extends Controller
{
    use AuthorizesErpPermissions;

    public function physicals(Request $request, int $itemId, StockMeasureService $service)
    {
        $this->erpAuthorize($request, 'inventory.balance.view');
        $item = Item::query()->with('unit')->findOrFail($itemId);
        app(ItemManagementScopeService::class)->assertContext($item, app(ItemManagementScopeService::class)->requestScope($request));
        $data = $request->validate(['batch_no' => 'nullable|string|max:80', 'warehouse_id' => 'nullable|integer|min:1']);
        return response()->json(['data' => $service->physicals($itemId, $data), 'profile' => $service->profile($item)]);
    }

    public function updatePhysical(Request $request, int $id, StockMeasureService $service)
    {
        $user = $this->erpAuthorize($request, 'inventory.physical.manage');
        $data = $request->validate([
            'expected_version' => 'required|integer|min:1',
            'length_mm' => 'required', 'width_mm' => 'required', 'thickness_mm' => 'required',
            'nominal_thickness_mm' => 'nullable', 'reason' => 'nullable|string|max:200',
        ]);
        return response()->json(['message' => '尺寸已修改', 'data' => $service->updatePhysicalDimensions($id, $data, $user)]);
    }

    public function updateLotLength(Request $request, int $id, StockMeasureService $service)
    {
        $user = $this->erpAuthorize($request, 'inventory.physical.manage');
        $data = $request->validate(['length_mm' => 'required', 'reason' => 'nullable|string|max:200']);
        return response()->json(['message' => '每根长度已修改', 'data' => $service->updateLotLength($id, $data, $user)]);
    }
}
