<?php

return [
    'android' => [
        'version_name' => env('MOBILE_ANDROID_VERSION_NAME', '1.0.1'),
        'build_number' => (int) env('MOBILE_ANDROID_BUILD_NUMBER', 2),
        'download_url' => env('MOBILE_ANDROID_DOWNLOAD_URL', ''),
        'force_update' => filter_var(env('MOBILE_ANDROID_FORCE_UPDATE', false), FILTER_VALIDATE_BOOL),
        'changelog' => env('MOBILE_ANDROID_CHANGELOG', ''),
    ],
    'ios' => [
        'version_name' => env('MOBILE_IOS_VERSION_NAME', '1.0.1'),
        'build_number' => (int) env('MOBILE_IOS_BUILD_NUMBER', 2),
        'download_url' => env('MOBILE_IOS_DOWNLOAD_URL', ''),
        'force_update' => filter_var(env('MOBILE_IOS_FORCE_UPDATE', false), FILTER_VALIDATE_BOOL),
        'changelog' => env('MOBILE_IOS_CHANGELOG', ''),
    ],
];
