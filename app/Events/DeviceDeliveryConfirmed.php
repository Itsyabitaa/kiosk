<?php

namespace App\Events;

use App\Models\MdmCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the org dashboard channel when a device acknowledges a command, so the fleet
 * view can show live per-device delivery confirmation and rollout progress.
 */
class DeviceDeliveryConfirmed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MdmCommand $command,
        public int $orgId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org.' . $this->orgId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'delivery.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->id,
            'device_id' => $this->command->device_id,
            'command_type' => $this->command->command_type,
            'status' => $this->command->status,
            'rollout_id' => $this->command->rollout_id,
            'acked_at' => optional($this->command->acked_at)->toIso8601String(),
        ];
    }
}
