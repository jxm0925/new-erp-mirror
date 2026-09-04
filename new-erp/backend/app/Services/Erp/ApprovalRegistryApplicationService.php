<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalBusinessEvent;
use App\Models\Erp\ApprovalBusinessObject;
use App\Models\Erp\ApprovalBusinessObjectField;
use App\Services\Erp\ApprovalProviders\DatabaseBusinessObjectProvider;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalRegistryApplicationService
{
    private const SENSITIVE_FIELDS = [
        'password', 'password_hash', 'remember_token', 'legacy_payload',
        'access_token', 'refresh_token', 'secret', 'api_secret',
    ];

    public function __construct(
        private readonly ApprovalBusinessObjectRegistry $objects,
        private readonly ApprovalInstalledCapabilityCatalog $capabilities,
    ) {}

    public function candidates(array $filters): LengthAwarePaginator
    {
        $registered = ApprovalBusinessObject::query()->whereNotNull('source_table')->pluck('source_table')->all();
        $query = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'like', 'erp\_%')
            ->when($registered, fn ($q) => $q->whereNotIn('TABLE_NAME', $registered))
            ->when(trim((string) ($filters['keyword'] ?? '')), function ($q, $keyword) {
                $q->where(fn ($scope) => $scope->where('TABLE_NAME', 'like', "%{$keyword}%")
                    ->orWhere('TABLE_COMMENT', 'like', "%{$keyword}%"));
            })
            ->where('TABLE_NAME', 'not like', 'erp\_approval\_%')
            ->where('TABLE_NAME', 'not like', 'erp\_rbac\_%')
            ->where('TABLE_NAME', 'not like', 'erp\_sync\_%')
            ->where('TABLE_NAME', 'not like', 'erp\_legacy\_%')
            ->orderBy('TABLE_NAME');

        $page = $query->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 50), [
            'TABLE_NAME', 'TABLE_COMMENT', 'ENGINE', 'TABLE_ROWS',
        ]);
        $page->getCollection()->transform(function ($row) {
            $adapter = $this->capabilities->forTable((string) $row->TABLE_NAME);
            return [
                'table' => (string) $row->TABLE_NAME,
                'label' => (string) ($row->TABLE_COMMENT ?: $row->TABLE_NAME),
                'engine' => (string) $row->ENGINE,
                'estimated_rows' => (int) $row->TABLE_ROWS,
                'adapter' => $adapter ? Arr::except($adapter, ['actions', 'field_labels', 'field_defaults']) : null,
            ];
        });
        return $page;
    }

    public function candidate(string $table): array
    {
        $this->assertCandidateTable($table);
        $adapter = $this->capabilities->forTable($table);
        $defaults = (array) ($adapter['defaults'] ?? []);
        $labels = (array) ($adapter['field_labels'] ?? []);
        $fieldDefaults = (array) ($adapter['field_defaults'] ?? []);
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->orderBy('ORDINAL_POSITION')->get();

        return [
            'table' => $table,
            'adapter' => $adapter ? Arr::except($adapter, ['field_labels', 'field_defaults']) : null,
            'defaults' => $defaults,
            'fields' => $columns->reject(fn ($column) => $this->isSensitive((string) $column->COLUMN_NAME))
                ->values()->map(function ($column, $index) use ($labels, $fieldDefaults) {
                    $code = (string) $column->COLUMN_NAME;
                    $flags = (array) ($fieldDefaults[$code] ?? []);
                    return [
                        'field_code' => $code,
                        'field_name' => (string) ($labels[$code] ?? $column->COLUMN_COMMENT ?: $code),
                        'field_type' => $this->fieldType((string) $column->DATA_TYPE, (string) $column->COLUMN_TYPE),
                        'nullable' => (string) $column->IS_NULLABLE === 'YES',
                        'selected' => array_key_exists($code, $fieldDefaults),
                        'condition_enabled' => (bool) ($flags['condition_enabled'] ?? false),
                        'display_enabled' => (bool) ($flags['display_enabled'] ?? false),
                        'search_enabled' => (bool) ($flags['search_enabled'] ?? false),
                        'reference_enabled' => (bool) ($flags['reference_enabled'] ?? false),
                        'approval_writable' => false,
                        'sort' => (int) $index,
                    ];
                })->all(),
            'installed_actions' => array_values(array_merge(
                (array) ($adapter['actions'] ?? []),
                $this->capabilities->globalActions(),
            )),
        ];
    }

    public function register(array $payload, string $operator): array
    {
        $table = (string) $payload['source_table'];
        $this->assertCandidateTable($table);
        $adapter = $this->capabilities->forTable($table);
        if (($payload['adapter_key'] ?? null) && (!$adapter || $adapter['key'] !== $payload['adapter_key'])) {
            throw ValidationException::withMessages(['adapter_key' => '所选业务适配器与候选数据表不匹配。']);
        }

        $columnDefinitions = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->get(['COLUMN_NAME', 'DATA_TYPE'])->keyBy('COLUMN_NAME');
        $actualColumns = $columnDefinitions->keys()->map(fn ($v) => (string) $v)->all();
        $fields = collect($payload['fields'])->filter(fn ($row) => !empty($row['selected']))->values();
        if ($fields->isEmpty()) throw ValidationException::withMessages(['fields' => '请至少选择一个可用于审核的真实字段。']);
        foreach ($fields as $row) {
            $code = (string) ($row['field_code'] ?? '');
            if (!in_array($code, $actualColumns, true) || $this->isSensitive($code)) {
                throw ValidationException::withMessages(['fields' => "字段 {$code} 不存在或禁止登记。"]);
            }
        }
        if (!in_array((string) $payload['primary_key'], $actualColumns, true)) {
            throw ValidationException::withMessages(['primary_key' => '主键字段不存在于候选数据表。']);
        }
        $primaryType = strtolower((string) data_get($columnDefinitions->get((string) $payload['primary_key']), 'DATA_TYPE'));
        if (!in_array($primaryType, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            throw ValidationException::withMessages(['primary_key' => 'Approval Center V1 仅支持整数主键，请选择整数列作为业务主键。']);
        }
        if (!empty($payload['event']['manual_start_allowed']) && trim((string) ($payload['view_permission_code'] ?? '')) === '') {
            throw ValidationException::withMessages(['view_permission_code' => '允许手动发起时必须配置来源业务查看权限码。']);
        }

        return DB::transaction(function () use ($payload, $table, $fields, $adapter, $operator) {
            $now = now();
            $object = ApprovalBusinessObject::create([
                'object_code' => strtoupper((string) $payload['object_code']),
                'object_name' => $payload['object_name'],
                'business_module' => $payload['business_module'],
                'source_type' => 'database',
                'source_table' => $table,
                'primary_key' => $payload['primary_key'],
                'display_fields' => $fields->filter(fn ($row) => !empty($row['display_enabled']))->pluck('field_code')->values()->all(),
                'search_fields' => $fields->filter(fn ($row) => !empty($row['search_enabled']))->pluck('field_code')->values()->all(),
                'route_pattern' => $payload['route_pattern'] ?? null,
                'provider_class' => DatabaseBusinessObjectProvider::class,
                'context_provider_class' => DatabaseBusinessObjectProvider::class,
                'view_permission_code' => $payload['view_permission_code'] ?? null,
                'enabled' => true,
            ]);

            foreach ($fields as $index => $row) {
                ApprovalBusinessObjectField::create([
                    'business_object_id' => $object->id,
                    'field_code' => $row['field_code'],
                    'field_name' => $row['field_name'] ?: $row['field_code'],
                    'field_type' => $row['field_type'],
                    'source_path' => $row['field_code'],
                    'options' => null,
                    'condition_enabled' => (bool) ($row['condition_enabled'] ?? false),
                    'display_enabled' => (bool) ($row['display_enabled'] ?? false),
                    'reference_enabled' => (bool) ($row['reference_enabled'] ?? false),
                    'approval_writable' => false,
                    'sort' => (int) ($row['sort'] ?? $index),
                ]);
            }

            ApprovalBusinessEvent::create([
                'business_object_id' => $object->id,
                'event_code' => $payload['event']['event_code'],
                'event_name' => $payload['event']['event_name'],
                'manual_start_allowed' => (bool) $payload['event']['manual_start_allowed'],
                'event_trigger_allowed' => (bool) $payload['event']['event_trigger_allowed'],
                'enabled' => true,
            ]);

            foreach ($this->capabilities->globalActions() as $action) {
                ApprovalBusinessAction::updateOrCreate(['action_code' => $action['action_code']], [
                    ...$action, 'business_object_id' => null, 'config_schema' => null, 'enabled' => true,
                ]);
            }
            foreach ((array) ($adapter['actions'] ?? []) as $action) {
                ApprovalBusinessAction::updateOrCreate(['action_code' => $action['action_code']], [
                    ...$action, 'business_object_id' => $object->id, 'config_schema' => null, 'enabled' => true,
                ]);
            }

            $object->load(['fields', 'events', 'actions']);
            return [
                'object' => $this->objects->serialize($object),
                'registered_by' => $operator,
                'registered_at' => $now->toDateTimeString(),
            ];
        });
    }

    private function assertCandidateTable(string $table): void
    {
        if (!preg_match('/^erp_[a-z0-9_]+$/', $table)) {
            throw ValidationException::withMessages(['source_table' => '候选数据表名称非法。']);
        }
        if (str_starts_with($table, 'erp_approval_') || str_starts_with($table, 'erp_rbac_')
            || str_starts_with($table, 'erp_sync_') || str_starts_with($table, 'erp_legacy_')) {
            throw ValidationException::withMessages(['source_table' => '系统内部表不能登记为审核业务对象。']);
        }
        $exists = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)->where('ENGINE', 'InnoDB')->exists();
        if (!$exists) throw ValidationException::withMessages(['source_table' => '候选数据表不存在或不是 InnoDB 表。']);
        if (ApprovalBusinessObject::query()->where('source_table', $table)->exists()) {
            throw ValidationException::withMessages(['source_table' => '该数据表已登记为审核业务对象。']);
        }
    }

    private function isSensitive(string $field): bool
    {
        return in_array(strtolower($field), self::SENSITIVE_FIELDS, true)
            || str_contains(strtolower($field), 'password')
            || str_contains(strtolower($field), 'secret_token');
    }

    private function fieldType(string $dataType, string $columnType): string
    {
        return match (strtolower($dataType)) {
            'tinyint' => preg_match('/tinyint\(1\)/i', $columnType) ? 'boolean' : 'integer',
            'smallint', 'mediumint', 'int', 'bigint' => 'integer',
            'decimal', 'float', 'double' => 'number',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'json' => 'json',
            'enum' => 'select',
            default => 'text',
        };
    }
}
