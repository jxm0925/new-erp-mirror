<?php

namespace App\Services\Erp\ApprovalActions;

use App\Models\Erp\ApprovalBusinessAction;
use App\Models\Erp\ApprovalTask;
use App\Models\Erp\ApprovalTaskNode;
use App\Services\Erp\ApprovalBusinessObjectRegistry;
use App\Services\Erp\Contracts\ApprovalBusinessActionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRegisteredFieldsAction implements ApprovalBusinessActionHandler
{
    public function __construct(private readonly ApprovalBusinessObjectRegistry $objects) {}

    public function execute(ApprovalBusinessAction $action, ApprovalTask $task, ?ApprovalTaskNode $node, array $config, string $operator, ?string $decision = null, ?string $comment = null): array
    {
        $object = $this->objects->find((string) $task->business_object_code);
        $writable = $object->fields->where('approval_writable', true)->keyBy('field_code');
        $payload = [];
        foreach ((array) ($config['updates'] ?? []) as $update) {
            $field = (string) ($update['field'] ?? '');
            if (!$writable->has($field)) throw ValidationException::withMessages(['completion_action' => '字段 '.$field.' 未注册为审批可写字段。']);
            $payload[$writable[$field]->source_path] = $update['value'] ?? null;
        }
        if (!$payload) throw ValidationException::withMessages(['completion_action' => '字段更新动作没有配置任何允许字段。']);
        $affected = DB::table($object->source_table)->where($object->primary_key, $task->business_id)->update($payload);
        return ['business_id' => $task->business_id, 'updated_fields' => array_keys($payload), 'affected_rows' => $affected, 'operated_by' => $operator];
    }
}
