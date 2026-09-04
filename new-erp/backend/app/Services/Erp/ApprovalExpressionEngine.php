<?php

namespace App\Services\Erp;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ApprovalExpressionEngine
{
    public function matches(array $expression, array $context, array $fieldTypes = []): bool
    {
        if ($expression === []) return true;
        if (array_is_list($expression)) return $this->matches(['logic' => 'AND', 'children' => $expression], $context, $fieldTypes);
        if (isset($expression['children'])) {
            $logic = strtoupper((string) ($expression['logic'] ?? 'AND'));
            if (!in_array($logic, ['AND', 'OR'], true)) throw ValidationException::withMessages(['conditions' => '条件组只支持 AND 或 OR。']);
            $children = array_values((array) $expression['children']);
            if (!$children) return true;
            return $logic === 'AND'
                ? collect($children)->every(fn ($child) => $this->matches((array) $child, $context, $fieldTypes))
                : collect($children)->contains(fn ($child) => $this->matches((array) $child, $context, $fieldTypes));
        }
        $field = (string) ($expression['field'] ?? '');
        $operator = $this->normalizeOperator((string) ($expression['operator'] ?? '='));
        $type = (string) ($expression['type'] ?? ($fieldTypes[$field] ?? 'string'));
        $actual = data_get($context, $field);
        $expected = $expression['value'] ?? null;
        if ($operator === 'empty') return $this->isEmpty($actual);
        if ($operator === 'not_empty') return !$this->isEmpty($actual);
        // Null never equals false/0/empty text and never participates in a
        // range or membership comparison. Nullability has explicit operators.
        if ($this->isEmpty($actual)) return false;
        if (in_array($operator, ['in', 'not_in'], true)) {
            $values = is_array($expected) ? $expected : [];
            try { $found = collect($values)->contains(fn ($value) => $this->compare($actual, $value, $type) === 0); }
            catch (ValidationException) { return false; }
            return $operator === 'in' ? $found : !$found;
        }
        if (in_array($operator, ['contains', 'not_contains'], true)) {
            $contains = is_array($actual) ? collect($actual)->flatten()->contains(fn ($value) => $this->compare($value, $expected, $type) === 0)
                : str_contains((string) $actual, (string) $expected);
            return $operator === 'contains' ? $contains : !$contains;
        }
        try { $comparison = $this->compare($actual, $expected, $type); }
        catch (ValidationException) { return false; }
        return match ($operator) {
            '=' => $comparison === 0, '!=' => $comparison !== 0, '>' => $comparison > 0,
            '>=' => $comparison >= 0, '<' => $comparison < 0, '<=' => $comparison <= 0,
            default => false,
        };
    }

