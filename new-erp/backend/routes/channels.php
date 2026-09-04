<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('inventory-alerts', function ($user) {
    if (!$user || !isset($user->legacy_id)) return false;
    $auth = app(\App\Services\Erp\AuthContextService::class);
    return $auth->isSuperAdmin($user) || in_array('inventory.alert.view', $auth->permissionCodes($user), true);
});

Broadcast::channel('approval-user.{userId}', function ($user, int $userId) {
    if (!$user || !isset($user->legacy_id)) return false;
    return (int) $user->legacy_id === $userId;
});
