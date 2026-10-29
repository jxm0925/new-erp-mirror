<?php

use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(\App\Services\Erp\ApprovalNotificationService::class)->sendDueReminders())
    ->name('approval-sla-reminders')->everyFiveMinutes()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Erp\DocumentNumberService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(DocumentNumberService::class)->expire())
    ->name('expire-unused-document-number-reservations')
    ->everyTenMinutes();

// 车间计时超过 4 小时没停的自动停止（忘了停、人走了）。
Schedule::call(fn () => app(\App\Services\Erp\Manufacturing\TaskService::class)->closeOverdueSessions())
    ->name('mfg-labor-auto-stop')->everyFiveMinutes()->withoutOverlapping();
