<?php

declare(strict_types=1);

namespace App\Services\Erp;

use AlphaSnow\Flysystem\Aliyun\AliyunFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OSS\Core\OssException;
use Throwable;

/**
 * 系统第三方服务配置服务（当前接入阿里云 OSS，结构可扩展至短信等其他第三方服务）。
 * 密钥使用 Laravel Crypt (AES-256) 密文存储，接口绝不回显明文 Secret；
 * 启用后在运行时动态覆盖 filesystems.disks.oss，使业务附件与图纸无缝生效。
 */
class ThirdPartyServiceConfigService
{
    public const CODE_ALIYUN_OSS = 'aliyun_oss';
    private const RUNTIME_CACHE_KEY = 'erp:third_party_service:aliyun_oss_runtime_v1';

    private readonly array $environmentOssConfig;

    public function __construct()
    {
        // 保留启动时配置，停用数据库接管后恢复，避免继续使用本次请求中已覆盖的密钥与端点。
        $this->environmentOssConfig = config('filesystems.disks.oss', []);
    }

    /** @var array<int, array<string, string>> */
    public const ALIYUN_OSS_REGIONS = [
        ['region' => 'cn-qingdao', 'label' => '华北 1（青岛）', 'endpoint' => 'oss-cn-qingdao.aliyuncs.com', 'internal_endpoint' => 'oss-cn-qingdao-internal.aliyuncs.com'],
        ['region' => 'cn-hangzhou', 'label' => '华东 1（杭州）', 'endpoint' => 'oss-cn-hangzhou.aliyuncs.com', 'internal_endpoint' => 'oss-cn-hangzhou-internal.aliyuncs.com'],
        ['region' => 'cn-shanghai', 'label' => '华东 2（上海）', 'endpoint' => 'oss-cn-shanghai.aliyuncs.com', 'internal_endpoint' => 'oss-cn-shanghai-internal.aliyuncs.com'],
        ['region' => 'cn-beijing', 'label' => '华北 2（北京）', 'endpoint' => 'oss-cn-beijing.aliyuncs.com', 'internal_endpoint' => 'oss-cn-beijing-internal.aliyuncs.com'],
        ['region' => 'cn-zhangjiakou', 'label' => '华北 3（张家口）', 'endpoint' => 'oss-cn-zhangjiakou.aliyuncs.com', 'internal_endpoint' => 'oss-cn-zhangjiakou-internal.aliyuncs.com'],
        ['region' => 'cn-huhehaote', 'label' => '华北 5（呼和浩特）', 'endpoint' => 'oss-cn-huhehaote.aliyuncs.com', 'internal_endpoint' => 'oss-cn-huhehaote-internal.aliyuncs.com'],
        ['region' => 'cn-wulanchabu', 'label' => '华北 6（乌兰察布）', 'endpoint' => 'oss-cn-wulanchabu.aliyuncs.com', 'internal_endpoint' => 'oss-cn-wulanchabu-internal.aliyuncs.com'],
        ['region' => 'cn-shenzhen', 'label' => '华南 1（深圳）', 'endpoint' => 'oss-cn-shenzhen.aliyuncs.com', 'internal_endpoint' => 'oss-cn-shenzhen-internal.aliyuncs.com'],
        ['region' => 'cn-heyuan', 'label' => '华南 2（河源）', 'endpoint' => 'oss-cn-heyuan.aliyuncs.com', 'internal_endpoint' => 'oss-cn-heyuan-internal.aliyuncs.com'],
        ['region' => 'cn-guangzhou', 'label' => '华南 3（广州）', 'endpoint' => 'oss-cn-guangzhou.aliyuncs.com', 'internal_endpoint' => 'oss-cn-guangzhou-internal.aliyuncs.com'],
        ['region' => 'cn-chengdu', 'label' => '西南 1（成都）', 'endpoint' => 'oss-cn-chengdu.aliyuncs.com', 'internal_endpoint' => 'oss-cn-chengdu-internal.aliyuncs.com'],
        ['region' => 'cn-hongkong', 'label' => '中国香港', 'endpoint' => 'oss-cn-hongkong.aliyuncs.com', 'internal_endpoint' => 'oss-cn-hongkong-internal.aliyuncs.com'],
        ['region' => 'ap-southeast-1', 'label' => '新加坡', 'endpoint' => 'oss-ap-southeast-1.aliyuncs.com', 'internal_endpoint' => 'oss-ap-southeast-1-internal.aliyuncs.com'],
        ['region' => 'us-west-1', 'label' => '美国（硅谷）', 'endpoint' => 'oss-us-west-1.aliyuncs.com', 'internal_endpoint' => 'oss-us-west-1-internal.aliyuncs.com'],
    ];

