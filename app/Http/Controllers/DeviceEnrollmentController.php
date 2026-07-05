<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\EnrollmentToken;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTFactory;
use Carbon\Carbon;

class DeviceEnrollmentController extends Controller
{
    public function enroll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'hardware_fingerprint' => 'required|string',
            'platform' => 'required|string',
            'enrollment_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Consume the token and transition the device state atomically. Locking the token row
        // serializes concurrent enrollment attempts so a single-use token can only ever be
        // redeemed once, even under a race.
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
                $enrollmentToken = EnrollmentToken::withoutGlobalScopes()
                    ->where('token', $request->enrollment_token)
                    ->lockForUpdate()
                    ->first();

                if (!$enrollmentToken) {
                    return ['status' => 401, 'body' => ['error' => 'Invalid or expired enrollment token']];
                }

                if ($enrollmentToken->expires_at && $enrollmentToken->expires_at->isPast()) {
                    return ['status' => 401, 'body' => ['error' => 'Invalid or expired enrollment token']];
                }

                if ($enrollmentToken->single_use && $enrollmentToken->used_at !== null) {
                    return ['status' => 401, 'body' => ['error' => 'Invalid or expired enrollment token']];
                }

                $fingerprint = $request->hardware_fingerprint;
                $orgId = $enrollmentToken->org_id;

                // Check for duplicate enrollment attempts from the same hardware_fingerprint
                $device = Device::withoutGlobalScopes()->where('hardware_fingerprint', $fingerprint)->first();

                if ($device) {
                    $device->update([
                        'org_id' => $orgId,
                        'enrollment_status' => 'enrolled',
                        'platform' => $request->platform,
                        'last_seen_at' => Carbon::now(),
                    ]);
                } else {
                    $device = Device::create([
                        'org_id' => $orgId,
                        'device_uid' => (string) \Illuminate\Support\Str::uuid(),
                        'hardware_fingerprint' => $fingerprint,
                        'platform' => $request->platform,
                        'enrollment_status' => 'enrolled',
                        'last_seen_at' => Carbon::now(),
                    ]);
                }

                // If the token bundles a policy, assign it to the freshly enrolled device.
                if ($enrollmentToken->policy_id) {
                    $policy = Policy::withoutGlobalScopes()
                        ->where('id', $enrollmentToken->policy_id)
                        ->where('status', 'published')
                        ->first();

                    if ($policy) {
                        PolicyAssignment::updateOrCreate(
                            ['device_id' => $device->id],
                            [
                                'policy_id' => $policy->id,
                                'assigned_at' => Carbon::now(),
                                'status' => 'pending',
                            ]
                        );
                    }
                }

                // Mark single-use tokens consumed within the same transaction as the state change.
                if ($enrollmentToken->single_use) {
                    $enrollmentToken->update(['used_at' => Carbon::now()]);
                }

                $customClaims = [
                    'sub' => $device->id,
                    'type' => 'device_token',
                    'device_uid' => $device->device_uid,
                    'org_id' => $orgId,
                    'exp' => Carbon::now()->addYears(10)->timestamp, // long-lived
                ];

                $payload = JWTFactory::customClaims($customClaims)->make();
                $deviceToken = JWTAuth::encode($payload)->get();

