<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Models\Erp\ApprovalBusinessObject;
use App\Services\Erp\ApprovalBusinessObjectRegistry;
use App\Services\Erp\SalesOrderEditImpactService;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateRegisteredFieldsAction implements ApprovalBusinessActionHandler
{
    public function __construct(private readonly ApprovalBusinessObjectRegistry $objects) {}

    public function targets(ApprovalBusinessObject $object): array
    {
        $targets = [[
            'value' => 'business', 'label' => $object->object_name,
            'table' => $object->source_table, 'primary_key' => $object->primary_key,
            'fields' => $object->fields->where('approval_writable', true)->map(fn ($field) => [
                'value' => $field->field_code, 'label' => $field->field_name, 'type' => $field->field_type,
                'source_path' => $field->source_path, 'options' => $field->options ?: [],
            ])->values()->all(),
        ]];
        if ($object->object_code === 'SALES_ORDER_CHANGE' && $object->source_table === 'erp_sales_order_change_candidates') {
            $targets[] = [
                'value' => 'sales_order', 'label' => '关联销售订单', 'table' => 'erp_sales_orders', 'primary_key' => 'id',
                'fields' => collect(SalesOrderEditImpactService::APPROVAL_WRITABLE_FIELDS)->map(fn ($field, $code) => $field + [
                    'value' => $code, 'source_path' => $code, 'options' => [],
                ])->values()->all(),
            ];
        }
        return array_values(array_filter($targets, fn ($target) => count($target['fields']) > 0));
    }

    private function target(ApprovalBusinessObject $object, array $config): array
    {
        $target = collect($this->targets($object))->firstWhere('value', $config['target'] ?? 'business');
        if (!$target) throw ValidationException::withMessages(['completion_action' => '请选择具有可写字段的业务对象。']);
        return $target;
    }

    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $object = $this->objects->find((string) $task->business_object_code);
        $target = $this->target($object, $config);
        $payload = $this->validatedUpdates((string) $task->business_object_code, $config);
        $businessId = $target['value'] === 'sales_order'
            ? DB::table($object->source_table)->where($object->primary_key, $task->business_id)->value('sales_order_id') : $task->business_id;
        $query = DB::table($target['table'])->where($target['primary_key'], $businessId);
        $before = $query->lockForUpdate()->first(array_keys($payload));
        if (!$before) throw ValidationException::withMessages(['completion_action' => '要更新的业务记录已不存在。']);
        if ($target['value'] === 'sales_order') app(SalesOrderEditImpactService::class)->updateApprovalFields((int) $businessId, $payload, $operator, $task->id);
        else $query->update($payload);
        return ['target' => $target['value'], 'business_id' => $businessId, 'updated_fields' => array_keys($payload), 'before' => (array) $before, 'after' => (array) $query->first(array_keys($payload)), 'operated_by' => $operator];
    }

    public function validatedUpdates(string $objectCode, array $config): array
    {
        $object = $this->objects->find($objectCode);
        $target = $this->target($object, $config);
        Validator::make($config, [
            'updates' => ['required', 'array', 'min:1'],
            'updates.*' => ['array'], 'updates.*.field' => ['required', 'string', 'distinct'],
            'updates.*.value' => ['present'],
        ])->validate();
        $writable = collect($target['fields'])->keyBy('value');
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $target['table'])->get()->keyBy('COLUMN_NAME');
        $payload = [];
        foreach ($config['updates'] as $update) {
            $field = (string) ($update['field'] ?? '');
            if (!$writable->has($field)) throw ValidationException::withMessages(['completion_action' => '字段 '.$field.' 未注册为审批可写字段。']);
            $registered = (object) $writable[$field];
            $column = $columns->get($registered->source_path);
            if (!$column || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $registered->source_path)
                || in_array($registered->source_path, [$target['primary_key'], 'created_at', 'updated_at', 'deleted_at'], true)
                || str_contains((string) $column->EXTRA, 'GENERATED')) {
                throw ValidationException::withMessages(['completion_action' => '字段 '.$field.' 不是可更新的业务列。']);
            }
            $rules = $column->IS_NULLABLE === 'YES' ? ['nullable'] : ['required'];
            $rules[] = match ($registered->type) {
                'integer', 'user', 'department' => 'integer', 'number' => 'numeric', 'boolean' => 'boolean',
                'date' => 'date_format:Y-m-d', 'datetime' => 'date_format:Y-m-d H:i:s',
                'json' => 'json', default => 'string',
            };
            if ($column->CHARACTER_MAXIMUM_LENGTH !== null && !in_array($registered->type, ['integer', 'number', 'boolean'], true)) $rules[] = 'max:'.$column->CHARACTER_MAXIMUM_LENGTH;
            if ($registered->options) $rules[] = \Illuminate\Validation\Rule::in(array_column($registered->options, 'value'));
            Validator::make(['value' => $update['value']], ['value' => $rules], [], ['value' => $registered->label])->validate();
            $payload[$registered->source_path] = $update['value'];
        }
        return $payload;
    }
}
