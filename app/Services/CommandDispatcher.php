<?php

namespace App\Services;

use App\Events\DeviceCommandIssued;
use App\Models\Device;
use App\Models\MdmCommand;

/**
 * Central place to enqueue a device command. Writes the durable row to `mdm_commands`
 * (the source of truth for the poll fallback) and broadcasts it over Reverb for instant
 * delivery to the connected device.
 */
class CommandDispatcher
{
    public static function issue(Device $device, string $type, array $payload = [], ?int $rolloutId = null): MdmCommand
    {
        $command = MdmCommand::create([
            'device_id' => $device->id,
            'command_type' => $type,
            'status' => 'pending',
            'payload' => $payload ?: null,
            'rollout_id' => $rolloutId,
        ]);

        // Dispatched via event() (not broadcast()) so the ShouldBroadcast event both broadcasts
        // in production and is assertable with Event::fake in tests.
        event(new DeviceCommandIssued($command));

        return $command;
    }
}
