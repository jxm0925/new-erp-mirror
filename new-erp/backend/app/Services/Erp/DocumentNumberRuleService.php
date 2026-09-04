<?php

namespace App\Services\Erp;

use App\Models\Erp\DocumentNumber;
use App\Models\Erp\DocumentNumberRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentNumberRuleService
{
    private const EDITABLE = ['name', 'prefix', 'date_format', 'sequence_length', 'reset_cycle', 'enabled'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = DocumentNumberRule::query()->orderBy('document_type');
        if (!empty($filters['document_type'])) $query->where('document_type', $filters['document_type']);
        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', filter_var($filters['enabled'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('prefix', 'like', "%{$keyword}%"));
        }

        $paginator = $query->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 20))));
        $paginator->through(fn (DocumentNumberRule $rule) => $this->present($rule));
        return $paginator;
    }

    public function businessTypes(): array
    {
        return collect(config('erp_document_numbers.business_types', []))
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])
            ->values()->all();
    }

    public function create(array $data, object $operator): array
    {
        return DB::transaction(function () use ($data, $operator) {
            $this->assertBusinessType($data['document_type']);
            $this->assertConfiguration($data);
            if (DocumentNumberRule::where('document_type', $data['document_type'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['document_type' => '该业务类型已经存在编号规则。']);
            }
            $rule = DocumentNumberRule::create($this->normalize($data) + ['allow_manual_edit' => false]);
            $this->log('create', $rule, null, $rule->toArray(), $data['change_reason'] ?? null, $operator);
            return $this->present($rule->fresh());
        }, 5);
    }

    public function update(DocumentNumberRule $rule, array $data, object $operator): array
    {
        return DB::transaction(function () use ($rule, $data, $operator) {
            $locked = DocumentNumberRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $normalized = $this->normalize($data, $locked->document_type);
            $this->assertConfiguration($normalized);
            $old = Arr::only($locked->toArray(), self::EDITABLE);
            $new = Arr::only(array_merge($locked->toArray(), $normalized), self::EDITABLE);
            $changes = array_diff_assoc($new, $old);
            if (!$changes) throw ValidationException::withMessages(['rule' => '编号规则没有发生任何变化。']);
            $reason = trim((string) ($data['change_reason'] ?? ''));
            if ($reason === '') throw ValidationException::withMessages(['change_reason' => '规则实际发生变更时必须填写修改原因。']);
            if (mb_strlen($reason) > 200) throw ValidationException::withMessages(['change_reason' => '修改原因不能超过200个字符。']);
            $locked->update($normalized);
            $this->log('update', $locked, $old, $new, $reason, $operator);
            return $this->present($locked->fresh());
        }, 5);
    }

    public function setEnabled(DocumentNumberRule $rule, bool $enabled, object $operator): array
    {
        return DB::transaction(function () use ($rule, $enabled, $operator) {
            $locked = DocumentNumberRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if ((bool) $locked->enabled === $enabled) return $this->present($locked);
            if ($enabled) {
                $this->assertConfiguration($locked->toArray());
                $duplicate = DocumentNumberRule::where('document_type', $locked->document_type)
                    ->where('enabled', true)->whereKeyNot($locked->id)->exists();
                if ($duplicate) throw ValidationException::withMessages(['enabled' => '同一业务类型已存在启用中的编号规则。']);
            }
            $old = ['enabled' => (bool) $locked->enabled];
            $locked->update(['enabled' => $enabled]);
            $this->log($enabled ? 'enable' : 'disable', $locked, $old, ['enabled' => $enabled], null, $operator);
            return $this->present($locked->fresh());
        }, 5);
    }

    public function preview(array $data): array
    {
        $normalized = $this->normalize($data, $data['document_type'] ?? null);
        $this->assertConfiguration($normalized);
        return ['format_example' => $this->formatExample($normalized, 1)];
    }

    private function normalize(array $data, ?string $documentType = null): array
    {
        $result = Arr::only($data, self::EDITABLE);
        if ($documentType || isset($data['document_type'])) $result['document_type'] = $documentType ?: $data['document_type'];
        if (isset($result['prefix'])) $result['prefix'] = strtoupper(trim((string) $result['prefix']));
        if (array_key_exists('date_format', $result)) {
            $result['date_format'] = match ($result['date_format']) {
                'none', '' => '', 'YYYY', 'Y' => 'Y', 'YYYYMM', 'Ym' => 'Ym', 'YYYYMMDD', 'Ymd' => 'Ymd',
                default => (string) $result['date_format'],
            };
        }
        if (isset($result['enabled'])) $result['enabled'] = (bool) $result['enabled'];
        return $result;
    }

    private function assertBusinessType(string $type): void
    {
        if (!array_key_exists($type, config('erp_document_numbers.business_types', []))) {
            throw ValidationException::withMessages(['document_type' => '请选择系统提供的业务类型。']);
        }
    }

    private function assertConfiguration(array $data): void
    {
        $prefix = (string) ($data['prefix'] ?? '');
        if (!preg_match('/^[A-Z][A-Z0-9-]{0,19}$/', $prefix)) {
            throw ValidationException::withMessages(['prefix' => '编号前缀只能使用大写字母、数字和短横线，且必须以字母开头。']);
        }
        $length = (int) ($data['sequence_length'] ?? 0);
        if ($length < 1 || $length > 12) throw ValidationException::withMessages(['sequence_length' => '流水号长度必须为1至12位。']);
        $format = (string) ($data['date_format'] ?? '');
        $cycle = (string) ($data['reset_cycle'] ?? '');
        $safe = ['', 'Y', 'Ym', 'Ymd'];
        if (!in_array($format, $safe, true)) throw ValidationException::withMessages(['date_format' => '日期格式无效。']);
        $requiredCycle = ['' => 'none', 'Y' => 'yearly', 'Ym' => 'monthly', 'Ymd' => 'daily'][$format];
        if ($cycle !== $requiredCycle) {
            throw ValidationException::withMessages(['reset_cycle' => '日期格式与重置周期组合会产生重复编号风险，请按无日期/不重置、YYYY/每年、YYYYMM/每月、YYYYMMDD/每日配对。']);
        }
    }

    private function present(DocumentNumberRule $rule): array
    {
        $numberDate = match ($rule->reset_cycle) {
            'none' => '1970-01-01', 'yearly' => now()->startOfYear()->toDateString(),
            'monthly' => now()->startOfMonth()->toDateString(), default => now()->toDateString(),
        };
        $current = (int) (DocumentNumber::where('document_type', $rule->document_type)
            ->whereDate('number_date', $numberDate)->value('current_sequence') ?? 0);
        return [
            'id' => $rule->id, 'document_type' => $rule->document_type,
            'business_type_label' => config("erp_document_numbers.business_types.{$rule->document_type}", $rule->document_type),
            'name' => $rule->name, 'prefix' => $rule->prefix,
            'date_format' => $this->formatCode((string) $rule->date_format),
            'sequence_length' => (int) $rule->sequence_length, 'reset_cycle' => $rule->reset_cycle,
            'enabled' => (bool) $rule->enabled, 'current_sequence' => $current,
            'format_example' => $this->formatExample($rule->toArray(), max(1, $current)),
            'updated_at' => $rule->updated_at,
        ];
    }

    private function formatCode(string $format): string
    {
        return ['' => 'none', 'Y' => 'YYYY', 'Ym' => 'YYYYMM', 'Ymd' => 'YYYYMMDD'][$format] ?? $format;
    }

    private function formatExample(array $rule, int $sequence): string
    {
        $format = (string) ($rule['date_format'] ?? '');
        $date = $format !== '' ? now()->format($format) : '';
        return (string) ($rule['prefix'] ?? '').$date.str_pad((string) $sequence, (int) ($rule['sequence_length'] ?? 1), '0', STR_PAD_LEFT);
    }

    private function log(string $action, DocumentNumberRule $rule, ?array $old, ?array $new, ?string $reason, object $operator): void
    {
        DB::table('erp_operation_logs')->insert([
            'module' => 'document_number_rule', 'action' => $action,
            'target_type' => DocumentNumberRule::class, 'target_id' => $rule->id,
            'old_snapshot' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_snapshot' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'reason' => $reason, 'operator_id' => $operator->legacy_id ?? null,
            'operator_name' => $operator->nickname ?? $operator->username ?? null, 'created_at' => now(),
        ]);
    }
}
