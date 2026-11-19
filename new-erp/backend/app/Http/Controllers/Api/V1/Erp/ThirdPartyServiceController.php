<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ThirdPartyServiceConfigService;
use Illuminate\Http\Request;

class ThirdPartyServiceController extends Controller
{
    public function showAliyunOss(Request $request, ThirdPartyServiceConfigService $service)
    {
        $this->authorizeActor($request, ['system.third_party_service.view', 'system.third_party_service.manage']);

        return response()->json(['data' => $service->getAliyunOssConfig()]);
    }

    public function saveAliyunOss(Request $request, ThirdPartyServiceConfigService $service)
    {
        $actor = $this->authorizeActor($request, ['system.third_party_service.manage']);
        $saved = $service->saveAliyunOss($request->all(), $actor);

        return response()->json([
            'message' => '阿里云 OSS 配置已保存。',
            'data' => $saved,
        ]);
    }

    public function rotateAliyunOssSecret(Request $request, ThirdPartyServiceConfigService $service)
    {
        $actor = $this->authorizeActor($request, ['system.third_party_service.manage']);
        $result = $service->rotateAliyunOssSecret($request->all(), $actor);

        return response()->json([
            'message' => '访问密钥已加密更新。',
            'data' => $result['service'],
            'check' => $result['check'],
        ]);
    }

    public function checkAliyunOssConnection(Request $request, ThirdPartyServiceConfigService $service)
    {
        $actor = $this->authorizeActor($request, ['system.third_party_service.manage']);
        $result = $service->checkAliyunOssConnection($request->all(), $actor);

        return response()->json([
            'message' => $result['check']['message'],
            'ok' => $result['check']['ok'],
            'check' => $result['check'],
            'data' => $result['service'],
        ]);
    }

    private function authorizeActor(Request $request, array $required): object
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        $permissions = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        abort_unless($super || array_intersect($required, $permissions), 403, '没有执行第三方服务配置操作的权限。');

        return $user;
    }
}
