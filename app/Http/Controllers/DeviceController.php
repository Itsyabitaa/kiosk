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
     * Chronological, merged device timeline: policy changes, lock/unlock + generic events,
     * tamper/health alerts, and recent telemetry snapshots, newest first.
     */
    public function timeline(Request $request, $id)
    {
        // findOrFail enforces org tenancy via the global scope.
        $device = Device::findOrFail($id);
        $limit = (int) $request->query('limit', 100);

        $timeline = collect();

        foreach ($device->events()->latest()->limit($limit)->get() as $event) {
            $timeline->push([
                'type' => 'event',
                'event_type' => $event->event_type,
                'status' => $event->status,
                'details' => $event->details,
                'timestamp' => optional($event->created_at)->toIso8601String(),
            ]);
        }

        foreach (\App\Models\Alert::where('device_id', $device->id)->latest()->limit($limit)->get() as $alert) {
            $timeline->push([
                'type' => 'alert',
                'alert_type' => $alert->type,
                'severity' => $alert->severity,
                'message' => $alert->message,
                'status' => $alert->status,
                'timestamp' => optional($alert->created_at)->toIso8601String(),
            ]);
        }

        foreach (\App\Models\DeviceTelemetry::where('device_id', $device->id)->latest('recorded_at')->limit($limit)->get() as $t) {
            $timeline->push([
                'type' => 'telemetry',
                'battery_level' => $t->battery_level,
                'connectivity_type' => $t->connectivity_type,
                'app_version' => $t->app_version,
                'os_version' => $t->os_version,
                'timestamp' => optional($t->recorded_at)->toIso8601String(),
            ]);
        }

        $assignment = \App\Models\PolicyAssignment::where('device_id', $device->id)->first();
        if ($assignment) {
            $timeline->push([
                'type' => 'policy_change',
                'policy_id' => $assignment->policy_id,
                'status' => $assignment->status,
                'applied_version' => $assignment->applied_version,
                'timestamp' => optional($assignment->assigned_at)->toIso8601String(),
            ]);
        }

        $sorted = $timeline
            ->sortByDesc('timestamp')
            ->values()
            ->all();

        return response()->json([
            'device_id' => $device->id,
            'timeline' => $sorted,
        ]);
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
