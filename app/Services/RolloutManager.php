<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MdmCommand;
use App\Models\PolicyAssignment;
use App\Models\PolicyRollout;
use Illuminate\Support\Facades\DB;

/**
 * Drives staged rollouts: assigns a policy to a random N% of a group first, tracks per-device
 * delivery, and supports pause / resume / rollback / complete-to-100%.
 */
class RolloutManager
{
    /**
     * Advance a rollout by assigning the policy to enough randomly-selected members to reach
     * the target percentage, pushing a real-time policy_update to each. Idempotent and safe to
     * call repeatedly (resume, scheduler tick, complete).
     */
    public static function processWave(PolicyRollout $rollout): void
    {
        if (!in_array($rollout->status, [PolicyRollout::STATUS_SCHEDULED, PolicyRollout::STATUS_IN_PROGRESS], true)) {
            return;
        }

        if ($rollout->status === PolicyRollout::STATUS_SCHEDULED) {
            $rollout->update(['status' => PolicyRollout::STATUS_IN_PROGRESS]);
        }

        $memberIds = self::memberIds($rollout);
        $total = count($memberIds);

        if ($total === 0) {
            $rollout->update(['status' => PolicyRollout::STATUS_COMPLETED]);
            return;
        }

        $targetCount = (int) ceil(($rollout->rollout_percentage / 100) * $total);

        $alreadyRolledIds = PolicyAssignment::where('rollout_id', $rollout->id)
            ->pluck('device_id')
            ->all();

        $need = $targetCount - count($alreadyRolledIds);
        if ($need <= 0) {
            return;
        }

        // Randomly pick from members not yet part of this rollout wave.
        $candidates = array_values(array_diff($memberIds, $alreadyRolledIds));
        shuffle($candidates);
        $selected = array_slice($candidates, 0, $need);

        foreach ($selected as $deviceId) {
            DB::transaction(function () use ($deviceId, $rollout) {
                $existing = PolicyAssignment::where('device_id', $deviceId)->first();
                $previousPolicyId = $existing?->policy_id;

                PolicyAssignment::updateOrCreate(
                    ['device_id' => $deviceId],
                    [
                        'policy_id' => $rollout->policy_id,
                        'assigned_at' => now(),
                        'status' => 'pending',
                        'rollout_id' => $rollout->id,
                        'previous_policy_id' => $previousPolicyId,
                    ]
                );

                $device = Device::withoutGlobalScopes()->find($deviceId);
                if ($device) {
                    CommandDispatcher::issue(
                        $device,
                        MdmCommand::TYPE_POLICY_UPDATE,
                        ['policy_id' => $rollout->policy_id, 'rollout_id' => $rollout->id],
                        $rollout->id
                    );
                }
            });
        }
    }

    public static function pause(PolicyRollout $rollout): void
    {
        if ($rollout->status === PolicyRollout::STATUS_IN_PROGRESS) {
            $rollout->update(['status' => PolicyRollout::STATUS_PAUSED]);
        }
    }

    public static function resume(PolicyRollout $rollout): void
    {
        if ($rollout->status === PolicyRollout::STATUS_PAUSED) {
            $rollout->update(['status' => PolicyRollout::STATUS_IN_PROGRESS]);
            self::processWave($rollout);
        }
    }

    /**
     * Push the rollout to 100% of the group.
     */
    public static function complete(PolicyRollout $rollout): void
    {
        if (in_array($rollout->status, [PolicyRollout::STATUS_COMPLETED, PolicyRollout::STATUS_ROLLED_BACK], true)) {
            return;
        }

        $rollout->update([
            'rollout_percentage' => 100,
            'status' => PolicyRollout::STATUS_IN_PROGRESS,
        ]);

        self::processWave($rollout->fresh());
    }

    /**
     * Revert every device touched by this rollout back to its previous policy (or clear the
     * assignment if it had none) and push the change out.
     */
    public static function rollback(PolicyRollout $rollout): void
    {
        $assignments = PolicyAssignment::where('rollout_id', $rollout->id)->get();

        foreach ($assignments as $assignment) {
            DB::transaction(function () use ($assignment) {
                $device = Device::withoutGlobalScopes()->find($assignment->device_id);

                if ($assignment->previous_policy_id) {
                    $assignment->update([
                        'policy_id' => $assignment->previous_policy_id,
                        'assigned_at' => now(),
                        'status' => 'pending',
                        'rollout_id' => null,
                        'previous_policy_id' => null,
                    ]);

                    if ($device) {
                        CommandDispatcher::issue(
                            $device,
                            MdmCommand::TYPE_POLICY_UPDATE,
                            ['policy_id' => $assignment->policy_id, 'rolled_back' => true]
                        );
                    }
                } else {
                    $assignment->delete();
                    if ($device) {
                        CommandDispatcher::issue($device, MdmCommand::TYPE_UNLOCK, ['rolled_back' => true]);
                    }
                }
            });
        }

        $rollout->update(['status' => PolicyRollout::STATUS_ROLLED_BACK]);
    }

    /**
     * Called when a rollout command is acknowledged so completion can be detected.
     */
    public static function onCommandAcked(MdmCommand $command): void
    {
        $rollout = PolicyRollout::withoutGlobalScopes()->find($command->rollout_id);
        if (!$rollout || $rollout->status !== PolicyRollout::STATUS_IN_PROGRESS) {
            return;
        }

        $progress = self::progress($rollout);
        if ($progress['delivered'] >= $progress['target_count'] && $rollout->rollout_percentage >= 100) {
            $rollout->update(['status' => PolicyRollout::STATUS_COMPLETED]);
        }
    }

    /**
     * @return array{total:int,target_count:int,rolled_out:int,delivered:int,percentage:int}
     */
    public static function progress(PolicyRollout $rollout): array
    {
        $memberIds = self::memberIds($rollout);
        $total = count($memberIds);
        $targetCount = (int) ceil(($rollout->rollout_percentage / 100) * $total);

        $rolledOut = PolicyAssignment::where('rollout_id', $rollout->id)->count();
        $delivered = MdmCommand::where('rollout_id', $rollout->id)
            ->where('status', 'acknowledged')
            ->distinct('device_id')
            ->count('device_id');

        return [
            'total' => $total,
            'target_count' => $targetCount,
            'rolled_out' => $rolledOut,
            'delivered' => $delivered,
            'percentage' => $rollout->rollout_percentage,
        ];
    }

    /**
     * @return int[]
     */
    private static function memberIds(PolicyRollout $rollout): array
    {
        if (!$rollout->group_id) {
            return [];
        }

        return DB::table('device_group_memberships')
            ->where('group_id', $rollout->group_id)
            ->pluck('device_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
