<?php

namespace App\Jobs;

use App\Models\PolicyRollout;
use App\Services\RolloutManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a rollout wave off the request cycle (start / resume / scheduled start).
 */
class ProcessRolloutWave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $rolloutId)
    {
    }

    public function handle(): void
    {
        $rollout = PolicyRollout::withoutGlobalScopes()->find($this->rolloutId);
        if ($rollout) {
            RolloutManager::processWave($rollout);
        }
    }
}
