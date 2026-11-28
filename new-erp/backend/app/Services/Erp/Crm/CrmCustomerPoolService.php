<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmCustomerPool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrmCustomerPoolService
{
    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (CrmCustomerPool::query()->orderBy('id')->get() as $pool) {
            if ($value === $pool->pool_code || $value === $pool->pool_name || in_array($value, $pool->legacy_aliases ?? [], true)) {
                return $pool->pool_name;
            }
        }

        // 未收录的历史原值保留，不能以字典维护阻止历史记录读取和无关编辑。
        return $value;
    }

    public function normalizePoolName(?string $value): ?string
    {
        return $this->normalize($value);
    }

    public function assertSelectable(?string $value, ?string $scope = null, ?string $current = null): ?string
    {
        $name = $this->normalize($value);
        if ($name === null) {
            return null;
        }
        if ($current !== null && $name === $this->normalize($current)) {
            return $current;
        }
        $pool = CrmCustomerPool::query()->where('pool_name', $name)->lockForUpdate()->first();
        abort_unless($pool && $pool->status === 'enabled' && (! $scope || in_array($scope, $pool->applicable_scopes, true)), 422, '请选择当前阶段适用且启用的客户池。');

        return $pool->pool_name;
    }

    public function sqlName(string $column): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column)) {
            throw new \InvalidArgumentException('Invalid pool column');
        }

        return "COALESCE((SELECT pool_name FROM erp_crm_customer_pools AS crm_pool_dict WHERE crm_pool_dict.pool_name = {$column} OR crm_pool_dict.pool_code = {$column} OR JSON_CONTAINS(crm_pool_dict.legacy_aliases, JSON_QUOTE({$column})) ORDER BY crm_pool_dict.id LIMIT 1), NULLIF({$column}, ''))";
    }

    public function queryNames(?string $value): array
    {
        $name = $this->normalize($value);
        $pool = CrmCustomerPool::query()->where('pool_name', $name)->first();

        return $pool ? array_values(array_unique(array_merge([$pool->pool_name, $pool->pool_code], $pool->legacy_aliases ?? []))) : [(string) $value];
    }

    public function options(?string $scope = null): array
    {
        return CrmCustomerPool::query()->where('status', 'enabled')->when($scope, fn ($q) => $q->whereJsonContains('applicable_scopes', $scope))
            ->orderBy('sort_order')->orderBy('id')->get()->map(fn ($p) => $this->present($p))->all();
    }

    public function present(CrmCustomerPool $pool): array
    {
        return $pool->toArray() + ['business_version' => $this->version($pool), 'name' => $pool->pool_name, 'code' => $pool->pool_code];
    }

    private function version(CrmCustomerPool $pool): string
    {
        return hash('sha256', json_encode($pool->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function save(?int $id, array $data, object $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            // 名称与别名共用一个命名空间；锁字典防止并发把同一历史值认到两个池。
            $rows = CrmCustomerPool::query()->orderBy('id')->lockForUpdate()->get();
            $pool = $id ? $rows->firstWhere('id', $id) : new CrmCustomerPool;
            abort_unless($pool, 404, '客户池不存在。');
            if ($id) {
                $this->checkVersion($pool, $data['expected_version'] ?? '');
            }
            $before = $id ? $pool->attributesToArray() : null;
            $values = array_intersect_key($data, array_flip(['pool_code', 'pool_name', 'group_code', 'applicable_scopes', 'legacy_aliases', 'sort_order', 'status']));
            $values['pool_code'] = trim($values['pool_code'] ?? $pool->pool_code ?? '');
            $values['pool_name'] = trim($values['pool_name'] ?? $pool->pool_name ?? '');
            $values['applicable_scopes'] = array_values(array_unique($values['applicable_scopes'] ?? $pool->applicable_scopes ?? []));
            $aliases = array_values(array_unique(array_filter(array_map('trim', $values['legacy_aliases'] ?? $pool->legacy_aliases ?? []))));
            if ($id && $pool->pool_name !== $values['pool_name']) {
                $aliases[] = $pool->pool_name;
            }
            $values['legacy_aliases'] = array_values(array_diff(array_unique($aliases), [$values['pool_name'], $values['pool_code']]));
            abort_if($values['pool_code'] === '' || $values['pool_name'] === '' || ! $values['applicable_scopes'], 422, '请填写编码、名称与适用范围。');
            $tokens = array_merge([$values['pool_code'], $values['pool_name']], $values['legacy_aliases']);
            foreach ($rows as $other) {
                if ((int) $other->id !== (int) $id && array_intersect($tokens, array_merge([$other->pool_code, $other->pool_name], $other->legacy_aliases ?? []))) {
                    if (! $id && $other->pool_code === $values['pool_code'] && $other->pool_name === $values['pool_name']
                    && $other->group_code === ($values['group_code'] ?? 'lead')
                    && $other->applicable_scopes === $values['applicable_scopes'] && $other->legacy_aliases === $values['legacy_aliases']
                    && $other->sort_order === (int) ($values['sort_order'] ?? 0) && $other->status === ($values['status'] ?? 'enabled')) {
                        return $this->present($other);
                    }
                    throw ValidationException::withMessages(['pool_name' => '编码、名称或历史别名已被其他客户池使用。']);
                }
            }
            $pool->fill($values + ['updated_by' => $actor->legacy_id]);
            if (! $id) {
                $pool->created_by = $actor->legacy_id;
            }
            if (! $pool->isDirty()) {
                return $this->present($pool);
            }
            $pool->save();
            $this->log($id ? 'update' : 'create', $pool, $before, $actor);

            return $this->present($pool->fresh());
        }, 3);
    }

    public function setStatus(int $id, string $status, string $version, object $actor): array
    {
        return $this->save($id, ['status' => $status, 'expected_version' => $version], $actor);
    }

    public function delete(int $id, string $version, object $actor): void
    {
        DB::transaction(function () use ($id, $version, $actor) {
            $pool = CrmCustomerPool::query()->lockForUpdate()->findOrFail($id);
            $this->checkVersion($pool, $version);
            $names = array_merge([$pool->pool_name, $pool->pool_code], $pool->legacy_aliases ?? []);
            foreach (['erp_crm_leads', 'erp_crm_opportunities', 'erp_sales_customers'] as $table) {
                abort_if(DB::table($table)->whereIn('customer_pool', $names)->exists(), 422, '客户池已有业务引用，请改为停用。');
            }
            foreach ($names as $name) {
                $quoted = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $followup = DB::table('erp_crm_followups')->whereRaw("JSON_CONTAINS(combat_snapshot, ?, '$.pool_before') OR JSON_CONTAINS(combat_snapshot, ?, '$.pool_after')", [$quoted, $quoted])->exists();
                $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name);
                $daily = DB::table('erp_crm_daily_reports')->whereRaw("JSON_SEARCH(body, 'one', ?, NULL, '$**.pool', '$**.customer_pool') IS NOT NULL", [$pattern])->exists();
                abort_if($followup || $daily, 422, '客户池已有历史跟进或日报引用，请改为停用。');
            }
            $this->log('delete', $pool, $pool->attributesToArray(), $actor);
            $pool->delete();
        }, 3);
    }

    private function checkVersion(CrmCustomerPool $pool, string $version): void
    {
        abort_unless(hash_equals($this->version($pool), $version), 409, '客户池已被修改，请刷新后重试。');
    }

    private function log(string $action, CrmCustomerPool $pool, ?array $before, object $actor): void
    {
        DB::table('erp_operation_logs')->insert(['module' => 'crm_customer_pool', 'action' => $action, 'target_type' => $pool->getTable(), 'target_id' => $pool->id,
            'old_snapshot' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, 'new_snapshot' => json_encode($pool->attributesToArray(), JSON_UNESCAPED_UNICODE),
            'reason' => '客户池基础档案维护', 'operator_id' => $actor->legacy_id, 'operator_name' => $actor->nickname ?? $actor->username ?? null, 'created_at' => now()]);
    }
}
