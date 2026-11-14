<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('drivecore:heartbeat')->everyMinute()->withoutOverlapping(5);
Schedule::command('drivecore:check')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
