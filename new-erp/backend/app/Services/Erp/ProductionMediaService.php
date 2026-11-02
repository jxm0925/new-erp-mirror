<?php

namespace App\Services\Erp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 生产和发货影像共用文件校验；上传不授予任何单据操作权限。 */
final class ProductionMediaService
{
    public static function rules(string $field = 'media'): array
    {
        return [$field => 'nullable|array|max:9', $field.'.*' => 'array:type,url,path,poster_url,duration,size',
            $field.'.*.type' => 'required|in:image,video', $field.'.*.url' => 'required|url:http,https|max:1000',
            $field.'.*.path' => 'required|string|max:500', $field.'.*.poster_url' => 'nullable|url:http,https|max:1000',
            $field.'.*.duration' => 'nullable|numeric|min:0|max:86400', $field.'.*.size' => 'nullable|integer|min:1|max:104857600'];
    }

    public function upload(UploadedFile $file, object $actor): array
    {
        $mime = strtolower((string) $file->getMimeType());
        $image = str_starts_with($mime, 'image/');
        Validator::make(['file' => $file], ['file' => $image ? 'required|image|max:10240'
            : 'required|file|mimetypes:video/mp4,video/quicktime|max:102400'])->validate();
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
        if (! isset($extensions[$mime])) $this->fail('不支持此文件类型，请选择图片或 MP4、MOV 视频。');
        $id = (string) Str::uuid();
        $path = $file->storeAs('erp/production/media/'.date('Ymd'), $id.'.'.$extensions[$mime], 'public');
        if (! $path) $this->fail('影像保存失败，请重试。');
        $media = ['type' => $image ? 'image' : 'video', 'url' => $this->url($path), 'path' => $path,
            'poster_url' => '', 'duration' => null, 'size' => (int) $file->getSize()];
        // 私有上传凭据只证明文件归属；单据保存仍由各业务入口检查权限、范围和当前状态。
        $saved = Storage::disk('local')->put('erp/production/media-receipts/'.$id.'.json', json_encode([
            'uploader_legacy_id' => (int) ($actor->legacy_id ?? $actor->id), 'mime' => $mime, 'media' => $media,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! $saved) {
            Storage::disk('public')->delete($path);
            $this->fail('影像上传凭据保存失败，请重试。');
        }
        return $media;
    }

    public function normalize(array $media, object $actor, string $stage): array
    {
        Validator::make(['media' => $media], self::rules())->validate();
        $result = [];
        foreach ($media as $row) {
            $stored = $this->owned($row['path'], $actor);
            if ($stored['type'] !== $row['type']) $this->fail('影像类型与已上传文件不一致，请重新上传。');
            $stored['url'] = $this->url($stored['path']);
            $stored['stage'] = $stage;
            if ($stored['type'] === 'video') {
                $stored['duration'] = isset($row['duration']) ? (float) $row['duration'] : null;
                if (! empty($row['poster_url'])) {
                    $posterPath = ltrim((string) parse_url($row['poster_url'], PHP_URL_PATH), '/');
                    if (! str_starts_with($posterPath, 'storage/')) $this->fail('视频封面不是已上传的图片。');
                    $poster = $this->owned(substr($posterPath, strlen('storage/')), $actor);
                    if ($poster['type'] !== 'image') $this->fail('视频封面必须是图片。');
                    $stored['poster_url'] = $this->url($poster['path']);
                }
            }
            $result[$stored['type'].'|'.$stored['path']] = $stored;
        }
        return array_values($result);
    }

    /** 只将旧 photos 补为图片；已有 media 的不同阶段记录保持原样。 */
    public static function forRead(array $media = [], array $photos = []): array
    {
        $result = array_values(array_filter($media, fn ($row) => is_array($row) && in_array($row['type'] ?? '', ['image', 'video'], true)));
        $key = function (array $row): string {
            $path = ltrim((string) (($row['path'] ?? '') ?: parse_url($row['url'] ?? '', PHP_URL_PATH)), '/');
            return ($row['type'] ?? 'image').'|'.preg_replace('#^storage/#', '', $path);
        };
        $known = array_fill_keys(array_map($key, $result), true);
        foreach ($photos as $url) {
            if (! is_string($url) || $url === '') continue;
            $row = ['type' => 'image', 'url' => $url, 'path' => '', 'poster_url' => '', 'duration' => null, 'size' => null];
            if (! isset($known[$key($row)])) $result[] = $row;
            $known[$key($row)] = true;
        }
        return $result;
    }

    private function owned(string $path, object $actor): array
    {
        if (! preg_match('#^erp/production/media/\d{8}/([0-9a-f-]{36})\.(jpg|png|gif|bmp|webp|mp4|mov)$#i', $path, $match))
            $this->fail('请选择通过影像入口上传的文件。');
        $receipt = Storage::disk('local')->get('erp/production/media-receipts/'.$match[1].'.json');
        $receipt = $receipt ? json_decode($receipt, true) : null;
        if (! $receipt || (int) $receipt['uploader_legacy_id'] !== (int) ($actor->legacy_id ?? $actor->id)
            || ($receipt['media']['path'] ?? null) !== $path || ! Storage::disk('public')->exists($path))
            $this->fail('影像不属于当前上传人或已失效，请重新上传。');
        return $receipt['media'];
    }

    private function url(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        return preg_match('#^https?://#i', $url) ? preg_replace('#^https?://[^/]+#i', request()->getSchemeAndHttpHost(), $url)
            : rtrim(request()->getSchemeAndHttpHost(), '/').'/'.ltrim($url, '/');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['media' => $message]);
    }
}
