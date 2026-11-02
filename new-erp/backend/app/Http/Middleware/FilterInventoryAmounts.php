<?php

namespace App\Http\Middleware;

use App\Services\Erp\InventoryAmountVisibilityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FilterInventoryAmounts
{
    public function __construct(private readonly InventoryAmountVisibilityService $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && $request->is('api/v1/erp/*') && ! $this->visibility->canView($request)) {
            // Decode as objects: empty JSON objects must not turn into lists when hidden fields are removed.
            $response->setData($this->visibility->forResponse($response->getData(), $request));
        }
        return $response;
    }
}