    public function validate(array $expression, array $allowedFields, array $fieldTypes = []): array
    {
        $errors = [];
        $walk = function (array $node, string $path) use (&$walk, &$errors, $allowedFields, $fieldTypes) {
            if (isset($node['children'])) {
                if (!in_array(strtoupper((string) ($node['logic'] ?? '')), ['AND', 'OR'], true)) $errors[] = $path.'逻辑必须为 AND 或 OR。';
                foreach ((array) $node['children'] as $index => $child) $walk((array) $child, $path.'.'.($index + 1));
                return;
            }
            $field = (string) ($node['field'] ?? '');
            if (!$field || !in_array($field, $allowedFields, true)) $errors[] = $path.'字段未注册或不可用于条件。';
            $operator = $this->normalizeOperator((string) ($node['operator'] ?? ''));
            if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'contains', 'not_contains', 'empty', 'not_empty'], true)) $errors[] = $path.'运算符无效。';
            if (!in_array($operator, ['empty', 'not_empty'], true) && !array_key_exists('value', $node)) $errors[] = $path.'缺少比较值。';
            if (isset($node['type']) && isset($fieldTypes[$field]) && $node['type'] !== $fieldTypes[$field]) $errors[] = $path.'字段类型与注册定义不一致。';
            if (!in_array($operator, ['empty', 'not_empty'], true) && array_key_exists('value', $node)) {
                $type = (string) ($fieldTypes[$field] ?? ($node['type'] ?? 'string'));
                $value = $node['value'];
                if (in_array($operator, ['in', 'not_in'], true)) {
                    if (!is_array($value) || $value === []) $errors[] = $path.'属于/不属于的比较值必须是非空数组。';
                    else foreach (array_values($value) as $valueIndex => $member) {
                        if ($message = $this->invalidValueMessage($member, $type)) $errors[] = $path.'第'.($valueIndex + 1).'个比较值'.$message;
                    }
                } elseif ($message = $this->invalidValueMessage($value, $type)) {
                    $errors[] = $path.$message;
                }
            }
        };
        $walk($expression, '条件');
        return array_values(array_unique($errors));
    }

    private function normalizeOperator(string $operator): string
    {
        return ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator] ?? $operator;
    }

    private function compare(mixed $left, mixed $right, string $type): int
    {
        return match ($type) {
            'integer' => $this->integerValue($left) <=> $this->integerValue($right),
            'decimal', 'number' => $this->compareDecimal((string) $left, (string) $right),
            'boolean' => $this->booleanValue($left) <=> $this->booleanValue($right),
            'date' => $this->dateValue($left) <=> $this->dateValue($right),
            'datetime' => $this->datetimeValue($left) <=> $this->datetimeValue($right),
            default => strcmp((string) $left, (string) $right),
        };
    }

    private function invalidValueMessage(mixed $value, string $type): ?string
    {
        try {
            match ($type) {
                'integer' => $this->integerValue($value),
                'decimal', 'number' => $this->decimalParts((string) $value),
                'boolean' => $this->booleanValue($value),
                'date' => $this->dateValue($value),
                'datetime' => $this->datetimeValue($value),
                default => is_scalar($value) || $value === null ? true : throw ValidationException::withMessages(['conditions' => '无效文本']),
            };
            return null;
        } catch (\Throwable) {
            return match ($type) {
                'integer' => '必须是整数。', 'decimal', 'number' => '必须是合法小数。',
                'boolean' => '必须是 true/false 或 1/0。', 'date' => '必须是 YYYY-MM-DD 日期。',
                'datetime' => '必须是合法日期时间。', default => '类型无效。',
            };
        }
    }

    private function integerValue(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : trim((string) $value);
        if (!preg_match('/^[+-]?\d+$/', $text)) throw ValidationException::withMessages(['conditions' => '整数条件包含无效数值。']);
        return (int) $text;
    }

    private function booleanValue(mixed $value): int
    {
        if (is_bool($value)) return $value ? 1 : 0;
        if ($value === 1 || $value === '1' || $value === 'true') return 1;
        if ($value === 0 || $value === '0' || $value === 'false') return 0;
        throw ValidationException::withMessages(['conditions' => '布尔条件包含无效数值。']);
    }

    private function dateValue(mixed $value): CarbonImmutable
    {
        $text = trim((string) $value);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw ValidationException::withMessages(['conditions' => '日期条件包含无效数值。']);
        return $date;
    }

    private function datetimeValue(mixed $value): CarbonImmutable
    {
        $text = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?$/', $text)) {
            throw ValidationException::withMessages(['conditions' => '日期时间条件包含无效数值。']);
        }
        try { return CarbonImmutable::parse($text); }
        catch (\Throwable) { throw ValidationException::withMessages(['conditions' => '日期时间条件包含无效数值。']); }
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function compareDecimal(string $left, string $right): int
    {
        [$ls, $li, $lf] = $this->decimalParts($left); [$rs, $ri, $rf] = $this->decimalParts($right);
        if ($ls !== $rs) return $ls <=> $rs;
        $scale = max(strlen($lf), strlen($rf)); $l = ltrim($li.str_pad($lf, $scale, '0'), '0') ?: '0'; $r = ltrim($ri.str_pad($rf, $scale, '0'), '0') ?: '0';
        $cmp = strlen($l) <=> strlen($r); if ($cmp === 0) $cmp = strcmp($l, $r) <=> 0;
        return $ls < 0 ? -$cmp : $cmp;
    }

    private function decimalParts(string $value): array
    {
        $value = trim($value); $sign = str_starts_with($value, '-') ? -1 : 1; $value = ltrim($value, '+-');
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) throw ValidationException::withMessages(['conditions' => '小数条件包含无效数值。']);
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return [$integer === '0' && trim($fraction, '0') === '' ? 1 : $sign, ltrim($integer, '0') ?: '0', rtrim($fraction, '0')];
    }
}
