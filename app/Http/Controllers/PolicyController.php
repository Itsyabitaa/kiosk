<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PolicyController extends Controller
{
    /**
     * Display a listing of the latest version of each policy.
     */
    public function index()
    {
        // Get the latest version for each policy group belonging to the organization
        $policies = Policy::whereIn('id', function ($query) {
            $query->selectRaw('MAX(id)')
                ->from('policies')
                ->groupBy('group_id');
        })->get();

        return response()->json($policies);
    }

    /**
     * Store a newly created policy.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'policy_type' => 'required|string|in:single_app,url_whitelist',
            'target' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('policy_type') === 'single_app' && !empty($value)) {
                        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $value)) {
                            $fail('The target must be a valid package name / bundle ID.');
                        }
                    }
                }
            ],
            'restrictions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Enforce org_id from authenticated admin
        $orgId = auth('api')->user()->org_id;

        $policy = Policy::create([
            'org_id' => $orgId,
            'name' => $data['name'],
            'policy_type' => $data['policy_type'],
            'target' => $data['target'],
            'restrictions' => $data['restrictions'],
            'version' => 1,
            'status' => 'draft',
        ]);

        // Set group_id to itself for version 1
        $policy->update(['group_id' => $policy->id]);

        return response()->json($policy, 201);
    }

    /**
     * Update the specified policy (creates a new version if published).
     */
    public function update(Request $request, $id)
    {
        $oldPolicy = Policy::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'policy_type' => 'required|string|in:single_app,url_whitelist',
            'target' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('policy_type') === 'single_app' && !empty($value)) {
                        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $value)) {
                            $fail('The target must be a valid package name / bundle ID.');
                        }
                    }
                }
            ],
            'restrictions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if ($oldPolicy->status === 'published') {
            // Edit of published policy creates a new version
            $maxVersion = Policy::withoutGlobalScopes()
                ->where('group_id', $oldPolicy->group_id)
                ->max('version');

            $newPolicy = Policy::create([
                'org_id' => $oldPolicy->org_id,
                'name' => $data['name'],
                'group_id' => $oldPolicy->group_id,
                'policy_type' => $data['policy_type'],
                'target' => $data['target'],
                'restrictions' => $data['restrictions'],
                'version' => $maxVersion + 1,
                'status' => 'draft', // starts as draft
            ]);

            return response()->json($newPolicy, 201);
        } else {
            // Draft is updated in place
            $oldPolicy->update([
                'name' => $data['name'],
                'policy_type' => $data['policy_type'],
                'target' => $data['target'],
                'restrictions' => $data['restrictions'],
            ]);

            return response()->json($oldPolicy, 200);
        }
    }

    /**
     * Publish the specified policy.
     */
    public function publish($id)
    {
        $policy = Policy::findOrFail($id);

        $policy->update(['status' => 'published']);

        return response()->json($policy, 200);
    }

    /**
     * Assign the specified policy to a device.
     */
    public function assign(Request $request, $id)
    {
        $policy = Policy::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'device_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $deviceId = $request->input('device_id');
        $device = Device::findOrFail($deviceId); // Enforces org tenancy

        if ($policy->status !== 'published') {
            return response()->json(['error' => 'Cannot assign a draft policy.'], 400);
        }

        $assignment = PolicyAssignment::updateOrCreate(
            ['device_id' => $device->id],
            [
                'policy_id' => $policy->id,
                'assigned_at' => now(),
                'status' => 'pending',
            ]
        );

        return response()->json($assignment, 200);
    }
}
