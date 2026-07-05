<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\CommandDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans out a single command to a device off the request cycle. Bulk group/rollout pushes
 * dispatch one of these per device so a 50+ device push does not block the HTTP request or
 * overwhelm the broadcaster synchronously.
 */
class DispatchDeviceCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $deviceId,
        public string $commandType,
        public array $payload = [],
        public ?int $rolloutId = null,
    ) {
    }

    public function handle(): void
    {
        $device = Device::withoutGlobalScopes()->find($this->deviceId);

        if (!$device) {
            return;
        }

        CommandDispatcher::issue($device, $this->commandType, $this->payload, $this->rolloutId);
    }
}
