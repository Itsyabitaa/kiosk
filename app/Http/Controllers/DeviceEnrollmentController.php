<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\EnrollmentToken;
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

        // Validate the enrollment token
        $enrollmentToken = EnrollmentToken::where('token', $request->enrollment_token)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', Carbon::now());
            })
            ->whereNull('used_at') // Depending on if it's single-use
            ->first();

        if (!$enrollmentToken) {
            return response()->json(['error' => 'Invalid or expired enrollment token'], 401);
        }

        $fingerprint = $request->hardware_fingerprint;
        $orgId = $enrollmentToken->org_id;

        // Check for duplicate enrollment attempts from the same hardware_fingerprint
        $device = Device::withoutGlobalScopes()->where('hardware_fingerprint', $fingerprint)->first();

        if ($device) {
            // Update existing device
            $device->update([
                'org_id' => $orgId, // In case the org changed? Or just use current org.
                'enrollment_status' => 'pending',
                'platform' => $request->platform,
                'last_seen_at' => Carbon::now(),
            ]);
        } else {
            // Create a new device
            $device = Device::create([
                'org_id' => $orgId,
                'device_uid' => (string) \Illuminate\Support\Str::uuid(),
                'hardware_fingerprint' => $fingerprint,
                'platform' => $request->platform,
                'enrollment_status' => 'pending',
                'last_seen_at' => Carbon::now(),
            ]);
        }

        // Mark the token as used if it's single-use
        // $enrollmentToken->update(['used_at' => Carbon::now()]);

        // Generate a long-lived JWT for the device
        $customClaims = [
            'sub' => $device->id,
            'type' => 'device_token',
            'device_uid' => $device->device_uid,
            'org_id' => $orgId,
            'exp' => Carbon::now()->addYears(10)->timestamp // long-lived
        ];
        
        $payload = JWTFactory::customClaims($customClaims)->make();
        $deviceToken = JWTAuth::encode($payload)->get();

        return response()->json([
            'device_id' => $device->id,
            'device_token' => $deviceToken,
        ]);
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
}
