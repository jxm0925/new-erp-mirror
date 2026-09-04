<?php

namespace App\Services\Erp\ApprovalProviders;

use App\Models\Erp\ApprovalBusinessObject;
use Illuminate\Support\Facades\DB;

class CustomFormSubmissionProvider extends DatabaseBusinessObjectProvider
{
    public function context(ApprovalBusinessObject $object, int|string $id, object $initiator): array
    {
        $context = parent::context($object, $id, $initiator);
        $formData = $this->formData($object, $id);
        return [...$context, ...$formData, 'form' => $formData];
    }

    public function snapshot(ApprovalBusinessObject $object, int|string $id, object $initiator): array
    {
        $row = $this->find($object, $id) ?: [];
        $formData = $this->formData($object, $id);
        return [
            'submission_no' => $row['submission_no'] ?? null, 'subject' => $row['subject'] ?? null,
            'submission_status' => $row['submission_status'] ?? null, 'form_data' => $formData,
        ];
    }

    private function formData(ApprovalBusinessObject $object, int|string $id): array
    {
        $raw = DB::table($object->source_table)->where($object->primary_key, $id)->value('form_data');
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
