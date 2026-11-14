<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerRegionService
{
    private ?array $retired = null;

    public function children(int $parentId): array
    {
        return DB::table('erp_regions')->where('parent_id', $parentId)
            ->orderBy('id')->get(['id', 'parent_id', 'name', 'level'])
            ->reject(fn ($row) => $this->isRetired((array) $row))->values()->all();
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

    public function selectablePath(int $regionId, ?int $existingRegionId = null): array
    {
        $path = $this->path($regionId);
        // 退役节点仍供历史回显；只有服务端查得的原记录可保留该地区，新增选择必须使用现行地区。
        if ($regionId !== $existingRegionId) {
            foreach ($path as $row) {
                if ($this->isRetired($row)) {
                    throw ValidationException::withMessages(['region_id' => '所选地区已调整，请重新选择现行地区。']);
                }
            }
        }
        return $path;
    }

    public function address(int $regionId, string $detail, ?int $existingRegionId = null): array
    {
        $path = $this->selectablePath($regionId, $existingRegionId);
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

    private function isRetired(array $row): bool
    {
        $this->retired ??= array_column(json_decode(file_get_contents(database_path('seeders/data/erp_regions_retired.json')),
            true, 512, JSON_THROW_ON_ERROR), null, 'id');
        $original = $this->retired[$row['id']] ?? null;
        // 只屏蔽原始快照，不能因同 ID 就隐藏后来人工维护的节点。
        return $original && (int) $row['parent_id'] === $original['parent_id']
            && $row['name'] === $original['name'] && (int) $row['level'] === $original['level'];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['addresses' => '所选地区不存在或上下级关系不完整，请重新选择。']);
    }
}
