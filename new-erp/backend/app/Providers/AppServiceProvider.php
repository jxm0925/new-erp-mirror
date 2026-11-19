<?php

namespace App\Providers;

use App\Services\Erp\ThirdPartyServiceConfigService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; // 必须引入此门面

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 强制将默认字符串长度限制为 191，以兼容老版本 MySQL 的 767 bytes 索引限制
        Schema::defaultStringLength(191);

        // 若后台已启用数据库级阿里云 OSS 配置，则在运行时覆盖 oss 磁盘参数；未启用或建表前自动回退 .env。
        $this->app->make(ThirdPartyServiceConfigService::class)->applyRuntimeOssConfig();
    }
}