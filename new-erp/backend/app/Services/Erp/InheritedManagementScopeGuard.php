<?php

namespace App\Services\Erp;

use Illuminate\Validation\ValidationException;

/** Validate inherited facts without classifying historical unknown scope as factory or office. */
final class InheritedManagementScopeGuard
{
    public static function nullable(mixed $value): ?string
    {
        // Legacy return/exchange sources may be unknown. CHECK permits NULL, but
        // non-null corruption must not propagate into a newly created document.
        if ($value !== null && (!is_string($value) || !in_array($value, ['factory', 'office'], true))) {
            throw ValidationException::withMessages([
                'management_scope' => '来源单据的管理范围无效，不能生成新的采购单据，请先核对来源。',
            ]);
        }

        return $value;
    }
}
