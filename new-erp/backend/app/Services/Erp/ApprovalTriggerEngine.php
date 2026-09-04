<?php

namespace App\Services\Erp;

use App\Models\Erp\ApprovalFlowTemplate;
use App\Models\Erp\ApprovalFlowVersion;
use Illuminate\Support\Facades\DB;

class ApprovalTriggerEngine
{
    public function __construct(
        private readonly ApprovalBusinessObjectRegistry $objects,
        private readonly ApprovalBusinessEventRegistry $events,
        private readonly ApprovalExpressionEngine $expressions,
        private readonly ApprovalTaskApplicationService $tasks,
        private readonly ApprovalBusinessObjectAccessService $access,
        private readonly AuthContextService $auth,
    ) {}

    public function dispatch(string $objectCode, int $businessId, string $eventCode, object $initiator, array $metadata = [], string $mode = 'EVENT_TRIGGER', ?int $flowTemplateId = null): array
    {
        return DB::transaction(function () use ($objectCode, $businessId, $eventCode, $initiator, $metadata, $mode, $flowTemplateId) {
            $this->events->assertEnabled($objectCode, $eventCode, $mode);
            $object = $this->objects->find($objectCode);
            if ($mode === 'MANUAL_START') {
                $this->access->assertCanAccessRecord($object, $businessId, $initiator,
                    $this->auth->permissionCodes($initiator), $this->auth->isSuperAdmin($initiator));
            } else {
                // Business-event integrations are trusted server-side callers, but
                // still may never manufacture an approval for a missing source row.
                $this->access->assertRecordExists($object, $businessId);
            }
            $context = $this->objects->provider($object)->context($object, $businessId, $initiator);
            $types = $object->fields->pluck('field_type', 'field_code')->all();
            $flows = ApprovalFlowTemplate::query()->where('status', 'enabled')->where('business_object_code', $objectCode)
                ->where('event_code', $eventCode)
                ->when($flowTemplateId, fn ($query) => $query->whereKey($flowTemplateId))
                ->whereIn('trigger_mode', [$mode, 'BOTH'])->orderByDesc('priority')->orderBy('id')
                ->with(['versions' => fn ($q) => $q->where('version_status', 'published')->orderByDesc('version_no')])->get();
            foreach ($flows as $flow) {
                $version = $flow->versions->firstWhere('version_no', $flow->current_version) ?: $flow->versions->first();
                if (!$version instanceof ApprovalFlowVersion) continue;
                $definition = (array) $version->definition_snapshot;
                if (!$this->expressions->matches((array) ($definition['start_conditions'] ?? []), $context, $types)) continue;
                $task = $this->tasks->createForBusinessFlow($objectCode, [
                    'flow_template_id' => $flow->id, 'business_id' => $businessId, 'event_code' => $eventCode,
                    'subject' => isset($metadata['subject']) ? trim((string) $metadata['subject']) : null,
                    'business_no' => $metadata['business_no'] ?? null,
                    'source_route' => $metadata['source_route'] ?? null,
                    'risk_level' => $metadata['risk_level'] ?? null,
                    'diff_snapshot' => (array) ($metadata['diff_snapshot'] ?? []),
                    'metadata' => [...$metadata, 'trigger_mode' => $mode],
                ], $initiator);
                if ($task) return [
                    'result' => 'STARTED', 'task' => $task, 'flow_id' => $flow->id, 'flow_version_id' => $version->id,
                    'execution_mode' => $flow->execution_mode,
                    'business_action' => $flow->execution_mode === 'BEFORE_ACTION' ? 'BLOCKED_PENDING_APPROVAL' : 'PROCEED',
                ];
                if ($flow->match_strategy === 'FIRST_MATCH') break;
            }
            return ['result' => 'BYPASS', 'task' => null, 'business_action' => 'PROCEED', 'reason' => $flows->isEmpty() ? 'NO_MATCHING_FLOW' : 'START_CONDITION_FALSE'];
        });
    }
}
