<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchDeviceCommand;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\MdmCommand;
use App\Services\CommandDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceCommandController extends Controller
{
    /**
     * Issue a real-time command to a single device. Broadcast happens synchronously (single
     * device is cheap); the row is persisted for the poll fallback + delivery confirmation.
     */
    public function issueToDevice(Request $request, $id)
    {
        $device = Device::findOrFail($id);

        $validator = $this->validator($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $command = CommandDispatcher::issue(
            $device,
            $request->input('command_type'),
            $request->input('payload', [])
        );

        return response()->json([
            'command_id' => $command->id,
            'device_id' => $device->id,
            'command_type' => $command->command_type,
            'status' => $command->status,
        ], 201);
    }

    /**
     * Issue a command to every member of a group. Fan-out is queued (one job per device) so a
     * 50+ device push completes without the request blocking or the broadcaster falling behind.
     */
    public function issueToGroup(Request $request, $id)
    {
        $group = DeviceGroup::findOrFail($id);

        $validator = $this->validator($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $deviceIds = $group->devices()->pluck('devices.id')->all();
        $type = $request->input('command_type');
        $payload = $request->input('payload', []);

        foreach ($deviceIds as $deviceId) {
            DispatchDeviceCommand::dispatch($deviceId, $type, $payload);
        }

        return response()->json([
            'group_id' => $group->id,
            'command_type' => $type,
            'devices_targeted' => count($deviceIds),
        ], 202);
    }

    private function validator(Request $request)
    {
        return Validator::make($request->all(), [
            'command_type' => 'required|string|in:' . implode(',', MdmCommand::REMOTE_COMMAND_TYPES),
            'payload' => 'nullable|array',
        ]);
    }
}
