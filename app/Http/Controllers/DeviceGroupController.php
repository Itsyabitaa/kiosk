<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchDeviceCommand;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\MdmCommand;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeviceGroupController extends Controller
{
    public function index()
    {
        $groups = DeviceGroup::withCount('memberships')->get();

        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $group = DeviceGroup::create([
            'org_id' => auth('api')->user()->org_id,
            'name' => $request->input('name'),
        ]);

        return response()->json($group, 201);
    }

    public function show($id)
    {
        $group = DeviceGroup::with('devices')->findOrFail($id);

        return response()->json($group);
    }

    /**
     * Attach devices to the group. Device ids are filtered to the caller's org via the
     * global org scope so cross-tenant devices cannot be added.
     */
    public function addMembers(Request $request, $id)
    {
        $group = DeviceGroup::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Org scope ensures only devices in this org resolve.
        $deviceIds = Device::whereIn('id', $request->input('device_ids'))->pluck('id')->all();

        $group->devices()->syncWithoutDetaching($deviceIds);

        return response()->json([
            'group_id' => $group->id,
            'added' => count($deviceIds),
            'member_count' => $group->devices()->count(),
        ]);
    }

    public function removeMembers(Request $request, $id)
    {
        $group = DeviceGroup::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $group->devices()->detach($request->input('device_ids'));

        return response()->json([
            'group_id' => $group->id,
            'member_count' => $group->devices()->count(),
        ]);
    }

    /**
     * Bulk assign a policy to every member of the group. Assignments are written in a
     * transaction; command fan-out is queued (one job per device) so large groups do not
     * block the request or the broadcaster.
     */
    public function assignPolicy(Request $request, $id)
    {
        $group = DeviceGroup::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'policy_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $policy = Policy::find($request->input('policy_id'));
        if (!$policy) {
            return response()->json(['error' => 'Policy not found for this organization.'], 404);
        }

        if ($policy->status !== 'published') {
            return response()->json(['error' => 'Cannot assign a draft policy.'], 422);
        }

        $deviceIds = $group->devices()->pluck('devices.id')->all();

        DB::transaction(function () use ($deviceIds, $policy) {
            foreach ($deviceIds as $deviceId) {
                PolicyAssignment::updateOrCreate(
                    ['device_id' => $deviceId],
                    [
                        'policy_id' => $policy->id,
                        'assigned_at' => now(),
                        'status' => 'pending',
                    ]
                );
            }
        });

        // Fan out real-time policy_update commands off the request cycle.
        foreach ($deviceIds as $deviceId) {
            DispatchDeviceCommand::dispatch(
                $deviceId,
                MdmCommand::TYPE_POLICY_UPDATE,
                ['policy_id' => $policy->id, 'version' => $policy->version]
            );
        }

        return response()->json([
            'group_id' => $group->id,
            'policy_id' => $policy->id,
            'devices_targeted' => count($deviceIds),
        ]);
    }
}
