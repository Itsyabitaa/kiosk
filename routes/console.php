<?php

use App\Jobs\ProcessRolloutWave;
use App\Models\PolicyRollout;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kick off any staged rollouts whose scheduled_at has arrived.
Schedule::call(function () {
    PolicyRollout::withoutGlobalScopes()
        ->where('status', PolicyRollout::STATUS_SCHEDULED)
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->get()
        ->each(fn (PolicyRollout $rollout) => ProcessRolloutWave::dispatch($rollout->id));
})->everyMinute()->name('start-due-rollouts')->withoutOverlapping();

// Scan the fleet for health alerts (offline / telemetry stopped).
Schedule::command('fleet:scan-health')->everyFiveMinutes()->withoutOverlapping();
