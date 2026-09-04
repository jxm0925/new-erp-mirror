<?php

namespace App\Services\Erp\ApprovalProviders;

use App\Models\Erp\ApprovalBusinessObject;
use App\Services\Erp\Contracts\ApprovalBusinessObjectProvider;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DatabaseBusinessObjectProvider implements ApprovalBusinessObjectProvider
{
    public function find(ApprovalBusinessObject $object, int|string $id): ?array
    {
        $allowed = $this->exposedFields($object)->pluck('source_path')->push($object->primary_key)->filter()->unique()->values()->all();
        $row = DB::table($object->source_table)->select($allowed)->where($object->primary_key, $id)->first();
        return $row ? $this->decodeJson($object, (array) $row) : null;
    }

    public function context(ApprovalBusinessObject $object, int|string $id, object $initiator): array
    {
        $row = $this->find($object, $id);
        if (!$row) return [];
        return [
            'business' => $row,
            'initiator' => ['id' => $initiator->legacy_id ?? null, 'name' => $initiator->nickname ?? $initiator->username ?? null],
            'system' => ['now' => now()->toDateTimeString(), 'today' => today()->toDateString()],
            ...$row,
        ];
    }

    public function snapshot(ApprovalBusinessObject $object, int|string $id, object $initiator): array
    {
        $row = $this->find($object, $id) ?: [];
        $allowed = $object->fields->where('display_enabled', true)->pluck('source_path')->all();
        return collect($row)->only($allowed ?: array_keys($row))->all();
    }

    public function paginate(ApprovalBusinessObject $object, array $filters): LengthAwarePaginator
    {
        $fields = $this->exposedFields($object)->pluck('source_path')->all();
        $columns = collect([$object->primary_key])->merge($object->display_fields ?: [])->filter(fn ($field) => in_array($field, $fields, true))->unique()->values()->all();
        $query = DB::table($object->source_table)->select($columns ?: [$object->primary_key]);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $search = collect($object->search_fields ?: [])->filter(fn ($field) => in_array($field, $fields, true))->values();
            if ($search->isNotEmpty()) $query->where(function ($where) use ($search, $keyword) {
                foreach ($search as $index => $field) $index ? $where->orWhere($field, 'like', "%{$keyword}%") : $where->where($field, 'like', "%{$keyword}%");
            });
        }
        return $query->orderByDesc($object->primary_key)->paginate(min(max((int) ($filters['per_page'] ?? 10), 1), 50));
    }

    private function decodeJson(ApprovalBusinessObject $object, array $row): array
    {
        foreach ($object->fields->where('field_type', 'json') as $field) {
            $key = $field->source_path;
            if (!isset($row[$key]) || !is_string($row[$key])) continue;
            $decoded = json_decode($row[$key], true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$key] = $decoded;
        }
        return $row;
    }

    private function exposedFields(ApprovalBusinessObject $object)
    {
        return $object->fields->filter(fn ($field) => $field->condition_enabled
            || $field->display_enabled || $field->reference_enabled || $field->approval_writable);
    }
}
