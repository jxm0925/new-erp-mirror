<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CrmAnalysisProvider;
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

    public function decryptAliyunOssSecret(Request $request, ThirdPartyServiceConfigService $service)
    {
        $this->authorizeActor($request, ['system.third_party_service.view', 'system.third_party_service.manage']);

        return response()->json([
            'message' => '密钥解密成功。',
            'data' => $service->decryptAliyunOssSecret($request->all()),
        ]);
    }

    public function showAi(Request $request, CrmAnalysisProvider $provider)
    {
        $this->authorizeActor($request, ['system.third_party_service.view','system.third_party_service.manage']);
        return ['data' => $provider->settings()];
    }

    public function saveAi(Request $request, CrmAnalysisProvider $provider)
    {
        $actor = $this->authorizeActor($request, ['system.third_party_service.manage']);
        return ['data' => $provider->save($request->all(), $actor)];
    }

    public function checkAi(Request $request, CrmAnalysisProvider $provider)
    {
        $this->authorizeActor($request, ['system.third_party_service.manage']);
        $provider->check($request->only(['service_code','provider','base_url','api_key','model','version']));
        return ['message' => '连接成功，模型已返回有效 JSON。', 'data' => $provider->settings()];
    }

    public function listAiModels(Request $request, CrmAnalysisProvider $provider)
    {
        $this->authorizeActor($request, ['system.third_party_service.manage']);
        return ['data' => $provider->models($request->only(['service_code','provider','base_url','api_key','version']))];
    }

    public function setDefaultAi(Request $request, string $serviceCode, CrmAnalysisProvider $provider)
    {
        $actor = $this->authorizeActor($request, ['system.third_party_service.manage']);
        $model = $request->input('model');

        return [
            'message' => '已切换为当前默认 AI 厂商。',
            'data' => $provider->setDefault($serviceCode, is_string($model) ? $model : null, $actor),
        ];
    }

    public function deleteAi(Request $request, string $serviceCode, CrmAnalysisProvider $provider)
    {
        $this->authorizeActor($request, ['system.third_party_service.manage']);

        return [
            'message' => '已删除该 AI 厂商配置。',
            'data' => $provider->delete($serviceCode),
        ];
    }

    public function decryptAiSecret(Request $request, CrmAnalysisProvider $provider)
    {
        $this->authorizeActor($request, ['system.third_party_service.view', 'system.third_party_service.manage']);

        return response()->json([
            'message' => '密钥解密成功。',
            'data' => $provider->decryptSecret($request->all()),
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
