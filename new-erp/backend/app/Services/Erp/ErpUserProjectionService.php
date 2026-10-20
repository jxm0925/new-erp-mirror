<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

final class ErpUserProjectionService
{
    public function one(mixed $userId): ?array
    {
        $id = is_numeric($userId) ? (int) $userId : 0;
        return $id > 0 ? ($this->many([$id])[$id] ?? null) : null;
    }

    public function many(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];

        return DB::table('erp_legacy_admin_users')
            ->whereIn('legacy_id', $ids)
            ->get(['legacy_id', 'nickname', 'username', 'department_names', 'status'])
            ->mapWithKeys(function (object $user): array {
                $departments = json_decode((string) ($user->department_names ?? '[]'), true);
                $departments = is_array($departments) ? array_values(array_filter($departments, 'is_string')) : [];
                return [(int) $user->legacy_id => [
                    'user_id' => (int) $user->legacy_id,
                    'display_name' => $user->nickname ?: $user->username ?: '未命名用户',
                    'department_name' => $departments[0] ?? null,
                    'status' => (string) ($user->status ?? 'unknown'),
                ]];
            })
            ->all();
    }
}
