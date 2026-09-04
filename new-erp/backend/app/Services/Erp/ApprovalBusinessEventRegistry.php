<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalBusinessEvent;
use Illuminate\Validation\ValidationException;

class ApprovalBusinessEventRegistry
{
    public function assertEnabled(string $objectCode, string $eventCode, string $mode): ApprovalBusinessEvent
    {
        $event = ApprovalBusinessEvent::query()->whereHas('businessObject', fn ($q) => $q->where('object_code', $objectCode)->where('enabled', true))
            ->where('event_code', $eventCode)->where('enabled', true)->first();
        if (!$event) throw ValidationException::withMessages(['event_code' => '业务事件未注册、已停用或不属于当前业务对象。']);
        if ($mode === 'MANUAL_START' && !$event->manual_start_allowed) throw ValidationException::withMessages(['trigger_mode' => '该业务事件不允许手动发起。']);
        if ($mode === 'EVENT_TRIGGER' && !$event->event_trigger_allowed) throw ValidationException::withMessages(['trigger_mode' => '该业务事件不允许自动触发。']);
        return $event;
    }
}
