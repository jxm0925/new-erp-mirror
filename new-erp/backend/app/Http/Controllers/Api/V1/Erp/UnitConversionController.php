<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\UnitConversion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitConversionController extends Controller
{
    public function index()
    {
        return response()->json(UnitConversion::with(['sourceUnit', 'targetUnit'])->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        abort_if($data['source_unit_id'] === $data['target_unit_id'], 422, '来源单位和目标单位不能相同');
        $record = UnitConversion::create($data);
        return response()->json(['message' => '换算关系保存成功', 'data' => $record->load(['sourceUnit', 'targetUnit'])], 201);
    }

    public function update(Request $request, int $id)
    {
        $record = UnitConversion::findOrFail($id);
        $data = $this->validated($request, $id);
        abort_if($data['source_unit_id'] === $data['target_unit_id'], 422, '来源单位和目标单位不能相同');
        $record->update($data);
        return response()->json(['message' => '换算关系已更新，请确认受影响的主数据', 'data' => $record->fresh()]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'source_unit_id' => ['required', 'exists:erp_units,id', Rule::unique('erp_unit_conversions')->where(fn ($q) => $q->where('target_unit_id', $request->target_unit_id))->ignore($id)],
            'target_unit_id' => 'required|exists:erp_units,id', 'ratio' => 'required|numeric|gt:0',
            'formula' => 'nullable|string|max:160', 'decimal_places' => 'required|integer|min:0|max:8',
            'status' => 'required|in:enabled,disabled',
        ]);
    }
}
