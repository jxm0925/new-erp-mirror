<?php

namespace Tests\Unit\Erp;

use App\Http\Controllers\Api\V1\Erp\MasterDataController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SkuImageOssUploadTest extends TestCase
{
    public function test_sku_image_upload_uses_the_oss_disk_and_never_the_public_disk(): void
    {
        config([
            'filesystems.disks.oss.access_key_id' => 'test-key',
            'filesystems.disks.oss.access_key_secret' => 'test-secret',
            'filesystems.disks.oss.bucket' => 'test-bucket',
            'filesystems.disks.oss.endpoint' => 'oss.example.test',
        ]);
        Storage::fake('oss');
        Storage::fake('public');

        $file = UploadedFile::fake()->create('sku-image.png', 64, 'image/png');
        $request = Request::create('/api/v1/erp/master/skus/image-upload', 'POST', [], [], ['image' => $file]);

        $response = app(MasterDataController::class)->uploadSkuImage($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('oss', $payload['data']['storage']);
        Storage::disk('oss')->assertExists($payload['data']['path']);
        Storage::disk('public')->assertMissing($payload['data']['path']);
    }
}
