<?php

declare(strict_types=1);

namespace App\Services\Erp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CrmAnalysisProvider
{
    public const SERVICE_CODE = 'crm_analysis_ai';
    public const CATEGORY = 'ai';

    /** @var array<int, array<string, mixed>> */
    public const PROVIDER_PRESETS = [
        [
            'code' => 'deepseek',
            'name' => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com',
            'default_model' => 'deepseek-chat',
            'editable_url' => false,
        ],
        [
            'code' => 'qwen',
            'name' => '通义千问（阿里云百炼）',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'default_model' => 'qwen-plus',
            'editable_url' => true,
        ],
        [
            'code' => 'zhipu',
            'name' => '智谱 GLM',
            'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
            'default_model' => 'glm-4-plus',
            'editable_url' => true,
        ],
        [
            'code' => 'moonshot',
            'name' => 'Kimi（Moonshot）',
            'base_url' => 'https://api.moonshot.cn/v1',
            'default_model' => 'moonshot-v1-8k',
            'editable_url' => true,
        ],
        [
            'code' => 'doubao',
            'name' => '豆包（火山方舟）',
            'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'default_model' => '',
            'editable_url' => true,
        ],
        [
            'code' => 'openai',
            'name' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'gpt-4o-mini',
            'editable_url' => true,
        ],
        [
            'code' => 'compatible',
            'name' => '自定义兼容服务',
            'base_url' => '',
            'default_model' => '',
            'editable_url' => true,
        ],
    ];

    private const ALLOWED_PROVIDERS = ['deepseek', 'qwen', 'zhipu', 'moonshot', 'doubao', 'openai', 'compatible'];

    private readonly ThirdPartySecretVaultService $vault;

    public function __construct(?ThirdPartySecretVaultService $vault = null)
    {
        $this->vault = $vault ?? new ThirdPartySecretVaultService();
    }

    private function aiQuery()
    {
        return DB::table('erp_third_party_services')->where('category', self::CATEGORY);
    }

    private function recordByCode(string $serviceCode)
    {
        return DB::table('erp_third_party_services')
            ->where('category', self::CATEGORY)
            ->where('service_code', $serviceCode);
    }

    public function settings(): array
    {
        $rows = $this->aiQuery()->orderBy('id')->get();
        $providers = [];
        foreach ($rows as $row) {
            $providers[] = $this->formatRow($row);
        }

        // 确保只有一个默认标记用于前端展示；若尚无默认标记则优先选中已启用项或第一项
        $defaultIdx = null;
        foreach ($providers as $idx => $item) {
            if (! empty($item['is_default']) && ! empty($item['enabled'])) {
                $defaultIdx = $idx;
                break;
            }
        }
        if ($defaultIdx === null) {
            foreach ($providers as $idx => $item) {
                if (! empty($item['enabled'])) {
                    $defaultIdx = $idx;
                    break;
                }
            }
        }
        if ($defaultIdx === null) {
            foreach ($providers as $idx => $item) {
                if (! empty($item['is_default'])) {
                    $defaultIdx = $idx;
                    break;
                }
            }
        }
        if ($defaultIdx === null && count($providers) > 0) {
            $defaultIdx = 0;
        }

        foreach ($providers as $idx => &$item) {
            $item['is_default'] = ($defaultIdx === $idx);
        }
        unset($item);

        $active = $defaultIdx !== null ? $providers[$defaultIdx] : null;
        $enabledCount = count(array_filter($providers, static fn (array $p): bool => ! empty($p['enabled'])));

        return [
            'configured' => count($providers) > 0,
            'enabled' => $active ? (bool) $active['enabled'] : ($enabledCount > 0),
            'enabled_count' => $enabledCount,
            'active_service_code' => $active['service_code'] ?? '',
            'active_vendor_name' => $active['vendor_name'] ?? '',
            'active_model' => $active['model'] ?? '',
            // 顶层兼容字段，便于左侧目录等处读取
            'provider' => $active['provider'] ?? 'deepseek',
            'base_url' => $active['base_url'] ?? 'https://api.deepseek.com',
            'model' => $active['model'] ?? 'deepseek-chat',
            'version' => (int) ($active['version'] ?? 0),
            'provider_presets' => self::PROVIDER_PRESETS,
            'providers' => $providers,
        ];
    }

