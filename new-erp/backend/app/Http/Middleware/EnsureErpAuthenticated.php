<?php

namespace App\Http\Middleware;

use App\Services\Erp\AuthContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpAuthenticated
{
    public function __construct(private readonly AuthContextService $authContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Login must remain public; all other ERP API calls require a valid token
        // (or a signed internal request accepted by AuthContextService).
        if ($request->isMethod('OPTIONS') || ($request->is('api/v1/erp/auth/login') && $request->isMethod('POST'))) {
            return $next($request);
        }

        $user = $this->authContext->currentUser($request);
        if (!$user) {
            return response()->json(['message' => '未登录或登录已过期。', 'error_code' => 'unauthenticated', 'errors' => ['unauthenticated' => ['需要有效的 ERP 登录令牌。']], 'details' => []], 401);
        }

        $request->attributes->set('erp_user', $user);
        $request->attributes->set('erp_legacy_id', (int) $user->legacy_id);
        // Broadcasting uses Request::user() when it authorizes a private channel.
        // ERP authentication is token based and stores a legacy-user snapshot, so bridge it here.
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