                return [
                    'status' => 200,
                    'body' => [
                        'device_id' => $device->id,
                        'device_token' => $deviceToken,
                    ],
                ];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['error' => 'Enrollment failed'], 500);
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * Get the assigned policy for the authenticated device.
     */
    public function getPolicy(Request $request, $id)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $deviceId = $payload->get('sub');
            $tokenType = $payload->get('type');

            if ($tokenType !== 'device_token' || (string)$deviceId !== (string)$id) {
                return response()->json(['error' => 'Unauthorized device'], 401);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid device token: ' . $e->getMessage()], 401);
        }

        $device = Device::withoutGlobalScopes()->findOrFail($id);
        $assignment = PolicyAssignment::where('device_id', $device->id)->first();

        if (!$assignment) {
            return response()->json([
                'policy' => null,
            ]);
        }

        $policy = Policy::withoutGlobalScopes()->find($assignment->policy_id);

        return response()->json([
            'policy' => $policy,
            'assignment_status' => $assignment->status,
        ]);
    }

    /**
     * Acknowledge the policy application by the device.
     */
    public function ackPolicy(Request $request, $id)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $deviceId = $payload->get('sub');
            $tokenType = $payload->get('type');

            if ($tokenType !== 'device_token' || (string)$deviceId !== (string)$id) {
                return response()->json(['error' => 'Unauthorized device'], 401);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid device token: ' . $e->getMessage()], 401);
        }

        $validator = Validator::make($request->all(), [
            'policy_id' => 'required|integer',
            'status' => 'required|string|in:applied,error',
            'error_message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $policyId = $request->input('policy_id');
        $status = $request->input('status');

        $assignment = PolicyAssignment::where('device_id', $deviceId)
            ->where('policy_id', $policyId)
            ->firstOrFail();

        $assignment->update([
            'status' => $status,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Log a device event.
     */
    public function logEvent(Request $request, $id)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $deviceId = $payload->get('sub');
            $tokenType = $payload->get('type');

            if ($tokenType !== 'device_token' || (string)$deviceId !== (string)$id) {
                return response()->json(['error' => 'Unauthorized device'], 401);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid device token: ' . $e->getMessage()], 401);
        }

        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string',
            'status' => 'required|string',
            'details' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = DeviceEvent::create([
            'device_id' => $deviceId,
            'event_type' => $request->input('event_type'),
            'status' => $request->input('status'),
            'details' => $request->input('details'),
        ]);

        return response()->json($event, 201);
    }

    public function deviceUnlock(Request $request, $id)
    {
        if (!$this->validateDeviceToken($id)) {
            return response()->json(['error' => 'Unauthorized device'], 401);
        }

        $device = Device::withoutGlobalScopes()->findOrFail($id);

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
                    'initiated_by' => 'device_exit_gesture',
                ],
            ]);
        } else {
            \App\Models\DeviceEvent::create([
                'device_id' => $device->id,
                'event_type' => 'unlock',
                'status' => 'success',
                'details' => [
                    'initiated_by' => 'device_exit_gesture',
                    'platform' => 'android',
                ],
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function generateMdmProfile(Request $request, $id)
    {
        if (!$this->validateDeviceToken($id)) {
            return response()->json(['error' => 'Unauthorized device'], 401);
        }

        $device = Device::withoutGlobalScopes()->findOrFail($id);
        $assignment = PolicyAssignment::where('device_id', $device->id)->first();
        $policy = $assignment ? Policy::withoutGlobalScopes()->find($assignment->policy_id) : null;
        $restrictions = $policy ? $policy->restrictions : [];

        $allowErase = isset($restrictions['block_factory_reset']) ? !$restrictions['block_factory_reset'] : false;
        $allowAppInstallation = isset($restrictions['block_install_apps']) ? !$restrictions['block_install_apps'] : false;
        $allowAppRemoval = isset($restrictions['block_uninstall_apps']) ? !$restrictions['block_uninstall_apps'] : false;

        $allowSettings = isset($restrictions['block_settings']) ? !$restrictions['block_settings'] : false;
        $allowAirDrop = isset($restrictions['block_airdrop']) ? !$restrictions['block_airdrop'] : false;
        $allowAirPlay = isset($restrictions['block_airplay']) ? !$restrictions['block_airplay'] : false;
        $allowControlCenter = isset($restrictions['block_control_center']) ? !$restrictions['block_control_center'] : false;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadDisplayName</key>
    <string>Kiosk KioskLock Profile</string>
    <key>PayloadIdentifier</key>
    <string>com.kiosklock.profile</string>
    <key>PayloadRemovalDisallowed</key>
    <true/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>f3b5c16d-fd45-f38c-cc8f-ef2f3da56838</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>PayloadDisplayName</key>
            <string>Restrictions</string>
            <key>PayloadIdentifier</key>
            <string>com.apple.applicationaccess</string>
            <key>PayloadType</key>
            <string>com.apple.applicationaccess</string>
            <key>PayloadUUID</key>
            <string>cc8f-ef2f3da56838-f3b5c16d-fd45-f38c</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
            <key>allowEraseContentAndSettings</key>
            ' . ($allowErase ? '<true/>' : '<false/>') . '
            <key>allowAppInstallation</key>
            ' . ($allowAppInstallation ? '<true/>' : '<false/>') . '
            <key>allowAppRemoval</key>
            ' . ($allowAppRemoval ? '<true/>' : '<false/>') . '
            <key>allowSettings</key>
            ' . ($allowSettings ? '<true/>' : '<false/>') . '
            <key>allowAirDrop</key>
            ' . ($allowAirDrop ? '<true/>' : '<false/>') . '
            <key>allowAirPlay</key>
            ' . ($allowAirPlay ? '<true/>' : '<false/>') . '
            <key>allowControlCenter</key>
            ' . ($allowControlCenter ? '<true/>' : '<false/>') . '
        </dict>
    </array>
</dict>
</plist>';

        return response($xml, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="kiosk.mobileconfig"',
        ]);
    }

    public function getMdmCommands(Request $request, $id)
    {
        if (!$this->validateDeviceToken($id)) {
            return response()->json(['error' => 'Unauthorized device'], 401);
        }

        $commands = \App\Models\MdmCommand::where('device_id', $id)
            ->where('status', 'pending')
            ->get();

        return response()->json(['commands' => $commands]);
    }

    public function ackMdmCommand(Request $request, $id, $commandId)
    {
        if (!$this->validateDeviceToken($id)) {
            return response()->json(['error' => 'Unauthorized device'], 401);
        }

        $command = \App\Models\MdmCommand::where('device_id', $id)
            ->where('id', $commandId)
            ->firstOrFail();

        $command->update([
            'status' => 'acknowledged',
        ]);

        \App\Models\DeviceEvent::create([
            'device_id' => $id,
            'event_type' => 'mdm_command',
            'status' => 'acknowledged',
            'details' => [
                'command' => $command->command_type,
                'platform' => 'ios',
            ],
        ]);

        return response()->json(['success' => true]);
    }

    private function validateDeviceToken($id)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $deviceId = $payload->get('sub');
            $tokenType = $payload->get('type');

            if ($tokenType !== 'device_token' || (string)$deviceId !== (string)$id) {
                return false;
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