    public function getAliyunOssConfig(): array
    {
        $row = DB::table('erp_third_party_services')
            ->where('service_code', self::CODE_ALIYUN_OSS)
            ->first();

        $envConfig = $this->environmentOssConfig;
        $envFallbackConfigured = ! empty($envConfig['access_key_id'])
            && ! empty($envConfig['access_key_secret'])
            && ! empty($envConfig['bucket'])
            && ! empty($envConfig['endpoint']);

        $savedConfig = $row && $row->config_json
            ? (json_decode((string) $row->config_json, true) ?: [])
            : [];

        $defaults = [
            'region' => (string) ($envConfig['region'] ?? 'cn-qingdao'),
            'endpoint' => (string) ($envConfig['endpoint'] ?? 'oss-cn-qingdao.aliyuncs.com'),
            'internal_endpoint' => (string) ($envConfig['internal'] ?? ''),
            'bucket' => (string) ($envConfig['bucket'] ?? ''),
            'prefix' => (string) ($envConfig['prefix'] ?? ''),
            'is_cname' => (bool) ($envConfig['is_cname'] ?? false),
            'use_ssl' => array_key_exists('use_ssl', $envConfig) ? (bool) $envConfig['use_ssl'] : true,
            'domain' => (string) ($envConfig['domain'] ?? ''),
            'signature_version' => in_array($envConfig['signatureVersion'] ?? 'v4', ['v1', 'v4'], true)
                ? (string) ($envConfig['signatureVersion'] ?? 'v4')
                : 'v4',
            'timeout' => isset($envConfig['timeout']) ? (int) $envConfig['timeout'] : 60,
            'connect_timeout' => isset($envConfig['connect_timeout']) ? (int) $envConfig['connect_timeout'] : 10,
            'max_retries' => isset($envConfig['max_retries']) ? (int) $envConfig['max_retries'] : 3,
        ];

        $merged = array_merge($defaults, $savedConfig);

        return [
            'configured' => $row !== null,
            'service_code' => self::CODE_ALIYUN_OSS,
            'category' => 'storage',
            'provider_name' => '阿里云对象存储 (OSS)',
            'enabled' => $row ? (bool) $row->enabled : false,
            'effective_source' => ($row && (bool) $row->enabled)
                ? 'database'
                : ($envFallbackConfigured ? 'env_fallback' : 'unconfigured'),
            'env_fallback_configured' => $envFallbackConfigured,
            'config' => [
                'region' => (string) ($merged['region'] ?? ''),
                'endpoint' => (string) ($merged['endpoint'] ?? ''),
                'internal_endpoint' => (string) ($merged['internal_endpoint'] ?? ''),
                'bucket' => (string) ($merged['bucket'] ?? ''),
                'prefix' => (string) ($merged['prefix'] ?? ''),
                'is_cname' => (bool) ($merged['is_cname'] ?? false),
                'use_ssl' => (bool) ($merged['use_ssl'] ?? true),
                'domain' => (string) ($merged['domain'] ?? ''),
                'signature_version' => (string) ($merged['signature_version'] ?? 'v4'),
                'timeout' => (int) ($merged['timeout'] ?? 60),
                'connect_timeout' => (int) ($merged['connect_timeout'] ?? 10),
                'max_retries' => (int) ($merged['max_retries'] ?? 3),
            ],
            'credentials' => [
                'has_credentials' => $row !== null && ! empty($row->encrypted_credentials),
                'access_key_id_masked' => $row ? (string) ($row->credential_mask ?? '') : '',
            ],
            'last_check_status' => $row ? $row->last_check_status : null,
            'last_check_message' => $row ? $row->last_check_message : null,
            'last_checked_at' => $row && $row->last_checked_at ? (string) $row->last_checked_at : null,
            'business_version' => $row ? (int) $row->business_version : 0,
            'updated_by_name' => $row ? $row->updated_by_name : null,
            'updated_at' => $row && $row->updated_at ? (string) $row->updated_at : null,
            'regions' => self::ALIYUN_OSS_REGIONS,
        ];
    }

