<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessObject;
use App\Services\Erp\Contracts\ApprovalBusinessObjectProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalBusinessObjectRegistry
{
    public function allEnabled(): array
    {
        return ApprovalBusinessObject::query()->where('enabled', true)->with([
            'fields' => fn ($q) => $q->orderBy('sort'),
            'events' => fn ($q) => $q->where('enabled', true)->orderBy('id'),
            'actions' => fn ($q) => $q->where('enabled', true)->orderBy('id'),
        ])->orderBy('business_module')->orderBy('object_name')->get()->map(fn ($object) => $this->serialize($object))->all();
    }

    public function find(string $code, bool $required = true): ?ApprovalBusinessObject
    {
        $object = ApprovalBusinessObject::query()->where('object_code', $code)->where('enabled', true)->with(['fields', 'events', 'actions'])->first();
        if (!$object && $required) throw ValidationException::withMessages(['business_object_code' => '业务对象未注册、已停用或不存在。']);
        return $object;
    }

    public function provider(ApprovalBusinessObject $object): ApprovalBusinessObjectProvider
    {
        $class = $object->provider_class;
        if (!$class || !class_exists($class)) throw ValidationException::withMessages(['business_object_code' => '业务对象 Provider 未安装。']);
        $provider = app($class);
        if (!$provider instanceof ApprovalBusinessObjectProvider) throw ValidationException::withMessages(['business_object_code' => '业务对象 Provider 类型无效。']);
        return $provider;
    }

    public function candidateTables(): array
    {
        $registered = ApprovalBusinessObject::query()->whereNotNull('source_table')->pluck('source_table')->all();
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', 'like', 'erp\_%')
            ->whereNotIn('TABLE_NAME', $registered)->orderBy('TABLE_NAME')->get(['TABLE_NAME', 'TABLE_COMMENT'])
            ->filter(fn ($row) => !$this->isInternal((string) $row->TABLE_NAME))
            ->map(fn ($row) => ['table' => $row->TABLE_NAME, 'label' => $row->TABLE_COMMENT ?: $row->TABLE_NAME, 'registered' => false])
            ->values()->all();
    }

    public function serialize(ApprovalBusinessObject $object): array
    {
        $exposedFields = $object->fields->filter(fn ($field) => $field->condition_enabled
            || $field->display_enabled || $field->reference_enabled || $field->approval_writable);
        $numberField = collect($object->display_fields ?: [])->first(fn ($field) => preg_match('/(_no|_number|_code)$/', (string) $field)) ?: $object->primary_key;
        $statusFields = $exposedFields->filter(fn ($field) => str_ends_with($field->field_code, '_status'))->map(fn ($field) => ['value' => $field->field_code, 'label' => $field->field_name])->values()->all();
        $events = $object->events->map(fn ($event) => [
            'key' => $event->event_code, 'label' => $event->event_name,
            'manual_start_allowed' => (bool) $event->manual_start_allowed, 'event_trigger_allowed' => (bool) $event->event_trigger_allowed,
        ])->values()->all();
        return [
            'code' => $object->object_code, 'label' => $object->object_name, 'module' => $object->business_module,
            'source_type' => $object->source_type, 'table' => $object->source_table, 'id_field' => $object->primary_key,
            'route_pattern' => $object->route_pattern, 'display_fields' => $object->display_fields ?: [], 'search_fields' => $object->search_fields ?: [],
            'number_field' => $numberField, 'status_fields' => $statusFields, 'triggers' => $events,
            'fields' => $exposedFields->map(fn ($field) => [
                'value' => $field->field_code, 'label' => $field->field_name, 'type' => $field->field_type,
                'source_path' => $field->source_path, 'options' => $field->options ?: [],
                'condition_enabled' => (bool) $field->condition_enabled, 'display_enabled' => (bool) $field->display_enabled,
                'reference_enabled' => (bool) $field->reference_enabled, 'approval_writable' => (bool) $field->approval_writable,
            ])->values()->all(),
            'events' => $events,
            'actions' => $object->actions->map(fn ($action) => [
                'key' => $action->action_code, 'label' => $action->action_name, 'event' => $action->result_event,
                'config_schema' => $action->config_schema ?: [],
            ])->values()->all(),
        ];
    }

    private function isInternal(string $table): bool
    {
        return str_starts_with($table, 'erp_approval_') || str_starts_with($table, 'erp_rbac_')
            || str_starts_with($table, 'erp_sync_') || str_starts_with($table, 'erp_legacy_');
    }
}
