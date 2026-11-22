<?php

declare(strict_types=1);

namespace App\Services\Erp;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * 第三方服务密钥加密保险箱：
 * - 运行时副本：外层经 Laravel Crypt (APP_KEY / AES-256) 加密存储，保障后台业务自动调用（OSS 上传、AI 分析等）；
 * - 查看复制密文：使用用户保存密钥时输入的加密密码，经 PBKDF2-SHA256 派生 256 位密钥 + AES-256-GCM 认证加密；
 *   不存储用户密码，仅在解密复制时凭相同加密密码实时校验并还原原始密钥。
 */
final class ThirdPartySecretVaultService
{
    public const ENVELOPE_VERSION = 2;
    public const CIPHER = 'aes-256-gcm';
    public const KDF_ALGO = 'sha256';
    public const KDF_ITERATIONS = 100000;
    public const KEY_BYTES = 32;
    public const SALT_BYTES = 16;
    public const IV_BYTES = 12;
    public const MIN_PASSWORD_LENGTH = 4;
    public const MAX_PASSWORD_LENGTH = 128;

    /**
     * 使用用户加密密码（PBKDF2-SHA256 派生密钥 + AES-256-GCM）加密密钥组，
     * 同时保留经系统 Crypt 加密的运行时副本与脱敏掩码。
     *
     * @param  array<string, string>  $secrets
     */
    public function encrypt(string $serviceCode, array $secrets, string $password): string
    {
        $normalized = [];
        foreach ($secrets as $field => $value) {
            $key = trim((string) $field);
            $val = trim((string) $value);
            if ($key === '' || $val === '') {
                abort(422, '密钥内容不能为空。');
            }
            $normalized[$key] = $val;
        }

        if ($normalized === []) {
            abort(422, '缺少待加密的密钥内容。');
        }

        $password = trim($password);
        $passLen = mb_strlen($password);
        if ($passLen < self::MIN_PASSWORD_LENGTH || $passLen > self::MAX_PASSWORD_LENGTH) {
            abort(422, sprintf('密钥加密密码长度须为 %d~%d 位。', self::MIN_PASSWORD_LENGTH, self::MAX_PASSWORD_LENGTH));
        }

        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        $derivedKey = hash_pbkdf2(self::KDF_ALGO, $password, $salt, self::KDF_ITERATIONS, self::KEY_BYTES, true);
        $plaintext = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $serviceCode
        );

        if ($ciphertext === false || $tag === '') {
            abort(500, '密钥加密失败，请检查服务器 OpenSSL 环境。');
        }

        $envelope = [
            '_v' => self::ENVELOPE_VERSION,
            'service_code' => $serviceCode,
            'runtime' => $normalized,
            'masks' => self::maskMap($normalized),
            'vault' => [
                'alg' => 'PBKDF2-SHA256+AES-256-GCM',
                'kdf' => self::KDF_ALGO,
                'iterations' => self::KDF_ITERATIONS,
                'salt' => base64_encode($salt),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'ciphertext' => base64_encode($ciphertext),
            ],
        ];

