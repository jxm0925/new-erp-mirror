<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Versioned custom item configurations used by cutting plans.
 *
 * Published rows are immutable business facts.  A change therefore starts a
 * new draft version in the same configuration family; editing a published row
 * in place would silently alter historic plans, lots and output identities.
 */
final class CuttingConfigurationService
{
    private const DIMENSION_FIELDS = [
        'length_mm', 'width_mm', 'height_mm', 'thickness_mm',
        'diameter_mm', 'inner_diameter_mm', 'outer_diameter_mm',
        'weight_kg', 'area_mm2',
    ];

    public function __construct(
        private readonly CuttingCommandService $commands,
        private readonly DocumentNumberService $numbers,
        private readonly ProductionDataScopeResolver $scope,
    ) {}

    public function paginate(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($filters['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) {
            $this->commands->fail('pagination_invalid', '分页参数不合法，每页最多100条。');
        }

        $query = $this->baseQuery();
        $this->applyVisibility($query, $user, $permissions, $super, 'production.cutting.view');
        if (! empty($filters['item_id'])) {
            $query->where('c.item_id', (int) $filters['item_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (! empty($filters['scope_mode'])) {
            $query->where('c.scope_mode', $filters['scope_mode']);
        }
        if (! empty($filters['keyword'])) {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $query->where(function (Builder $q) use ($keyword): void {
                $q->where('c.configuration_no', 'like', $keyword)
                    ->orWhere('c.drawing_reference', 'like', $keyword)
                    ->orWhere('i.item_code', 'like', $keyword)
                    ->orWhere('i.item_name', 'like', $keyword)
                    ->orWhere('i.spec', 'like', $keyword);
            });
        }

        $result = $query->orderByDesc('c.id')->paginate($size, ['*'], 'page', $page);

        return [
            'data' => $this->projections(collect($result->items())),
            'meta' => ['current_page' => (int) $page, 'per_page' => (int) $size,
                'total' => $result->total(), 'last_page' => $result->lastPage()],
        ];
    }

    public function show(int $id, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $row = $this->visibleRow($id, $user, $permissions, $super, 'production.cutting.view');

        return $this->projections(collect([$row]))[0];
    }

    public function createDraft(array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.material_manage');
        $this->assertFields($payload, ['client_command_id', 'expected_version', 'item_id', 'dimensions', 'drawing_reference', 'scope_mode', 'scope_work_order_ids']);
        $payload = $this->normalizePayload($payload, true);
        $this->item((int) $payload['item_id']);
        $this->validateScopes($payload['scope_mode'], $payload['scope_work_order_ids'], $user, $permissions, $super);

        return $c->run('create_cutting_configuration', 0, $payload, $user, function () use ($c, $payload, $user, $permissions, $super): array {
            if (($payload['expected_version'] ?? null) !== 0) {
                $c->fail('version_required', '新建配置草稿版本必须为0。');
            }
            $item = $this->item((int) $payload['item_id'], true);
            $this->validateScopes($payload['scope_mode'], $payload['scope_work_order_ids'], $user, $permissions, $super, true);
            $id = DB::table('erp_custom_configurations')->insertGetId([
                'item_id' => $item->id,
                'configuration_no' => $this->numbers->next('cutting_custom_configuration', 'CFG'),
                'version_no' => 1,
                'dimensions' => json_encode($payload['dimensions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'drawing_reference' => $payload['drawing_reference'],
                'scope_mode' => $payload['scope_mode'],
                'status' => 'DRAFT',
                'business_version' => 1,
                'created_by_legacy_id' => $c->actor($user),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->replaceScopes($id, $payload['scope_work_order_ids']);
            $response = $this->projection($this->baseQuery()->where('c.id', $id)->first(), $this->scopesFor([$id])->get($id, collect()));
            $c->event('configuration', $id, 'create_draft', $user, null, $response);

            return $response;
        });
    }

    public function updateDraft(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.material_manage');
        $this->assertFields($payload, ['client_command_id', 'expected_version', 'dimensions', 'drawing_reference', 'scope_mode', 'scope_work_order_ids']);
        $payload = $this->normalizePayload($payload, false);
        $this->assertManageable($id, $user, $permissions, $super);
        $this->validateScopes($payload['scope_mode'], $payload['scope_work_order_ids'], $user, $permissions, $super);

        return $c->run('update_cutting_configuration', $id, $payload, $user, function () use ($c, $id, $payload, $user, $permissions, $super): array {
            $row = DB::table('erp_custom_configurations')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                $c->fail('configuration_missing', '配置版本不存在。', 404);
            }
            $this->assertManageableRow($row, $user, $permissions, $super);
            $c->version($row, $payload);
            if ($row->status !== 'DRAFT') {
                $c->fail('configuration_immutable', '已发布的配置版本不可修改，请建立下一版本。', 409);
            }
            $this->item((int) $row->item_id, true);
            $this->validateScopes($payload['scope_mode'], $payload['scope_work_order_ids'], $user, $permissions, $super, true);
            $before = $this->projection($this->baseQuery()->where('c.id', $id)->first(), $this->scopesFor([$id])->get($id, collect()));
            DB::table('erp_custom_configurations')->where('id', $id)->update([
                'dimensions' => json_encode($payload['dimensions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'drawing_reference' => $payload['drawing_reference'],
                'scope_mode' => $payload['scope_mode'],
                'business_version' => (int) $row->business_version + 1,
                'updated_at' => now(),
            ]);
            $this->replaceScopes($id, $payload['scope_work_order_ids']);
            $response = $this->projection($this->baseQuery()->where('c.id', $id)->first(), $this->scopesFor([$id])->get($id, collect()));
            $c->event('configuration', $id, 'update_draft', $user, $before, $response);

            return $response;
        });
    }

    public function publish(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.material_manage');
        $this->assertFields($payload, ['client_command_id', 'expected_version']);
        $this->assertManageable($id, $user, $permissions, $super);

        return $c->run('publish_cutting_configuration', $id, $payload, $user, function () use ($c, $id, $payload, $user, $permissions, $super): array {
            $row = DB::table('erp_custom_configurations')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                $c->fail('configuration_missing', '配置版本不存在。', 404);
            }
            $this->assertManageableRow($row, $user, $permissions, $super);
            $c->version($row, $payload);
            if ($row->status !== 'DRAFT') {
                $c->fail('configuration_state_invalid', '只有草稿配置可以发布。', 409);
            }
            $this->item((int) $row->item_id, true);
            $scopeIds = DB::table('erp_custom_configuration_scopes')->where('configuration_id', $id)
                ->where('source_type', 'work_order')->orderBy('source_id')->pluck('source_id')->map(fn ($value) => (int) $value)->all();
            $this->validateScopes((string) $row->scope_mode, $scopeIds, $user, $permissions, $super, true);
            $before = $this->projection($this->baseQuery()->where('c.id', $id)->first(), $this->scopesFor([$id])->get($id, collect()));
            DB::table('erp_custom_configurations')->where('id', $id)->update([
                'status' => 'PUBLISHED', 'published_at' => now(),
                'business_version' => (int) $row->business_version + 1, 'updated_at' => now(),
            ]);
            $response = $this->projection($this->baseQuery()->where('c.id', $id)->first(), $this->scopesFor([$id])->get($id, collect()));
            $c->event('configuration', $id, 'publish', $user, $before, $response);

            return $response;
        });
    }

    public function createNextVersion(int $id, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        $c->permission($permissions, 'production.cutting.material_manage');
        $this->assertFields($payload, ['client_command_id', 'expected_version']);
        $this->assertManageable($id, $user, $permissions, $super);

        return $c->run('version_cutting_configuration', $id, $payload, $user, function () use ($c, $id, $payload, $user, $permissions, $super): array {
            // Lock the whole family before choosing max(version_no).  Without this
            // family lock two editors could both derive the same next version.  Read
            // the family key first, then take every row lock in ID order so callers
            // starting from different published versions do not invert lock order.
            $seed = DB::table('erp_custom_configurations')->where('id', $id)->first();
            if (! $seed) {
                $c->fail('configuration_missing', '配置版本不存在。', 404);
            }
            $family = DB::table('erp_custom_configurations')->where('configuration_no', $seed->configuration_no)
                ->orderBy('id')->lockForUpdate()->get();
            $source = $family->firstWhere('id', $id);
            if (! $source) {
                $c->fail('configuration_missing', '配置版本不存在。', 404);
            }
            $this->assertManageableRow($source, $user, $permissions, $super);
            $c->version($source, $payload);
            if ($source->status !== 'PUBLISHED') {
                $c->fail('configuration_state_invalid', '只有已发布配置可以建立下一版本。', 409);
            }
            $this->item((int) $source->item_id, true);
            $draft = $family->firstWhere('status', 'DRAFT');
            if ($draft) {
                $c->fail('configuration_draft_exists', '该配置已有待完善草稿，请先处理现有草稿。', 409, ['draft_id' => (int) $draft->id]);
            }
            $scopeIds = DB::table('erp_custom_configuration_scopes')->where('configuration_id', $source->id)
                ->where('source_type', 'work_order')->orderBy('source_id')->pluck('source_id')->map(fn ($value) => (int) $value)->all();
            $this->validateScopes((string) $source->scope_mode, $scopeIds, $user, $permissions, $super, true);
            $next = (int) $family->max('version_no') + 1;
            $newId = DB::table('erp_custom_configurations')->insertGetId([
                'item_id' => $source->item_id, 'configuration_no' => $source->configuration_no,
                'version_no' => $next, 'dimensions' => $source->dimensions,
                'drawing_reference' => $source->drawing_reference, 'scope_mode' => $source->scope_mode,
                'status' => 'DRAFT', 'business_version' => 1,
                'created_by_legacy_id' => $c->actor($user),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->replaceScopes($newId, $scopeIds);
            $response = $this->projection($this->baseQuery()->where('c.id', $newId)->first(), $this->scopesFor([$newId])->get($newId, collect()));
            $response['source_configuration_id'] = (int) $source->id;
            $c->event('configuration', $newId, 'create_next_version', $user, null, $response);

            return $response;
        });
    }

    private function normalizePayload(array $payload, bool $withItem): array
    {
        if (! is_array($payload['dimensions'] ?? null) || array_is_list($payload['dimensions'])) {
            $this->commands->fail('configuration_dimensions_invalid', '配置尺寸必须是字段明确的结构化对象。');
        }
        $unknown = array_diff(array_keys($payload['dimensions']), self::DIMENSION_FIELDS);
        if ($unknown !== []) {
            $this->commands->fail('configuration_dimensions_invalid', '配置尺寸包含不支持的字段。', 422, ['fields' => array_values($unknown)]);
        }
        if ($payload['dimensions'] === []) {
            $this->commands->fail('configuration_dimensions_required', '请至少填写一项正式配置尺寸。');
        }
        $dimensions = [];
        foreach ($payload['dimensions'] as $field => $value) {
            $dimensions[$field] = CuttingDecimal::value($value, 8);
        }
        ksort($dimensions);

        $drawing = trim((string) ($payload['drawing_reference'] ?? ''));
        if ($drawing === '' || mb_strlen($drawing) > 255) {
            $this->commands->fail('drawing_reference_invalid', '请填写不超过255个字符的图纸版本引用。');
        }
        $mode = strtoupper(trim((string) ($payload['scope_mode'] ?? '')));
        if (! in_array($mode, ['PUBLIC', 'RESTRICTED'], true)) {
            $this->commands->fail('configuration_scope_mode_invalid', '配置范围只能是公共或指定工单。');
        }
        $ids = $payload['scope_work_order_ids'] ?? [];
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 100) {
            $this->commands->fail('configuration_scopes_invalid', '配置适用工单须为不超过100项的列表。');
        }
        $ids = array_map(fn ($value) => filter_var($value, FILTER_VALIDATE_INT), $ids);
        if (in_array(false, $ids, true) || collect($ids)->contains(fn ($value) => $value <= 0)) {
            $this->commands->fail('configuration_scopes_invalid', '配置适用工单标识不合法。');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $normalized = ['client_command_id' => $payload['client_command_id'] ?? null,
            'expected_version' => $payload['expected_version'] ?? null,
            'dimensions' => $dimensions, 'drawing_reference' => $drawing,
            'scope_mode' => $mode, 'scope_work_order_ids' => $ids];
        if ($withItem) {
            $normalized['item_id'] = (int) ($payload['item_id'] ?? 0);
        }

        return $normalized;
    }

    private function validateScopes(string $mode, array $ids, object $user, array $permissions, bool $super, bool $lock = false): void
    {
        if ($mode === 'PUBLIC') {
            if ($ids !== []) {
                $this->commands->fail('configuration_public_scope_invalid', '公共配置不能同时限定工单。');
            }

            return;
        }
        if ($ids === []) {
            $this->commands->fail('configuration_scopes_required', '指定工单配置至少要选择一张有权管理的正式工单。');
        }
        foreach ($ids as $id) {
            $this->commands->workOrder((int) $id, $user, $permissions, $super, 'production.cutting.material_manage', $lock);
        }
    }

    private function item(int $id, bool $lock = false): Item
    {
        $query = Item::query()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $item = $query->first();
        if (! $item) {
            $this->commands->fail('configuration_item_missing', '配置所属物料不存在。', 404);
        }
        if ($item->status !== 'enabled' || ! $item->is_custom_item || ! $item->is_production_item) {
            $this->commands->fail('configuration_item_ineligible', '只有已启用的定制生产物料可以维护正式配置。');
        }

        return $item;
    }

    private function replaceScopes(int $configurationId, array $ids): void
    {
        DB::table('erp_custom_configuration_scopes')->where('configuration_id', $configurationId)->delete();
        if ($ids === []) {
            return;
        }
        DB::table('erp_custom_configuration_scopes')->insert(array_map(fn (int $id): array => [
            'configuration_id' => $configurationId, 'source_type' => 'work_order', 'source_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ], $ids));
    }

    private function assertManageable(int $id, object $user, array $permissions, bool $super): object
    {
        $row = DB::table('erp_custom_configurations')->where('id', $id)->first();
        if (! $row) {
            $this->commands->fail('configuration_missing', '配置版本不存在。', 404);
        }
        $this->assertManageableRow($row, $user, $permissions, $super);

        return $row;
    }

    private function assertManageableRow(object $row, object $user, array $permissions, bool $super): void
    {
        if ($row->scope_mode === 'PUBLIC') {
            return;
        }
        $ids = DB::table('erp_custom_configuration_scopes')->where('configuration_id', $row->id)
            ->where('source_type', 'work_order')->orderBy('source_id')->pluck('source_id');
        if ($ids->isEmpty()) {
            $this->commands->fail('configuration_scope_corrupt', '指定工单配置缺少有效适用范围。', 409);
        }
        foreach ($ids as $id) {
            $this->commands->workOrder((int) $id, $user, $permissions, $super, 'production.cutting.material_manage');
        }
    }

    private function visibleRow(int $id, object $user, array $permissions, bool $super, string $permission): object
    {
        $exists = DB::table('erp_custom_configurations')->where('id', $id)->exists();
        if (! $exists) {
            $this->commands->fail('configuration_missing', '配置版本不存在。', 404);
        }
        $query = $this->baseQuery()->where('c.id', $id);
        $this->applyVisibility($query, $user, $permissions, $super, $permission);
        $row = $query->first();
        if (! $row) {
            $this->commands->fail('data_scope_denied', '该配置不在当前生产数据范围内。', 403);
        }

        return $row;
    }

    private function applyVisibility(Builder $query, object $user, array $permissions, bool $super, string $permission): void
    {
        $resolved = $this->scope->resolve($user, $permission, $permissions, $super);
        if (($resolved['mode'] ?? 'deny') === 'all') {
            return;
        }
        if (($resolved['mode'] ?? 'deny') === 'deny') {
            $query->whereRaw('1 = 0');

            return;
        }
        $ids = (array) ($resolved['user_ids'] ?? []);
        $pool = (bool) ($resolved['can_manage_pool'] ?? false);
        $query->where(function (Builder $outer) use ($ids, $pool): void {
            $outer->where('c.scope_mode', 'PUBLIC')->orWhereExists(function (Builder $scope) use ($ids, $pool): void {
                $scope->selectRaw('1')->from('erp_custom_configuration_scopes as cs')
                    ->join('erp_work_orders as wo', 'wo.id', '=', 'cs.source_id')
                    ->whereColumn('cs.configuration_id', 'c.id')->where('cs.source_type', 'work_order')
                    ->where(function (Builder $workOrder) use ($ids, $pool): void {
                        $workOrder->whereIn('wo.responsible_user_legacy_id', $ids);
                        if ($pool) {
                            $workOrder->orWhereNull('wo.responsible_user_legacy_id');
                        }
                    });
            });
        });
    }

    private function baseQuery(): Builder
    {
        return DB::table('erp_custom_configurations as c')->join('erp_items as i', 'i.id', '=', 'c.item_id')
            ->select('c.*', 'i.item_code', 'i.item_name', 'i.spec', 'i.status as item_status');
    }

    private function projections(Collection $rows): array
    {
        $scopes = $this->scopesFor($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $rows->map(fn ($row) => $this->projection($row, $scopes->get((int) $row->id, collect())))->all();
    }

    private function scopesFor(array $configurationIds): Collection
    {
        if ($configurationIds === []) {
            return collect();
        }

        return DB::table('erp_custom_configuration_scopes as cs')->join('erp_work_orders as wo', 'wo.id', '=', 'cs.source_id')
            ->whereIn('cs.configuration_id', $configurationIds)->where('cs.source_type', 'work_order')
            ->orderBy('cs.configuration_id')->orderBy('cs.source_id')
            ->get(['cs.configuration_id', 'cs.source_id', 'wo.work_order_no', 'wo.status', 'wo.responsible_user_legacy_id'])
            ->groupBy('configuration_id');
    }

    private function projection(object $row, Collection $scopes): array
    {
        $dimensions = is_string($row->dimensions) ? json_decode($row->dimensions, true, 512, JSON_THROW_ON_ERROR) : (array) $row->dimensions;

        return [
            'id' => (int) $row->id, 'item_id' => (int) $row->item_id,
            'item_code' => $row->item_code, 'item_name' => $row->item_name, 'spec' => $row->spec,
            'configuration_no' => $row->configuration_no, 'version_no' => (int) $row->version_no,
            'dimensions' => $dimensions, 'drawing_reference' => $row->drawing_reference,
            'scope_mode' => $row->scope_mode, 'status' => $row->status,
            'scope_work_orders' => $scopes->map(fn ($scope) => [
                'id' => (int) $scope->source_id, 'work_order_no' => $scope->work_order_no,
                'status' => $scope->status, 'responsible_user_legacy_id' => $scope->responsible_user_legacy_id === null ? null : (int) $scope->responsible_user_legacy_id,
            ])->values()->all(),
            'business_version' => (int) $row->business_version,
            'created_by_legacy_id' => (int) $row->created_by_legacy_id,
            'published_at' => $row->published_at ? date(DATE_ATOM, strtotime((string) $row->published_at)) : null,
            'created_at' => $row->created_at ? date(DATE_ATOM, strtotime((string) $row->created_at)) : null,
            'updated_at' => $row->updated_at ? date(DATE_ATOM, strtotime((string) $row->updated_at)) : null,
        ];
    }

    private function assertFields(array $payload, array $allowed): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            $this->commands->fail('configuration_fields_invalid', '配置操作包含不允许的字段。', 422, ['fields' => array_values($unknown)]);
        }
    }
}
