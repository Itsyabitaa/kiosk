<?php

namespace App\Events;

use App\Models\MdmCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to a single device's private channel so it executes a queued command instantly
 * instead of waiting for the next poll cycle. The durable copy lives in `mdm_commands`.
 */
class DeviceCommandIssued implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MdmCommand $command)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device.' . $this->command->device_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'command';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->command->id,
            'command_type' => $this->command->command_type,
            'payload' => $this->command->payload,
            'created_at' => optional($this->command->created_at)->toIso8601String(),
        ];
    }
}
