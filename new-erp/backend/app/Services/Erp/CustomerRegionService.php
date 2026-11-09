<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerRegionService
{
    public function children(int $parentId): array
    {
        return DB::table('erp_regions')->where('parent_id', $parentId)
            ->orderBy('id')->get(['id', 'name', 'level'])->all();
    }

    public function path(int $id): array
    {
        $path = [];
        while ($id > 0 && count($path) < 4) {
            $row = DB::table('erp_regions')->find($id);
            if (!$row) $this->invalid();
            array_unshift($path, (array) $row);
            $id = (int) $row->parent_id;
        }
        if ($id !== 0 || !$path) $this->invalid();
        foreach ($path as $level => $row) {
            if ((int) $row['level'] !== $level) $this->invalid();
        }
        return $path;
    }

    public function address(int $regionId, string $detail): array
    {
        $path = $this->path($regionId);
        $names = array_column($path, 'name');
        $fullAddress = trim(implode(' ', [...$names, $detail]));
        if (mb_strlen($fullAddress) > 500) {
            throw ValidationException::withMessages(['addresses' => '地区与详细地址合计不能超过500字。']);
        }
        return [
            'region_id' => $regionId,
            'country' => $names[0], 'province' => $names[1] ?? null,
            'city' => $names[2] ?? null, 'district' => $names[3] ?? null,
            'full_address' => $fullAddress,
        ];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['addresses' => '所选地区不存在或上下级关系不完整，请重新选择。']);
    }
}
