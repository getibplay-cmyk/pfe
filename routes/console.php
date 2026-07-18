<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('operations:scheduler-heartbeat')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('reservations:expire-pending')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('insurance:expire-policies')
    ->dailyAt('00:15')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30)
    ->onOneServer();
