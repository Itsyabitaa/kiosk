<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    /**
     * Display a paginated and filterable listing of the devices.
     */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $query = Device::with('assignedPolicy');

        if ($status) {
            $query->where('enrollment_status', $status);
        }

        $devices = $query->paginate($request->query('per_page', 15));

        return response()->json($devices);
    }

    /**
     * Display the specified device details.
     */
    public function show($id)
    {
        $device = Device::with(['assignedPolicy', 'events'])->findOrFail($id);

        return response()->json([
            'device' => $device,
            'event_logs' => $device->events,
        ]);
    }

    /**
     * Unlock/Exit kiosk mode on a device (Admin action).
     */
    public function unlock($id)
    {
        $device = Device::findOrFail($id);

        if ($device->platform === 'ios') {
            \App\Models\MdmCommand::create([
                'device_id' => $device->id,
                'command_type' => 'RemoveProfile',
                'status' => 'pending',
            ]);

            \App\Models\DeviceEvent::create([
                'device_id' => $device->id,
                'event_type' => 'mdm_command',
                'status' => 'sent',
                'details' => [
                    'command' => 'RemoveProfile',
                    'platform' => 'ios',
                    'initiated_by' => 'admin_dashboard',
                ],
            ]);
        } else {
            \App\Models\DeviceEvent::create([
                'device_id' => $device->id,
                'event_type' => 'unlock',
                'status' => 'success',
                'details' => [
                    'initiated_by' => 'admin_dashboard',
                    'platform' => 'android',
                ],
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Attach a tag to a device (tag-based grouping).
     */
    public function addTag(Request $request, $id)
    {
        $device = Device::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tag = DeviceTag::firstOrCreate([
            'device_id' => $device->id,
            'tag' => $request->input('tag'),
        ]);

        return response()->json($tag, 201);
    }

    /**
     * Remove a tag from a device.
     */
    public function removeTag(Request $request, $id, $tag)
    {
        $device = Device::findOrFail($id);

        DeviceTag::where('device_id', $device->id)->where('tag', $tag)->delete();

        return response()->json(['success' => true]);
    }
}
