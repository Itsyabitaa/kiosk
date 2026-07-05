<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRolloutWave;
use App\Models\DeviceGroup;
use App\Models\Policy;
use App\Models\PolicyRollout;
use App\Services\RolloutManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RolloutController extends Controller
{
    /**
     * Create a staged rollout of a policy to a group. Starts immediately unless scheduled_at
     * is in the future (the scheduler picks scheduled rollouts up).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'policy_id' => 'required|integer',
            'group_id' => 'required|integer',
            'rollout_percentage' => 'required|integer|min:1|max:100',
            'scheduled_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $policy = Policy::find($request->input('policy_id'));
        if (!$policy) {
            return response()->json(['error' => 'Policy not found for this organization.'], 404);
        }
        if ($policy->status !== 'published') {
            return response()->json(['error' => 'Cannot roll out a draft policy.'], 422);
        }

        $group = DeviceGroup::find($request->input('group_id'));
        if (!$group) {
            return response()->json(['error' => 'Group not found for this organization.'], 404);
        }

        $scheduledAt = $request->input('scheduled_at');

        $rollout = PolicyRollout::create([
            'org_id' => auth('api')->user()->org_id,
            'policy_id' => $policy->id,
            'group_id' => $group->id,
            'rollout_percentage' => $request->input('rollout_percentage'),
            'scheduled_at' => $scheduledAt,
            'status' => PolicyRollout::STATUS_SCHEDULED,
        ]);

        // Start now unless scheduled for the future.
        if (!$scheduledAt || $rollout->scheduled_at->isPast()) {
            ProcessRolloutWave::dispatch($rollout->id);
        }

        return response()->json($this->present($rollout->fresh()), 201);
    }

    public function show($id)
    {
        $rollout = PolicyRollout::findOrFail($id);

        return response()->json($this->present($rollout));
    }

    public function pause($id)
    {
        $rollout = PolicyRollout::findOrFail($id);
        RolloutManager::pause($rollout);

        return response()->json($this->present($rollout->fresh()));
    }

    public function resume($id)
    {
        $rollout = PolicyRollout::findOrFail($id);
        RolloutManager::resume($rollout);

        return response()->json($this->present($rollout->fresh()));
    }

    public function complete($id)
    {
        $rollout = PolicyRollout::findOrFail($id);
        RolloutManager::complete($rollout);

        return response()->json($this->present($rollout->fresh()));
    }

    public function rollback($id)
    {
        $rollout = PolicyRollout::findOrFail($id);
        RolloutManager::rollback($rollout);

        return response()->json($this->present($rollout->fresh()));
    }

    private function present(PolicyRollout $rollout): array
    {
        return [
            'id' => $rollout->id,
            'policy_id' => $rollout->policy_id,
            'group_id' => $rollout->group_id,
            'rollout_percentage' => $rollout->rollout_percentage,
            'scheduled_at' => optional($rollout->scheduled_at)->toIso8601String(),
            'status' => $rollout->status,
            'progress' => RolloutManager::progress($rollout),
        ];
    }
}
