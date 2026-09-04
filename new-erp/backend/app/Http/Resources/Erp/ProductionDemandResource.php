<?php

namespace App\Http\Resources\Erp;

use App\DTO\Erp\ProductionDemandDto;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionDemandResource extends JsonResource
{
    public function toArray($request): array
    {
        $context = $request->attributes->get('erp_action_context', []);
        return ProductionDemandDto::fromModel(
            $this->resource,
            (array) ($context['permissions'] ?? []),
            (bool) ($context['super_admin'] ?? false),
        );
    }
}
