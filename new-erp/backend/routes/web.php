<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    $index = public_path('index.html');

    if (file_exists($index)) {
        return response()->file($index);
    }

    return view('welcome');
});

Route::fallback(function () {
    $index = public_path('index.html');

    if (file_exists($index)) {
        return response()->file($index);
    }

    abort(404);
});

// 终极缓存清理探针
Route::get('/clear', function () {
    // 1. 清除 Laravel 框架层面的所有缓存
    Artisan::call('optimize:clear');
    
    // 2. 强制清除 PHP 底层的 OPcache 字节码缓存（极其关键）
    $opcache = false;
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $opcache = true;
    }

    return response()->json([
        'code' => 200,
        'message' => '底层缓存核爆清理完成！',
        'opcache_cleared' => $opcache
    ]);
});
