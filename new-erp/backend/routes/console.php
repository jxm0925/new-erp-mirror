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