    public function save(array $data, object $actor): array
    {
        $data = Validator::make($data, [
            'service_code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'provider' => 'required|in:'.implode(',', self::ALLOWED_PROVIDERS),
            'vendor_name' => 'nullable|string|max:80',
            'base_url' => 'required|url:https|max:400',
            'api_key' => 'nullable|string|max:4096',
            'model' => 'nullable|string|max:120',
            'models' => 'nullable|array|max:100',
            'models.*' => 'string|max:120',
            'available_models' => 'nullable|array|max:500',
            'available_models.*.id' => 'required|string|max:120',
            'available_models.*.name' => 'nullable|string|max:160',
            'enabled' => 'required|boolean',
            'is_default' => 'nullable|boolean',
            'version' => 'nullable|integer|min:0',
            'encryption_password' => 'nullable|string|min:4|max:128',
        ], [
            'provider.in' => '不支持的 AI 厂商类型。',
            'base_url.required' => '请填写 HTTPS API 基础地址。',
            'base_url.url' => '请填写有效的 HTTPS API 基础地址。',
            'encryption_password.min' => '密钥加密密码长度不能少于 4 位。',
        ])->validate();

        $providerCode = (string) $data['provider'];
        $base = $this->baseUrl($providerCode, (string) $data['base_url']);
        $preset = $this->presetByCode($providerCode);
        $vendorName = trim((string) ($data['vendor_name'] ?? '')) ?: (string) ($preset['name'] ?? '自定义兼容服务');

        $selectedModels = $this->normalizeModelIds($data['models'] ?? []);
        $primaryModel = trim((string) ($data['model'] ?? ''));
        if ($primaryModel === '' && count($selectedModels) > 0) {
            $primaryModel = $selectedModels[0];
        }
        if ($primaryModel !== '' && ! in_array($primaryModel, $selectedModels, true)) {
            array_unshift($selectedModels, $primaryModel);
        }

        abort_if($data['enabled'] && $primaryModel === '', 422, '启用服务前请选择或填写默认模型。');

        $incomingAvailable = isset($data['available_models']) && is_array($data['available_models'])
            ? $this->normalizeCatalogModels($data['available_models'])
            : null;

        $savedCode = '';

        DB::transaction(function () use ($actor, $data, $providerCode, $vendorName, $base, $primaryModel, $selectedModels, $incomingAvailable, &$savedCode): void {
            $now = now();
            $requestedCode = trim((string) ($data['service_code'] ?? ''));
            $isNew = $requestedCode === '';

            if ($isNew) {
                $hasLegacy = $this->recordByCode(self::SERVICE_CODE)->exists();
                $serviceCode = $hasLegacy
                    ? 'ai_'.$providerCode.'_'.strtolower(Str::random(6))
                    : self::SERVICE_CODE;
                $row = null;
            } else {
                $serviceCode = $requestedCode;
                $row = $this->recordByCode($serviceCode)->lockForUpdate()->first();
                if ($row) {
                    $expectedVersion = isset($data['version']) ? (int) $data['version'] : null;
                    if ($expectedVersion !== null && (int) $row->business_version !== $expectedVersion) {
                        abort(409, '配置已被其他管理员更新，请刷新后重试。');
                    }
                }
            }

            $existingCfg = $row && $row->config_json ? (json_decode((string) $row->config_json, true) ?: []) : [];
            $sameEndpoint = $row !== null
                && (string) ($existingCfg['provider'] ?? '') === $providerCode
                && rtrim((string) ($existingCfg['base_url'] ?? ''), '/') === $base;

            $secret = $sameEndpoint ? ($row->encrypted_credentials ?? null) : null;
            $mask = null;
            if ($secret !== null && $secret !== '') {
                $unpacked = $this->vault->unpack((string) $secret, 'api_key');
                $mask = (string) ($unpacked['masks']['api_key'] ?? ($row->credential_mask ?? ''));
            }

            if (filled($data['api_key'] ?? null)) {
                $encryptionPassword = (string) ($data['encryption_password'] ?? '');
                abort_if($encryptionPassword === '', 422, '保存新 API Key 时请设置加密密码（至少 4 位），用于后续解密复制。');
                $trimmedKey = trim((string) $data['api_key']);
                $secret = $this->vault->encrypt($serviceCode, ['api_key' => $trimmedKey], $encryptionPassword);
                $mask = ThirdPartySecretVaultService::maskSecret($trimmedKey);
            }

            abort_if($data['enabled'] && empty($secret), 422, '启用服务或更换接口地址时请填写 API Key。');

            // 保留已缓存的厂商模型目录（同接口地址时），若本次传入了最新目录则覆盖
            $availableModels = $incomingAvailable !== null
                ? $incomingAvailable
                : ($sameEndpoint ? $this->normalizeCatalogModels($existingCfg['available_models'] ?? []) : []);

            $wantsDefault = array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : (bool) ($existingCfg['is_default'] ?? false);

            $otherRows = $this->aiQuery()->where('service_code', '!=', $serviceCode)->lockForUpdate()->get();
            if ($otherRows->isEmpty()) {
                $wantsDefault = true;
            } elseif ($data['enabled'] && ! $otherRows->contains(fn ($r) => (bool) $r->enabled)) {
                $wantsDefault = true;
            }

            if ($wantsDefault) {
                foreach ($otherRows as $other) {
                    $otherCfg = json_decode((string) $other->config_json, true) ?: [];
                    if (! empty($otherCfg['is_default'])) {
                        $otherCfg['is_default'] = false;
                        DB::table('erp_third_party_services')
                            ->where('id', $other->id)
                            ->update(['config_json' => json_encode($otherCfg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                    }
                }
            }

            $configJson = [
                'provider' => $providerCode,
                'vendor_name' => $vendorName,
                'base_url' => $base,
                'model' => $primaryModel,
                'models' => $selectedModels,
                'available_models' => $availableModels,
                'is_default' => $wantsDefault,
            ];

            $actorId = (int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null;
            $actorName = (string) ($actor->nickname ?: $actor->username ?: '系统管理员');

            if ($row) {
                DB::table('erp_third_party_services')
                    ->where('id', $row->id)
                    ->update([
                        'provider_name' => $vendorName,
                        'enabled' => (bool) $data['enabled'],
                        'config_json' => json_encode($configJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'encrypted_credentials' => $secret,
                        'credential_mask' => $mask,
                        'updated_by_legacy_id' => $actorId,
                        'updated_by_name' => $actorName,
                        'business_version' => (int) $row->business_version + 1,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('erp_third_party_services')->insert([
                    'service_code' => $serviceCode,
                    'category' => self::CATEGORY,
                    'provider_name' => $vendorName,
                    'enabled' => (bool) $data['enabled'],
                    'config_json' => json_encode($configJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'encrypted_credentials' => $secret,
                    'credential_mask' => $mask,
                    'business_version' => 1,
                    'updated_by_legacy_id' => $actorId,
                    'updated_by_name' => $actorName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $savedCode = $serviceCode;
        }, 3);

        $settings = $this->settings();
        $settings['saved_service_code'] = $savedCode;

        return $settings;
    }

    public function setDefault(string $serviceCode, ?string $modelOverride, object $actor): array
    {
        DB::transaction(function () use ($serviceCode, $modelOverride, $actor): void {
            $target = $this->recordByCode($serviceCode)->lockForUpdate()->first();
            abort_unless($target, 404, '未找到指定的 AI 厂商配置。');

            $cfg = json_decode((string) $target->config_json, true) ?: [];
            $model = trim((string) ($modelOverride ?? $cfg['model'] ?? ''));
            abort_if($model === '', 422, '请先为该厂商选择模型后再设为默认。');
            abort_if(empty($target->encrypted_credentials), 422, '请先为该厂商保存 API Key 后再设为默认。');

            $models = $this->normalizeModelIds($cfg['models'] ?? []);
            if (! in_array($model, $models, true)) {
                array_unshift($models, $model);
            }
            $cfg['model'] = $model;
            $cfg['models'] = $models;
            $cfg['is_default'] = true;

            $others = $this->aiQuery()->where('id', '!=', $target->id)->lockForUpdate()->get();
            foreach ($others as $other) {
                $otherCfg = json_decode((string) $other->config_json, true) ?: [];
                if (! empty($otherCfg['is_default'])) {
                    $otherCfg['is_default'] = false;
                    DB::table('erp_third_party_services')
                        ->where('id', $other->id)
                        ->update(['config_json' => json_encode($otherCfg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
            }

            DB::table('erp_third_party_services')
                ->where('id', $target->id)
                ->update([
                    'enabled' => true,
                    'config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'business_version' => (int) $target->business_version + 1,
                    'updated_by_legacy_id' => (int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null,
                    'updated_by_name' => (string) ($actor->nickname ?: $actor->username ?: '系统管理员'),
                    'updated_at' => now(),
                ]);
        }, 3);

        return $this->settings();
    }

    public function delete(string $serviceCode): array
    {
        DB::transaction(function () use ($serviceCode): void {
            $target = $this->recordByCode($serviceCode)->lockForUpdate()->first();
            abort_unless($target, 404, '未找到指定的 AI 厂商配置。');

            DB::table('erp_third_party_services')->where('id', $target->id)->delete();

            // 若删除的是默认项，顺延一个已启用项或剩余第一项为默认
            $remaining = $this->aiQuery()->orderBy('id')->lockForUpdate()->get();
            if ($remaining->isNotEmpty()) {
                $hasDefault = $remaining->contains(function ($r): bool {
                    $c = json_decode((string) $r->config_json, true) ?: [];
                    return ! empty($c['is_default']);
                });
                if (! $hasDefault) {
                    $promote = $remaining->first(fn ($r) => (bool) $r->enabled) ?: $remaining->first();
                    if ($promote) {
                        $c = json_decode((string) $promote->config_json, true) ?: [];
                        $c['is_default'] = true;
                        DB::table('erp_third_party_services')
                            ->where('id', $promote->id)
                            ->update(['config_json' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                    }
                }
            }
        }, 3);

        return $this->settings();
    }

    public function decryptSecret(array $payload): array
    {
        $data = Validator::make($payload, [
            'service_code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'password' => 'required|string|min:1|max:128',
        ], [
            'password.required' => '请输入保存密钥时设置的加密密码。',
        ])->validate();

        $serviceCode = trim((string) ($data['service_code'] ?? '')) ?: self::SERVICE_CODE;
        $row = $this->recordByCode($serviceCode)->first();
        $secrets = $this->vault->decryptWithPassword(
            $serviceCode,
            $row ? (string) $row->encrypted_credentials : null,
            (string) $data['password'],
            'api_key'
        );

        return [
            'api_key' => (string) ($secrets['api_key'] ?? ''),
        ];
    }

    public function models(array $input): array
    {
        $config = $this->probeConfig($input, false);
        try {
            $response = $this->client($config['api_key'])->timeout(20)->get($config['base_url'].'/models');
        } catch (ConnectionException) {
            abort(502, '模型服务无法连接，请检查地址、网络和 HTTPS 证书。');
        }
        if ($config['provider'] !== 'deepseek' && in_array($response->status(), [404, 405, 501], true)) {
            return ['models' => [], 'listing_supported' => false];
        }
        abort_if(in_array($response->status(), [401, 403], true), 502, '服务商拒绝查询模型，请检查 API Key 和权限。');
        abort_unless($response->successful(), 502, '查询模型失败，服务商返回 HTTP '.$response->status().'。');
        $rows = $response->json('data');
        abort_unless(is_array($rows) && array_is_list($rows), 502, '服务商返回的模型列表格式不正确。');

        $models = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 120) {
                continue;
            }
            if (isset($row['output_modalities']) && is_array($row['output_modalities']) && ! in_array('text', $row['output_modalities'], true)) {
                continue;
            }
            $name = $row['name'] ?? null;
            $models[$id] = ['id' => $id, 'name' => is_string($name) && trim($name) !== '' ? $name : $id];
        }
        $list = array_values($models);

        // 若该厂商记录已存在且接口地址一致，自动持久化缓存厂商返回的模型目录，刷新页面也能回显
        if (! empty($config['service_code'])) {
            $existing = $this->recordByCode($config['service_code'])->first();
            if ($existing) {
                $existingCfg = json_decode((string) $existing->config_json, true) ?: [];
                $same = (string) ($existingCfg['provider'] ?? '') === $config['provider']
                    && rtrim((string) ($existingCfg['base_url'] ?? ''), '/') === $config['base_url'];
                if ($same) {
                    $existingCfg['available_models'] = $list;
                    DB::table('erp_third_party_services')
                        ->where('id', $existing->id)
                        ->update(['config_json' => json_encode($existingCfg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
            }
        }

        return ['models' => $list, 'listing_supported' => true];
    }

    public function check(array $input): void
    {
        $instructions = '仅返回 JSON {"ok":true}。';
        $testedModel = null;
        $serviceCode = null;
        if ($input === []) {
            $generated = $this->generate($instructions, ['check' => true]);
            $result = $generated['output'];
            $testedModel = $generated['model'] ?? null;
            $serviceCode = $generated['service_code'] ?? null;
        } else {
            $config = $this->probeConfig($input, true);
            $testedModel = $config['model'] ?? null;
            $serviceCode = $config['service_code'] ?? null;
            $result = $this->completion($config, $config['api_key'], $instructions, ['check' => true]);
        }
        abort_unless(($result['ok'] ?? null) === true, 502, '模型未返回预期的 JSON 检测结果。');

        if ($serviceCode) {
            $row = $this->recordByCode($serviceCode)->first();
            if ($row) {
                DB::table('erp_third_party_services')
                    ->where('id', $row->id)
                    ->update([
                        'last_check_status' => 'ok',
                        'last_check_message' => mb_substr(($testedModel ? "模型 {$testedModel} " : '').'已通过 JSON 对话测试。', 0, 500),
                        'last_checked_at' => now(),
                    ]);
            }
        }
    }

    public function generate(string $instructions, array $facts, ?string $serviceCode = null, ?string $modelOverride = null): array
    {
        $row = null;
        if ($serviceCode) {
            $row = $this->recordByCode($serviceCode)->where('enabled', 1)->first();
        } else {
            $enabledRows = $this->aiQuery()->where('enabled', 1)->orderBy('id')->get();
            foreach ($enabledRows as $candidate) {
                if (empty($candidate->encrypted_credentials)) {
                    continue;
                }
                $c = json_decode((string) $candidate->config_json, true) ?: [];
                if (! empty($c['is_default'])) {
                    $row = $candidate;
                    break;
                }
            }
            if (! $row) {
                $row = $enabledRows->first(fn ($r) => ! empty($r->encrypted_credentials));
            }
        }

        abort_unless($row && $row->enabled && $row->encrypted_credentials, 422, '请先在第三方服务配置中启用至少一个 AI 模型服务。');
        $config = json_decode((string) $row->config_json, true, flags: JSON_THROW_ON_ERROR);
        if ($modelOverride !== null && trim($modelOverride) !== '') {
            $config['model'] = trim($modelOverride);
        }
        abort_if(empty($config['model']), 422, '当前 AI 厂商尚未设置默认模型。');

        $result = $this->completion($config, $this->extractRuntimeApiKey($row->encrypted_credentials), $instructions, $facts);

        return [
            'service_code' => $row->service_code,
            'model' => $config['model'],
            'provider' => $row->provider_name,
            'at' => now()->toDateTimeString(),
            'output' => $result,
        ];
    }

    private function formatRow(object $row): array
    {
        $cfg = $row->config_json ? (json_decode((string) $row->config_json, true) ?: []) : [];
        $providerCode = (string) ($cfg['provider'] ?? 'deepseek');
        $preset = $this->presetByCode($providerCode);
        $unpacked = $this->vault->unpack($row->encrypted_credentials, 'api_key');
        $hasKey = $unpacked !== null && ! empty($unpacked['runtime']['api_key']);
        $hasSecretPassword = (bool) ($unpacked['has_password'] ?? false);

        $model = trim((string) ($cfg['model'] ?? ''));
        $selectedModels = $this->normalizeModelIds($cfg['models'] ?? []);
        if ($model !== '' && ! in_array($model, $selectedModels, true)) {
            array_unshift($selectedModels, $model);
        }

        return [
            'service_code' => (string) $row->service_code,
            'provider' => $providerCode,
            'vendor_name' => trim((string) ($cfg['vendor_name'] ?? $row->provider_name ?? '')) ?: (string) ($preset['name'] ?? 'AI 服务'),
            'base_url' => (string) ($cfg['base_url'] ?? ($preset['base_url'] ?? '')),
            'model' => $model,
            'models' => $selectedModels,
            'available_models' => $this->normalizeCatalogModels($cfg['available_models'] ?? []),
            'is_default' => (bool) ($cfg['is_default'] ?? false),
            'configured' => true,
            'enabled' => (bool) $row->enabled,
            'has_key' => $hasKey,
            'has_secret_password' => $hasSecretPassword,
            'api_key_masked' => (string) ($unpacked['masks']['api_key'] ?? ($row->credential_mask ?? '')),
            'encryption_algorithm' => $hasSecretPassword ? 'SHA-256 + AES-256-GCM' : 'AES-256',
            'last_check_status' => $row->last_check_status ?? null,
            'last_check_message' => $row->last_check_message ?? null,
            'last_checked_at' => $row->last_checked_at ? (string) $row->last_checked_at : null,
            'updated_by_name' => $row->updated_by_name ?? null,
            'updated_at' => $row->updated_at ? (string) $row->updated_at : null,
            'version' => (int) ($row->business_version ?? 0),
        ];
    }

    private function presetByCode(string $code): ?array
    {
        foreach (self::PROVIDER_PRESETS as $preset) {
            if ($preset['code'] === $code) {
                return $preset;
            }
        }

        return null;
    }

    private function baseUrl(string $provider, string $rawUrl): string
    {
        if ($provider === 'deepseek') {
            return 'https://api.deepseek.com';
        }
        $base = rtrim(trim($rawUrl), '/');
        $parts = parse_url($base);
        abort_if(($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']), 422, '请填写 HTTPS API 基础地址。');

        return $base;
    }

    private function probeConfig(array $input, bool $needsModel): array
    {
        $data = Validator::make($input, [
            'service_code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'provider' => 'required|in:'.implode(',', self::ALLOWED_PROVIDERS),
            'base_url' => 'required|url:https|max:400',
            'api_key' => 'nullable|string|max:4096',
            'model' => ($needsModel ? 'required' : 'nullable').'|string|max:120',
            'version' => 'sometimes|integer|min:0',
        ])->validate();

        $serviceCode = trim((string) ($data['service_code'] ?? ''));
        $row = $serviceCode !== '' ? $this->recordByCode($serviceCode)->first() : null;
        if ($row && isset($data['version'])) {
            abort_unless((int) $data['version'] === (int) $row->business_version, 409, '配置已更新，请刷新。');
        }
        $data['service_code'] = $serviceCode;
        $data['base_url'] = $this->baseUrl((string) $data['provider'], (string) $data['base_url']);

        if (blank($data['api_key'] ?? null)) {
            $secret = null;
            if ($row && ! empty($row->encrypted_credentials)) {
                $saved = json_decode((string) $row->config_json, true) ?: [];
                if ((string) ($saved['provider'] ?? '') === $data['provider'] && rtrim((string) ($saved['base_url'] ?? ''), '/') === $data['base_url']) {
                    $secret = (string) $row->encrypted_credentials;
                }
            }
            abort_unless($secret, 422, '请填写当前厂商的 API Key。');
            $data['api_key'] = $this->extractRuntimeApiKey($secret);
        }

        return $data;
    }

    private function extractRuntimeApiKey(?string $encrypted): string
    {
        $unpacked = $this->vault->unpack($encrypted, 'api_key');
        $apiKey = trim((string) ($unpacked['runtime']['api_key'] ?? ''));
        abort_if($apiKey === '', 422, '已保存的 API Key 无法解密，请重新保存配置。');

        return $apiKey;
    }

    private function client(string $key): PendingRequest
    {
        return Http::acceptJson()->withToken($key)->withOptions(['allow_redirects' => false])->connectTimeout(8);
    }

    private function completion(array $config, string $key, string $instructions, array $facts): array
    {
        $payload = [
            'model' => $config['model'],
            'stream' => false,
            'max_tokens' => 3500,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            'response_format' => ['type' => 'json_object'],
        ];
        if (($config['provider'] ?? '') === 'deepseek') {
            $payload['thinking'] = ['type' => 'disabled'];
        }
        try {
            $response = $this->client($key)->timeout(65)
                ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $payload);
        } catch (ConnectionException) {
            abort(502, '模型服务连接超时或不可达，可稍后重试。');
        }
        if (! $response->successful()) {
            $reason = match ($response->status()) {
                401 => '请检查 API Key 是否有效',
                403 => '请检查该 API Key 是否有权调用所选模型',
                402 => '请检查服务账户余额',
                429 => '请检查服务额度或稍后重试',
                default => '请检查模型 ID 以及服务对对话和 JSON 输出的支持',
            };
            abort(502, '模型服务返回 HTTP '.$response->status().'，'.$reason.'。');
        }
        $content = $response->json('choices.0.message.content');
        $result = is_string($content) ? json_decode(trim($content), true) : null;
        abort_unless(is_array($result), 502, '模型未返回有效 JSON，请重试或检查模型兼容性。');

        return $result;
    }

    /**
     * @param  mixed  $items
     * @return array<int, string>
     */
    private function normalizeModelIds(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            $id = trim((string) $item);
            if ($id !== '' && mb_strlen($id) <= 120 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $items
     * @return array<int, array{id:string, name:string}>
     */
    private function normalizeCatalogModels(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '' || mb_strlen($id) > 120) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? '')) ?: $id;
            $out[$id] = ['id' => $id, 'name' => $name];
        }

        return array_values($out);
    }
}
