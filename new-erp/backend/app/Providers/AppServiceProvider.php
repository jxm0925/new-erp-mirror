<?php

namespace App\Providers;

use AlphaSnow\Flysystem\Aliyun\AliyunFactory;
use AlphaSnow\LaravelFilesystem\Aliyun\FilesystemMacroManager;
use App\Services\Erp\ThirdPartyServiceConfigService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; // 必须引入此门面
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ThirdPartyServiceConfigService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 强制将默认字符串长度限制为 191，以兼容老版本 MySQL 的 767 bytes 索引限制
        Schema::defaultStringLength(191);

        // 插件未向 Flysystem 传默认配置；在这里补齐，确保对象上传使用 public-read。
        $this->app->make('filesystem')->extend('oss', function ($app, array $config) {
            $config['url_prefixed'] = version_compare($app->version(), '9.33.0', '>=');
            $adapter = $app->make(AliyunFactory::class)->createAdapter($config);
            $driver = new Filesystem($adapter, ['visibility' => 'public']);
            $filesystem = new FilesystemAdapter($driver, $adapter, $config);
            (new FilesystemMacroManager($app, $filesystem))->defaultRegister()->register($config['macros'] ?? []);

            return $filesystem;
        });

        // 若后台已启用数据库级阿里云 OSS 配置，则在运行时覆盖 oss 磁盘参数；未启用或建表前自动回退 .env。
        $this->app->make(ThirdPartyServiceConfigService::class)->applyRuntimeOssConfig();
    }
}
