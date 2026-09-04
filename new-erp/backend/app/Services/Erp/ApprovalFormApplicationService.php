<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalFormSubmission;
use App\Models\Erp\ApprovalFormTemplate;
use App\Models\Erp\ApprovalFormVersion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApprovalFormApplicationService
{
    public function __construct(private readonly ApprovalTriggerEngine $triggers) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = ApprovalFormTemplate::query()->withCount('submissions');
        if ($status = trim((string) ($filters['status'] ?? ''))) $query->where('status', $status);
        if ($module = trim((string) ($filters['business_module'] ?? ''))) $query->where('business_module', $module);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('form_code', 'like', "%{$keyword}%")
                ->orWhere('form_name', 'like', "%{$keyword}%"));
        }
        $page = $query->orderByDesc('updated_at')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
        $page->getCollection()->transform(function (ApprovalFormTemplate $form) {
            $version = ApprovalFormVersion::query()->where('form_template_id', $form->id)
                ->when(
                    $form->current_version > 0,
                    fn ($query) => $query->where('version_no', $form->current_version),
                    fn ($query) => $query->where('version_status', 'draft')->orderByDesc('version_no')
                )->first();
            $schema = (array) ($version?->schema_snapshot ?? []);
            $form->setAttribute('field_count', count($schema['fields'] ?? []));
            $form->setAttribute('current_version_status', $version?->version_status);
            $form->setAttribute('linked_flow_count', DB::table('erp_approval_flow_versions')
                ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(definition_snapshot, '$.form_template_id')) AS UNSIGNED) = ?", [$form->id])
                ->distinct()->count('flow_template_id'));
            return $form;
        });
        return $page;
    }

    public function summary(): array
    {
        return [
            'total' => ApprovalFormTemplate::query()->count(),
            'enabled' => ApprovalFormTemplate::query()->where('status', 'enabled')->count(),
            'draft' => ApprovalFormTemplate::query()->where('status', 'draft')->count(),
            'disabled' => ApprovalFormTemplate::query()->where('status', 'disabled')->count(),
        ];
    }

    public function show(int $id): ApprovalFormTemplate
    {
        $form = ApprovalFormTemplate::query()->with(['versions' => fn ($q) => $q->orderByDesc('version_no')])->findOrFail($id);
        $draft = $form->versions->firstWhere('version_status', 'draft');
        $current = $form->versions->firstWhere('version_no', $form->current_version);
        $form->setAttribute('editing_version', $draft ?: $current);
        $form->setAttribute('submissions_count', $form->submissions()->count());
        $form->setAttribute('linked_flow_count', DB::table('erp_approval_flow_versions')
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(definition_snapshot, '$.form_template_id')) AS UNSIGNED) = ?", [$form->id])
            ->distinct()->count('flow_template_id'));
        return $form;
    }

    public function saveDraft(?int $id, array $payload, string $operator): ApprovalFormTemplate
    {
        return DB::transaction(function () use ($id, $payload, $operator) {
            $form = $id ? ApprovalFormTemplate::query()->lockForUpdate()->findOrFail($id) : new ApprovalFormTemplate();
            $form->fill(Arr::only($payload, ['form_code', 'form_name', 'business_module', 'description']));
            if (!$form->exists) $form->created_by = $operator;
            $form->updated_by = $operator;
            $form->status = $form->status ?: 'draft';
            $form->save();

            $schema = (array) ($payload['schema'] ?? []);
            $validation = $this->validateSchema($schema);
            $draft = ApprovalFormVersion::query()->where('form_template_id', $form->id)
                ->where('version_status', 'draft')->lockForUpdate()->first();
            if (!$draft) {
                $next = max((int) $form->current_version, (int) ApprovalFormVersion::where('form_template_id', $form->id)->max('version_no')) + 1;
                $draft = new ApprovalFormVersion(['form_template_id' => $form->id, 'version_no' => $next, 'version_status' => 'draft']);
            }
            $draft->schema_snapshot = $schema;
            $draft->validation_snapshot = $validation;
            $draft->updated_by = $operator;
            $draft->save();
            return $this->show($form->id);
        });
    }

    public function publish(int $id, array $payload, string $operator): ApprovalFormTemplate
    {
        return DB::transaction(function () use ($id, $payload, $operator) {
            $form = ApprovalFormTemplate::query()->lockForUpdate()->findOrFail($id);
            if (isset($payload['schema'])) $this->saveDraft($id, $payload, $operator);
            $draft = ApprovalFormVersion::query()->where('form_template_id', $id)
                ->where('version_status', 'draft')->lockForUpdate()->firstOrFail();
            $validation = $this->validateSchema((array) $draft->schema_snapshot);
            if (!$validation['valid']) throw ValidationException::withMessages(['schema' => $validation['errors']]);
            $draft->update([
                'version_status' => 'published', 'validation_snapshot' => $validation,
                'published_by' => $operator, 'published_at' => now(), 'updated_by' => $operator,
            ]);
            $form->update(['status' => 'enabled', 'current_version' => $draft->version_no, 'updated_by' => $operator]);
            return $this->show($form->id);
        });
    }

    public function toggle(int $id, bool $enabled, string $operator): ApprovalFormTemplate
    {
        $form = ApprovalFormTemplate::query()->findOrFail($id);
        if ($enabled && $form->current_version < 1) {
            throw ValidationException::withMessages(['status' => '未发布的自定义表单不能启用。']);
        }
        $form->update(['status' => $enabled ? 'enabled' : 'disabled', 'updated_by' => $operator]);
        return $this->show($id);
    }

    public function copy(int $id, string $operator): ApprovalFormTemplate
    {
        $source = $this->show($id);
        $schema = (array) ($source->editing_version?->schema_snapshot ?? []);
        return $this->saveDraft(null, [
            'form_code' => $source->form_code.'_COPY_'.now()->format('His'),
            'form_name' => $source->form_name.'（复制）',
            'business_module' => $source->business_module,
            'description' => $source->description,
            'schema' => $schema,
        ], $operator);
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $form = ApprovalFormTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($form->submissions()->exists()) {
                throw ValidationException::withMessages(['form' => '该表单已有申请记录，不能删除；可以停用。']);
            }
            $referenced = ApprovalFormVersion::query()->where('form_template_id', $form->id)->where('version_status', 'published')->exists()
                || DB::table('erp_approval_flow_versions')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(definition_snapshot, '$.form_template_id')) = ?", [(string) $form->id])->exists();
            if ($referenced) {
                throw ValidationException::withMessages(['form' => '该表单已发布或已被审核流程引用，不能删除；可以停用。']);
            }
            $form->delete();
        });
    }

    public function submit(int $id, array $payload, object $user, ?int $flowTemplateId = null): ApprovalFormSubmission
    {
        return DB::transaction(function () use ($id, $payload, $user, $flowTemplateId) {
            $form = ApprovalFormTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($form->status !== 'enabled' || $form->current_version < 1) {
                throw ValidationException::withMessages(['form' => '自定义表单未启用，不能提交申请。']);
            }
            $version = ApprovalFormVersion::query()->where('form_template_id', $form->id)
                ->where('version_no', $form->current_version)->where('version_status', 'published')->firstOrFail();
            $data = (array) ($payload['form_data'] ?? []);
            $this->validateSubmission((array) $version->schema_snapshot, $data);
            $operator = (string) ($user->nickname ?: $user->username ?: '系统');
            $submission = ApprovalFormSubmission::create([
                'form_template_id' => $form->id, 'form_version_id' => $version->id,
                'subject' => trim((string) ($payload['subject'] ?? '')) ?: $form->form_name,
                'submission_status' => 'submitted', 'form_data' => $data,
                'submitted_by' => $user->legacy_id, 'submitted_by_name' => $operator, 'submitted_at' => now(),
            ]);
            $submission->update(['submission_no' => 'AF'.now()->format('Ymd').str_pad((string) $submission->id, 6, '0', STR_PAD_LEFT)]);

            $trigger = $this->triggers->dispatch('CUSTOM_FORM_SUBMISSION', $submission->id, 'submit_form', $user, [
                'source_mode' => 'custom_form', 'form_template_id' => $form->id,
            ], 'MANUAL_START', $flowTemplateId);
            $task = $trigger['task'] ?? null;
            $submission->setAttribute('approval_task', $task);
            return $submission;
        });
    }

    public function validateSchema(array $schema): array
    {
        $errors = [];
        $fields = array_values((array) ($schema['fields'] ?? []));
        if (!$fields) $errors[] = '表单至少需要一个字段。';
        $keys = [];
        $allowedTypes = ['text', 'textarea', 'number', 'date', 'select', 'multi_select', 'user', 'department', 'attachment', 'business_link'];
        foreach ($fields as $index => $field) {
            $prefix = '第'.($index + 1).'个字段';
            $key = trim((string) ($field['key'] ?? ''));
            if (!$key || !preg_match('/^[a-z][a-z0-9_]{1,79}$/', $key)) $errors[] = $prefix.'字段标识格式不正确。';
            if (in_array($key, $keys, true)) $errors[] = '字段标识重复：'.$key;
            $keys[] = $key;
            if (!trim((string) ($field['label'] ?? ''))) $errors[] = $prefix.'缺少字段名称。';
            if (!in_array((string) ($field['type'] ?? ''), $allowedTypes, true)) $errors[] = $prefix.'字段类型不支持。';
            if (in_array((string) ($field['type'] ?? ''), ['select', 'multi_select'], true) && empty($field['options'])) {
                $errors[] = $prefix.'为选择字段，必须配置选项。';
            }
        }
        return ['valid' => !$errors, 'errors' => array_values(array_unique($errors)), 'warnings' => []];
    }

    private function validateSubmission(array $schema, array $data): void
    {
        $rules = [];
        $attributes = [];
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if (!$key) continue;
            $rule = !empty($field['required']) ? ['required'] : ['nullable'];
            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'number') $rule[] = 'numeric';
            elseif ($type === 'date') $rule[] = 'date';
            elseif (in_array($type, ['multi_select', 'attachment'], true)) $rule[] = 'array';
            elseif ($type === 'select' && !empty($field['options'])) $rule[] = 'in:'.collect($field['options'])->pluck('value')->implode(',');
            else $rule[] = 'string';
            $rules[$key] = $rule;
            $attributes[$key] = (string) ($field['label'] ?? $key);
        }
        Validator::make($data, $rules, [], $attributes)->validate();
    }
}
