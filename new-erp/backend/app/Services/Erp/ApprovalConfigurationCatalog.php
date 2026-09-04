<?php

namespace App\Services\Erp;

use Illuminate\Validation\ValidationException;

class ApprovalConfigurationCatalog
{
    public function __construct(
        private readonly ApprovalBusinessObjectRegistry $objects,
        private readonly BusinessActionRegistry $actionRegistry,
    ) {}

    public function flowCategories(): array
    {
        return collect($this->businessObjects())
            ->pluck('module')->filter()->unique()->sort()->values()
            ->map(fn ($module) => ['value' => $module, 'label' => $module])
            ->push(['value' => 'OTHER', 'label' => '其他'])
            ->all();
    }

    public function fieldTypes(): array
    {
        return [
            ['value' => 'text', 'label' => '单行文本'],
            ['value' => 'textarea', 'label' => '多行文本'],
            ['value' => 'number', 'label' => '数字'],
            ['value' => 'date', 'label' => '日期'],
            ['value' => 'select', 'label' => '单选'],
            ['value' => 'multi_select', 'label' => '多选'],
            ['value' => 'user', 'label' => '人员'],
            ['value' => 'department', 'label' => '部门'],
            ['value' => 'attachment', 'label' => '附件'],
            ['value' => 'business_link', 'label' => '关联业务单据'],
        ];
    }

    public function businessObjects(): array
    {
        return $this->objects->allEnabled();
    }

    public function actions(): array
    {
        return $this->actionRegistry->actions();
    }

    public function businessObject(?string $code): ?array
    {
        if (!$code) return null;
        return collect($this->businessObjects())->firstWhere('code', $code);
    }

    public function action(?string $key): ?array
    {
        if (!$key) return null;
        return collect($this->actions())->firstWhere('key', $key);
    }

    public function linkedObject(array $definition): ?array
    {
        if (($definition['source_mode'] ?? null) === 'existing' && !empty($definition['business_object_code'])) {
            return $this->businessObject((string) $definition['business_object_code']);
        }
        if (($definition['source_mode'] ?? null) === 'custom_form') return $this->businessObject('CUSTOM_FORM_SUBMISSION');
        $field = collect((array) ($definition['form_fields'] ?? []))
            ->first(fn ($row) => ($row['type'] ?? null) === 'business_link' && !empty($row['object_code']));
        return $field ? $this->businessObject((string) $field['object_code']) : null;
    }

    public function assertActionAllowed(array $definition, string $event, string $actionKey): array
    {
        $action = $this->action($actionKey);
        if (!$action || $action['event'] !== $event) {
            throw ValidationException::withMessages(['completion_actions' => '流程处理动作不存在或与触发结果不匹配。']);
        }
        $linked = $this->linkedObject($definition);
        if (!empty($action['object_code']) && (!$linked || $linked['code'] !== $action['object_code'])) {
            throw ValidationException::withMessages(['completion_actions' => '所选业务动作不适用于当前关联单据。']);
        }
        return $action;
    }

}