        return Crypt::encryptString(json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * 解包数据库存储的加密凭据，兼容旧版（未设置用户密码）与新版（含用户密码保险箱）。
     *
     * @return array{version:int, runtime:array<string,string>, masks:array<string,string>, vault:?array<string,mixed>, has_password:bool}|null
     */
    public function unpack(?string $encrypted, string $legacySingleKey = ''): ?array
    {
        if (! $encrypted) {
            return null;
        }

        try {
            $raw = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && (int) ($decoded['_v'] ?? 0) === self::ENVELOPE_VERSION && is_array($decoded['runtime'] ?? null)) {
            $runtime = [];
            foreach ($decoded['runtime'] as $k => $v) {
                if (is_string($k) && $v !== null && (string) $v !== '') {
                    $runtime[$k] = (string) $v;
                }
            }
            if ($runtime === []) {
                return null;
            }

            $masks = [];
            if (is_array($decoded['masks'] ?? null)) {
                foreach ($decoded['masks'] as $k => $v) {
                    if (is_string($k)) {
                        $masks[$k] = (string) $v;
                    }
                }
            }
            foreach ($runtime as $k => $v) {
                if (! isset($masks[$k]) || $masks[$k] === '') {
                    $masks[$k] = self::maskSecret($v);
                }
            }

            $vault = is_array($decoded['vault'] ?? null) ? $decoded['vault'] : null;
            $hasPassword = $vault !== null
                && ! empty($vault['salt'])
                && ! empty($vault['iv'])
                && ! empty($vault['tag'])
                && ! empty($vault['ciphertext']);

            return [
                'version' => self::ENVELOPE_VERSION,
                'runtime' => $runtime,
                'masks' => $masks,
                'vault' => $hasPassword ? $vault : null,
                'has_password' => $hasPassword,
            ];
        }

        // 兼容旧版 JSON 结构（如旧版阿里云 OSS 凭据）
        if (is_array($decoded)) {
            $runtime = [];
            foreach ($decoded as $k => $v) {
                if (is_string($k) && (is_string($v) || is_numeric($v)) && trim((string) $v) !== '') {
                    $runtime[$k] = (string) $v;
                }
            }
            if ($runtime === []) {
                return null;
            }

            return [
                'version' => 1,
                'runtime' => $runtime,
                'masks' => self::maskMap($runtime),
                'vault' => null,
                'has_password' => false,
            ];
        }

        // 兼容旧版单字符串结构（如旧版 AI API Key）
        $plain = trim($raw);
        if ($plain !== '' && $legacySingleKey !== '') {
            return [
                'version' => 1,
                'runtime' => [$legacySingleKey => $plain],
                'masks' => [$legacySingleKey => self::maskSecret($plain)],
                'vault' => null,
                'has_password' => false,
            ];
        }

        return null;
    }

    /**
     * 使用用户输入的加密密码解密保险箱并返回原始明文密钥映射。
     *
     * @return array<string, string>
     */
    public function decryptWithPassword(string $serviceCode, ?string $encrypted, string $password, string $legacySingleKey = ''): array
    {
        $unpacked = $this->unpack($encrypted, $legacySingleKey);
        if ($unpacked === null) {
            abort(422, '当前服务尚未保存密钥，无法解密。');
        }

        if (! $unpacked['has_password'] || ! is_array($unpacked['vault'])) {
            abort(422, '当前密钥为旧版加密保存，尚未设置解密密码；请由管理员重新保存或更换密钥并设置加密密码。');
        }

        $password = trim($password);
        if ($password === '') {
            abort(422, '请输入保存密钥时设置的加密密码。');
        }

        $vault = $unpacked['vault'];
        $salt = base64_decode((string) ($vault['salt'] ?? ''), true);
        $iv = base64_decode((string) ($vault['iv'] ?? ''), true);
        $tag = base64_decode((string) ($vault['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($vault['ciphertext'] ?? ''), true);
        $kdf = (string) ($vault['kdf'] ?? self::KDF_ALGO);
        $iterations = (int) ($vault['iterations'] ?? self::KDF_ITERATIONS);

        if ($salt === false || $iv === false || $tag === false || $ciphertext === false || $iterations < 1000) {
            abort(500, '密钥密文结构损坏，请联系管理员重新保存密钥。');
        }

        $derivedKey = hash_pbkdf2($kdf, $password, $salt, $iterations, self::KEY_BYTES, true);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $serviceCode
        );

        if ($plaintext === false) {
            abort(422, '加密密码不正确，解密失败，请核对后重试。');
        }

        $decoded = json_decode($plaintext, true);
        if (! is_array($decoded)) {
            abort(500, '解密结果异常，请联系管理员重新保存密钥。');
        }

        $result = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && $v !== null) {
                $result[$k] = (string) $v;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $secrets
     * @return array<string, string>
     */
    public static function maskMap(array $secrets): array
    {
        $masks = [];
        foreach ($secrets as $k => $v) {
            $masks[(string) $k] = self::maskSecret((string) $v);
        }

        return $masks;
    }

    public static function maskSecret(string $secret): string
    {
        $val = trim($secret);
        $len = strlen($val);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        if ($len <= 8) {
            return substr($val, 0, 2).'••••'.substr($val, -2);
        }
        if ($len <= 12) {
            return substr($val, 0, 3).'••••••'.substr($val, -3);
        }

        return substr($val, 0, 6).'••••••'.substr($val, -4);
    }
}
