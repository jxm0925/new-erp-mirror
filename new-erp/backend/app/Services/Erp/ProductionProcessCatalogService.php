<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionStage;
use App\Models\Erp\ProductionPackagingScheme;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Small maintainable process catalogs share one permission, command and audit boundary. */
class ProductionProcessCatalogService
{
    public function paginate(string $type, array $filters, array $permissions, bool $super): mixed
    {
        $this->authorize($permissions, $super, false);
        $model = $this->model($type);
        return $model::query()->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub->where('code', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('sort')->orderBy('id')->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function save(string $type, ?int $id, array $data, object $actor, array $permissions, bool $super): array
    {
        $this->authorize($permissions, $super, true);
        $model = $this->model($type);
        $actorId = (int) ($actor->legacy_id ?? $actor->id);
        $hash = hash('sha256', json_encode([$type, $id, $actorId, $data], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $commandId = $data['client_command_id'];
        try {
            return DB::transaction(function () use ($type, $id, $data, $actor, $actorId, $hash, $commandId, $model): array {
                if ($id) $model::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $existing = DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->first();
                if ($existing) return $this->replay($existing, $hash);
                DB::table('erp_production_catalog_commands')->insert(['client_command_id' => $commandId,
                    'request_hash' => $hash, 'catalog_type' => $type, 'operator_legacy_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                $row = $id ? $model::query()->whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
                if ($id && (int) $row->business_version !== (int) ($data['expected_version'] ?? 0)) throw ValidationException::withMessages(['expected_version' => '档案已变化，请刷新后重试。']);
                $before = $row->exists ? $row->toArray() : null;
                if (($data['status'] ?? 'enabled') === 'disabled' && $id) {
                    $field = $type === 'stages' ? 'production_stage_id' : 'packaging_scheme_id';
                    if (DB::table('erp_production_routing_operations as node')->join('erp_production_routings as route', 'route.id', '=', 'node.routing_id')
                        ->where('node.'.$field, $id)->where('route.status', 'active')->exists()) throw ValidationException::withMessages(['status' => '档案已被生效工艺路线引用，不能停用。']);
                }
                if ($model::where('code', trim($data['code']))->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) throw ValidationException::withMessages(['code' => '档案编码已存在。']);
                $row->fill(['code' => trim($data['code']), 'name' => trim($data['name']), 'status' => $data['status'] ?? 'enabled',
                    'sort' => $data['sort'] ?? 0, 'description' => $data['description'] ?? null,
                    'business_version' => $id ? (int) $row->business_version + 1 : 1, 'updated_by_legacy_id' => $actorId]);
                if (! $id) $row->created_by_legacy_id = $actorId;
                $row->save();
                $response = $row->toArray();
                DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'process_'.$type, 'aggregate_id' => $row->id,
                    'action' => $id ? 'update' : 'create', 'before_status' => $before['status'] ?? null, 'after_status' => $row->status,
                    'before_version' => $before['business_version'] ?? 0, 'after_version' => $row->business_version,
                    'fact_snapshot' => json_encode(['before' => $before, 'after' => $response], JSON_UNESCAPED_UNICODE),
                    'operator_legacy_id' => $actorId, 'operator_name' => $actor->nickname ?? $actor->username ?? null,
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->update(['entity_id' => $row->id,
                    'response_snapshot' => json_encode($response, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->first();
            if (! $existing) throw $e;
            return $this->replay($existing, $hash);
        }
    }

    private function replay(object $command, string $hash): array
    {
        if ($command->request_hash !== $hash) throw ValidationException::withMessages(['client_command_id' => '请求标识已用于其他操作。']);
        if (! $command->response_snapshot) throw ValidationException::withMessages(['client_command_id' => '请求正在处理，请稍后重试。']);
        return json_decode($command->response_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    private function model(string $type): string
    {
        return match ($type) { 'stages' => ProductionStage::class, 'packaging-schemes' => ProductionPackagingScheme::class,
            default => throw ValidationException::withMessages(['type' => '不支持的工艺档案类型。']) };
    }

    private function authorize(array $permissions, bool $super, bool $write): void
    {
        $required = $write ? ['production.routing.create', 'production.routing.edit'] : ['production.routing.view', 'production.work_order.view', 'production.task.view'];
        if (! $super && array_intersect($required, $permissions) === []) abort(403, '当前用户没有工艺档案权限。');
    }
}
