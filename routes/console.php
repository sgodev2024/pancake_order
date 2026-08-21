<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('customer-care:reclaim-stale', [
    '--execute',
    '--limit' => config('customer_care.auto_reclaim.limit'),
])
    ->hourly()
    ->timezone(config('app.timezone'))
    ->when(fn (): bool => config('customer_care.auto_reclaim.enabled') === true)
    ->withoutOverlapping(180)
    ->onFailure(fn () => Log::error('Scheduled customer-care auto reclaim failed.'));