    public function saveAliyunOss(array $payload, object $actor): array
    {
        $data = $this->validateAliyunOssPayload($payload, false);

        DB::transaction(function () use ($data, $actor): void {
            $existing = DB::table('erp_third_party_services')
                ->where('service_code', self::CODE_ALIYUN_OSS)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $expectedVersion = $data['expected_version'] ?? null;
                if ($expectedVersion !== null && (int) $existing->business_version !== (int) $expectedVersion) {
                    abort(409, '配置已被其他管理员更新，请刷新页面后重试。');
                }
            }

            $storedCreds = $existing ? $this->decryptCredentials($existing->encrypted_credentials) : null;
            $newKeyId = trim((string) ($data['access_key_id'] ?? ''));
            $newKeySecret = trim((string) ($data['access_key_secret'] ?? ''));

            if ($newKeyId !== '' || $newKeySecret !== '') {
                if ($newKeyId === '' || $newKeySecret === '') {
                    abort(422, '首次配置或更换凭证时，AccessKey ID 与 AccessKey Secret 必须同时填写。');
                }
                $creds = [
                    'access_key_id' => $newKeyId,
                    'access_key_secret' => $newKeySecret,
                ];
            } elseif ($storedCreds !== null) {
                $creds = $storedCreds;
            } else {
                abort(422, '首次配置阿里云 OSS 时必须填写 AccessKey ID 与 AccessKey Secret。');
            }

            $configJson = $this->extractPublicConfig($data);
            $encrypted = Crypt::encryptString(json_encode($creds, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $mask = self::maskAccessKeyId($creds['access_key_id']);
            $now = now();
            $actorId = (int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null;
            $actorName = (string) ($actor->nickname ?? $actor->username ?? '系统管理员');

            if ($existing) {
                DB::table('erp_third_party_services')
                    ->where('id', $existing->id)
                    ->update([
                        'enabled' => (bool) $data['enabled'],
                        'config_json' => json_encode($configJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'encrypted_credentials' => $encrypted,
                        'credential_mask' => $mask,
                        'business_version' => (int) $existing->business_version + 1,
                        'updated_by_legacy_id' => $actorId,
                        'updated_by_name' => $actorName,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('erp_third_party_services')->insert([
                    'service_code' => self::CODE_ALIYUN_OSS,
                    'category' => 'storage',
                    'provider_name' => '阿里云对象存储 (OSS)',
                    'enabled' => (bool) $data['enabled'],
                    'config_json' => json_encode($configJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'encrypted_credentials' => $encrypted,
                    'credential_mask' => $mask,
                    'business_version' => 1,
                    'updated_by_legacy_id' => $actorId,
                    'updated_by_name' => $actorName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $this->refreshRuntimeOssConfig();

        return $this->getAliyunOssConfig();
    }

    public function rotateAliyunOssSecret(array $payload, object $actor): array
    {
        $data = Validator::make($payload, [
            'access_key_id' => 'required|string|min:8|max:128',
            'access_key_secret' => 'required|string|min:8|max:256',
            'verify_before_save' => 'nullable|boolean',
            'expected_version' => 'nullable|integer|min:1',
        ], [
            'access_key_id.required' => '请填写新的 AccessKey ID。',
            'access_key_secret.required' => '请填写新的 AccessKey Secret。',
        ])->validate();

        $existing = DB::table('erp_third_party_services')
            ->where('service_code', self::CODE_ALIYUN_OSS)
            ->first();
        abort_unless($existing, 422, '请先保存基础连接配置后再更换访问密钥。');

        $savedConfig = json_decode((string) $existing->config_json, true) ?: [];
        $newKeyId = trim((string) $data['access_key_id']);
        $newKeySecret = trim((string) $data['access_key_secret']);

        $checkResult = null;
        if (! empty($data['verify_before_save'])) {
            $checkResult = $this->probeOssConnection($savedConfig, $newKeyId, $newKeySecret);
            if (! $checkResult['ok']) {
                abort(422, '新密钥连通性检测失败：'.$checkResult['message']);
            }
        }

        DB::transaction(function () use ($data, $newKeyId, $newKeySecret, $checkResult, $actor): void {
            $locked = DB::table('erp_third_party_services')
                ->where('service_code', self::CODE_ALIYUN_OSS)
                ->lockForUpdate()
                ->first();
            abort_unless($locked, 422, '配置记录不存在。');

            $expectedVersion = $data['expected_version'] ?? null;
            if ($expectedVersion !== null && (int) $locked->business_version !== (int) $expectedVersion) {
                abort(409, '配置已被其他管理员更新，请刷新页面后重试。');
            }

            $creds = [
                'access_key_id' => $newKeyId,
                'access_key_secret' => $newKeySecret,
            ];
            $now = now();
            $update = [
                'encrypted_credentials' => Crypt::encryptString(json_encode($creds, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'credential_mask' => self::maskAccessKeyId($newKeyId),
                'business_version' => (int) $locked->business_version + 1,
                'updated_by_legacy_id' => (int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null,
                'updated_by_name' => (string) ($actor->nickname ?? $actor->username ?? '系统管理员'),
                'updated_at' => $now,
            ];
            if ($checkResult !== null) {
                $update['last_check_status'] = $checkResult['ok'] ? 'ok' : 'failed';
                $update['last_check_message'] = mb_substr($checkResult['message'], 0, 500);
                $update['last_checked_at'] = $now;
            }

            DB::table('erp_third_party_services')->where('id', $locked->id)->update($update);
        });

        $this->refreshRuntimeOssConfig();

        return [
            'service' => $this->getAliyunOssConfig(),
            'check' => $checkResult,
        ];
    }

    public function checkAliyunOssConnection(array $payload, object $actor): array
    {
        $data = $this->validateAliyunOssPayload($payload, true);
        $existing = DB::table('erp_third_party_services')
            ->where('service_code', self::CODE_ALIYUN_OSS)
            ->first();

        $storedCreds = $existing ? $this->decryptCredentials($existing->encrypted_credentials) : null;
        $keyId = trim((string) ($data['access_key_id'] ?? '')) ?: (string) ($storedCreds['access_key_id'] ?? '');
        $keySecret = trim((string) ($data['access_key_secret'] ?? '')) ?: (string) ($storedCreds['access_key_secret'] ?? '');

        if ($keyId === '' || $keySecret === '') {
            abort(422, '缺少 AccessKey ID 或 AccessKey Secret，无法发起连通性检测。');
        }

        $publicConfig = $this->extractPublicConfig($data);
        $result = $this->probeOssConnection($publicConfig, $keyId, $keySecret);

        if ($existing) {
            DB::table('erp_third_party_services')
                ->where('id', $existing->id)
                ->update([
                    'last_check_status' => $result['ok'] ? 'ok' : 'failed',
                    'last_check_message' => mb_substr($result['message'], 0, 500),
                    'last_checked_at' => now(),
                ]);
        }

        return [
            'check' => $result,
            'service' => $this->getAliyunOssConfig(),
        ];
    }

    /**
     * 在应用启动或配置保存后，将已启用的数据库阿里云 OSS 配置动态注入 filesystems.disks.oss。
     * 未启用、尚未建表或解密异常时静默保留原有 .env 配置，绝不阻塞应用启动。
     */
    public function applyRuntimeOssConfig(): void
    {
        $runtime = null;
        try {
            $runtime = Cache::remember(self::RUNTIME_CACHE_KEY, 300, function (): ?array {
                if (! Schema::hasTable('erp_third_party_services')) {
                    return null;
                }
                $row = DB::table('erp_third_party_services')
                    ->where('service_code', self::CODE_ALIYUN_OSS)
                    ->where('enabled', 1)
                    ->first();
                if (! $row || empty($row->encrypted_credentials)) {
                    return null;
                }
                $creds = $this->decryptCredentials($row->encrypted_credentials);
                $cfg = json_decode((string) $row->config_json, true);
                if (! $creds || ! is_array($cfg) || empty($cfg['bucket']) || empty($cfg['endpoint'])) {
                    return null;
                }

                return $this->buildDiskConfig($cfg, $creds['access_key_id'], $creds['access_key_secret']);
            });
        } catch (Throwable) {
            // 数据库尚未初始化或迁移阶段静默回退至 .env 配置
        }

        config(['filesystems.disks.oss' => array_merge($this->environmentOssConfig, is_array($runtime) ? $runtime : [])]);
        Storage::forgetDisk('oss');
    }

    public function refreshRuntimeOssConfig(): void
    {
        try {
            Cache::forget(self::RUNTIME_CACHE_KEY);
        } catch (Throwable) {
        }
        $this->applyRuntimeOssConfig();
    }

    private function validateAliyunOssPayload(array $payload, bool $forCheck): array
    {
        $validator = Validator::make($payload, [
            'enabled' => ($forCheck ? 'nullable' : 'required').'|boolean',
            'region' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'endpoint' => 'required|string|max:200',
            'internal_endpoint' => 'nullable|string|max:200',
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/'],
            'prefix' => 'nullable|string|max:200',
            'is_cname' => 'nullable|boolean',
            'use_ssl' => 'nullable|boolean',
            'domain' => 'nullable|string|max:200',
            'signature_version' => 'nullable|in:v1,v4',
            'timeout' => 'nullable|integer|min:1|max:300',
            'connect_timeout' => 'nullable|integer|min:1|max:120',
            'max_retries' => 'nullable|integer|min:0|max:10',
            'access_key_id' => 'nullable|string|max:128',
            'access_key_secret' => 'nullable|string|max:256',
            'expected_version' => 'nullable|integer|min:1',
        ], [
            'region.required' => '请选择或填写地域 Region（例如 cn-qingdao）。',
            'region.regex' => '地域 Region 仅支持小写字母、数字和短横线。',
            'endpoint.required' => '请填写访问端点 Endpoint。',
            'bucket.required' => '请填写存储空间 Bucket 名称。',
            'bucket.regex' => 'Bucket 名称须符合阿里云官方规范：3~63 位小写字母、数字或短横线，且以字母或数字开头结尾。',
        ]);

        $validator->after(function ($v) use ($payload): void {
            if (! empty($payload['is_cname']) && trim((string) ($payload['domain'] ?? '')) === '') {
                $v->errors()->add('domain', '启用 CNAME 自定义域名时，必须填写自定义访问域名 Domain。');
            }
        });

        return $validator->validate();
    }

    private function extractPublicConfig(array $data): array
    {
        return [
            'region' => trim((string) $data['region']),
            'endpoint' => self::normalizeHost((string) $data['endpoint']),
            'internal_endpoint' => self::normalizeHost((string) ($data['internal_endpoint'] ?? '')),
            'bucket' => trim((string) $data['bucket']),
            'prefix' => trim((string) ($data['prefix'] ?? ''), '/'),
            'is_cname' => (bool) ($data['is_cname'] ?? false),
            'use_ssl' => array_key_exists('use_ssl', $data) ? (bool) $data['use_ssl'] : true,
            'domain' => self::normalizeHost((string) ($data['domain'] ?? '')),
            'signature_version' => (string) ($data['signature_version'] ?? 'v4'),
            'timeout' => (int) ($data['timeout'] ?? 60),
            'connect_timeout' => (int) ($data['connect_timeout'] ?? 10),
            'max_retries' => (int) ($data['max_retries'] ?? 3),
        ];
    }

    private function buildDiskConfig(array $cfg, string $keyId, string $keySecret): array
    {
        $isCname = (bool) ($cfg['is_cname'] ?? false);
        $domain = (string) ($cfg['domain'] ?? '');
        $endpoint = $isCname && $domain !== '' ? $domain : (string) $cfg['endpoint'];

        return [
            'driver' => 'oss',
            'access_key_id' => $keyId,
            'access_key_secret' => $keySecret,
            'bucket' => (string) $cfg['bucket'],
            'endpoint' => $endpoint,
            'internal' => ($cfg['internal_endpoint'] ?? '') !== '' ? (string) $cfg['internal_endpoint'] : null,
            'prefix' => (string) ($cfg['prefix'] ?? ''),
            'domain' => $domain !== '' ? $domain : null,
            'is_cname' => $isCname,
            'use_ssl' => (bool) ($cfg['use_ssl'] ?? true),
            'region' => (string) ($cfg['region'] ?? ''),
            'signatureVersion' => (string) ($cfg['signature_version'] ?? 'v4'),
            'timeout' => (int) ($cfg['timeout'] ?? 60),
            'connect_timeout' => (int) ($cfg['connect_timeout'] ?? 10),
            'max_retries' => (int) ($cfg['max_retries'] ?? 3),
            'throw' => true,
        ];
    }

    private function probeOssConnection(array $publicConfig, string $keyId, string $keySecret): array
    {
        $startedAt = microtime(true);
        $diskConfig = $this->buildDiskConfig($publicConfig, $keyId, $keySecret);
        $diskConfig['timeout'] = min(15, max(3, (int) ($diskConfig['timeout'] ?? 10)));
        $diskConfig['connect_timeout'] = min(10, max(2, (int) ($diskConfig['connect_timeout'] ?? 5)));
        $diskConfig['max_retries'] = 0;

        try {
            $client = app(AliyunFactory::class)->createClient($diskConfig);
            $exists = $client->doesBucketExist((string) $diskConfig['bucket']);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (! $exists) {
                return [
                    'ok' => false,
                    'latency_ms' => $latencyMs,
                    'message' => sprintf('已连通端点 %s，但存储空间 Bucket「%s」不存在或无访问权限。', $diskConfig['endpoint'], $diskConfig['bucket']),
                ];
            }

            return [
                'ok' => true,
                'latency_ms' => $latencyMs,
                'message' => sprintf('连接成功：Bucket「%s」可正常访问（耗时 %d ms）。', $diskConfig['bucket'], $latencyMs),
            ];
        } catch (OssException $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $code = trim((string) $e->getErrorCode());
            $detail = trim((string) ($e->getErrorMessage() ?: $e->getMessage()));
            $msg = $code !== '' ? "{$code}: {$detail}" : $detail;

            return [
                'ok' => false,
                'latency_ms' => $latencyMs,
                'message' => mb_substr($msg ?: 'OSS 认证或网络请求失败。', 0, 300),
            ];
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'latency_ms' => $latencyMs,
                'message' => mb_substr($e->getMessage() ?: '连接检测异常。', 0, 300),
            ];
        }
    }

    private function decryptCredentials(?string $encrypted): ?array
    {
        if (! $encrypted) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
            if (is_array($decoded) && ! empty($decoded['access_key_id']) && ! empty($decoded['access_key_secret'])) {
                return [
                    'access_key_id' => (string) $decoded['access_key_id'],
                    'access_key_secret' => (string) $decoded['access_key_secret'],
                ];
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function normalizeHost(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $trimmed = preg_replace('#^https?://#i', '', $trimmed) ?? $trimmed;

        return rtrim($trimmed, '/');
    }

    private static function maskAccessKeyId(string $keyId): string
    {
        $len = strlen($keyId);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        if ($len <= 10) {
            return substr($keyId, 0, 3).'****'.substr($keyId, -2);
        }

        return substr($keyId, 0, 6).'••••••'.substr($keyId, -4);
    }
}
