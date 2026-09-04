<?php

namespace App\Http\Resources\Erp;

use App\DTO\Erp\WorkOrderDto;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $context = $request->attributes->get('erp_action_context', []);
        return WorkOrderDto::fromModel(
            $this->resource,
            (array) ($context['permissions'] ?? []),
            (bool) ($context['super_admin'] ?? false),
        );
    }
}
