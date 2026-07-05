<?php

namespace App\Events;

use App\Models\Alert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the org dashboard channel so the alert feed updates live (tamper + fleet-health).
 */
class AlertRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Alert $alert)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('org.' . $this->alert->org_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.raised';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'device_id' => $this->alert->device_id,
            'type' => $this->alert->type,
            'severity' => $this->alert->severity,
            'message' => $this->alert->message,
            'status' => $this->alert->status,
            'created_at' => optional($this->alert->created_at)->toIso8601String(),
        ];
    }
}
