<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\EnrollmentToken;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupSVG;

class DeviceDeploymentController extends Controller
{
    /**
     * Fully qualified name of the Device Admin receiver used during Android Enterprise
     * device-owner provisioning. Must match the component declared in the agent manifest.
     */
    private const ADMIN_COMPONENT =
        'com.kiosklock.kiosklock_agent/com.kiosklock.kiosklock_agent.KioskDeviceAdminReceiver';

    /**
     * Build an Android Enterprise compliant provisioning payload (bundling WiFi + policy
     * data into the PROVISIONING_ADMIN_EXTRAS_BUNDLE) and render it as an SVG QR code.
     *
     * The generated enrollment token is single-use and (optionally) time boxed so that a
     * printed QR sheet cannot be replayed to enroll rogue devices.
     */
    public function generateDeploymentQr(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'policy_id' => 'nullable|integer',
            'wifi_ssid' => 'nullable|string',
            'wifi_password' => 'nullable|string',
            'wifi_security_type' => 'nullable|string|in:NONE,WPA,WEP,EAP',
            'wifi_hidden' => 'nullable|boolean',
            'apk_download_url' => 'nullable|url',
            'signature_checksum' => 'nullable|string',
            'expires_in_hours' => 'nullable|integer|min:1|max:8760',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orgId = auth('api')->user()->org_id;

        // If a policy is supplied it must belong to the caller's organization and be published.
        $policyId = $request->input('policy_id');
        if ($policyId !== null) {
            $policy = Policy::where('id', $policyId)->first();
            if (!$policy) {
                return response()->json(['error' => 'Policy not found for this organization.'], 404);
            }
            if ($policy->status !== 'published') {
                return response()->json(['error' => 'Cannot bundle a draft policy into a deployment QR.'], 422);
            }
        }

        $expiresInHours = $request->input('expires_in_hours');

        $enrollmentToken = EnrollmentToken::create([
            'org_id' => $orgId,
            'policy_id' => $policyId,
            'token' => Str::random(48),
            'single_use' => true,
            'expires_at' => $expiresInHours ? Carbon::now()->addHours((int) $expiresInHours) : null,
        ]);

        $payload = $this->buildProvisioningPayload($enrollmentToken, $request);

        // JSON_UNESCAPED_SLASHES keeps component names / URLs readable and shrinks the QR.
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return response()->json([
            'token' => $enrollmentToken->token,
            'expires_at' => $enrollmentToken->expires_at,
            'payload' => $payload,
            'qr_svg' => $this->renderSvg($payloadJson),
        ]);
    }

    /**
     * Assemble the Android Enterprise provisioning extras. The device-specific enrollment
     * data (server URL, token, policy) is nested under PROVISIONING_ADMIN_EXTRAS_BUNDLE and
     * surfaced to the agent via DevicePolicyManager#getAdminExtras().
     *
     * @return array<string, mixed>
     */
    private function buildProvisioningPayload(EnrollmentToken $token, Request $request): array
    {
        $serverUrl = rtrim(config('app.url', 'http://localhost'), '/') . '/api';

        $adminExtras = array_filter([
            'enrollment_token' => $token->token,
            'policy_id' => $token->policy_id ? (string) $token->policy_id : null,
            'org_id' => (string) $token->org_id,
            'server_url' => $serverUrl,
        ], static fn ($value) => $value !== null);

        $payload = [
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => self::ADMIN_COMPONENT,
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' =>
                $request->input('apk_download_url', config('app.url', 'http://localhost') . '/downloads/kiosklock-agent.apk'),
            'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE' => $adminExtras,
            'android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED' => true,
            'android.app.extra.PROVISIONING_SKIP_ENCRYPTION' => false,
        ];

        if ($checksum = $request->input('signature_checksum')) {
            $payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM'] = $checksum;
        }

        // WiFi provisioning lets the device join the network during setup before it has any
        // user configured connectivity.
        if ($ssid = $request->input('wifi_ssid')) {
            $payload['android.app.extra.PROVISIONING_WIFI_SSID'] = $ssid;
            $payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE'] =
                $request->input('wifi_security_type', 'WPA');
            $payload['android.app.extra.PROVISIONING_WIFI_HIDDEN'] =
                (bool) $request->input('wifi_hidden', false);

            if ($password = $request->input('wifi_password')) {
                $payload['android.app.extra.PROVISIONING_WIFI_PASSWORD'] = $password;
            }
        }

        return $payload;
    }

    /**
     * Render the provisioning JSON as an inline (non base64) SVG QR code string.
     */
    private function renderSvg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::L,
            'svgUseFillAttributes' => false,
            'cssClass' => 'kiosklock-deployment-qr',
        ]);

        return (new QRCode($options))->render($data);
    }

    /**
     * Return the MDM enrollment .mobileconfig used for post-setup iOS enrollment. This is the
     * initial profile a device downloads (e.g. via a deep link / Safari) that points it at the
     * KioskLock server and carries the single-use enrollment token.
     */
    public function enrollIosDeepLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $enrollmentToken = EnrollmentToken::withoutGlobalScopes()
            ->where('token', $request->query('token'))
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->whereNull('used_at')
            ->first();

        if (!$enrollmentToken) {
            return response()->json(['error' => 'Invalid or expired enrollment token'], 401);
        }

        $serverUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $profileUuid = (string) Str::uuid();
        $payloadUuid = (string) Str::uuid();
        $checkInUrl = $serverUrl . '/api/devices/enroll-ios';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadDisplayName</key>
    <string>KioskLock Enrollment</string>
    <key>PayloadIdentifier</key>
    <string>com.kiosklock.enroll</string>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>' . $profileUuid . '</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
    <key>PayloadRemovalDisallowed</key>
    <true/>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>PayloadDisplayName</key>
            <string>KioskLock MDM Enrollment</string>
            <key>PayloadIdentifier</key>
            <string>com.kiosklock.mdm</string>
            <key>PayloadType</key>
            <string>com.apple.mdm</string>
            <key>PayloadUUID</key>
            <string>' . $payloadUuid . '</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
            <key>ServerURL</key>
            <string>' . htmlspecialchars($checkInUrl, ENT_XML1) . '</string>
            <key>CheckInURL</key>
            <string>' . htmlspecialchars($checkInUrl, ENT_XML1) . '</string>
            <key>Topic</key>
            <string>com.apple.mgmt.External.' . $enrollmentToken->org_id . '</string>
            <key>AccessRights</key>
            <integer>8191</integer>
            <key>EnrollmentToken</key>
            <string>' . htmlspecialchars($enrollmentToken->token, ENT_XML1) . '</string>
        </dict>
    </array>
</dict>
</plist>';

        return response($xml, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="kiosklock-enroll.mobileconfig"',
        ]);
    }

    /**
     * Validate a scanned QR enrollment token and (re)assign the bundled/target policy to the
     * device. The token is consumed atomically so a single QR can never re-provision two
     * devices under a race.
     */
    public function reassignPolicy(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'policy_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $id) {
                // Lock the token row for the duration of the transaction so concurrent
                // scans of the same token serialize and only the first wins.
                $enrollmentToken = EnrollmentToken::withoutGlobalScopes()
                    ->where('token', $request->input('token'))
                    ->lockForUpdate()
                    ->first();

                if (!$enrollmentToken) {
                    return ['status' => 401, 'body' => ['error' => 'Invalid enrollment token']];
                }

                if ($enrollmentToken->expires_at && $enrollmentToken->expires_at->isPast()) {
                    return ['status' => 401, 'body' => ['error' => 'Enrollment token has expired']];
                }

                if ($enrollmentToken->single_use && $enrollmentToken->used_at !== null) {
                    return ['status' => 409, 'body' => ['error' => 'Enrollment token has already been used']];
                }

                $device = Device::withoutGlobalScopes()->find($id);
                if (!$device) {
                    return ['status' => 404, 'body' => ['error' => 'Device not found']];
                }

                // Cross-tenant guard: the scanned token must belong to the device's org.
                if ((int) $device->org_id !== (int) $enrollmentToken->org_id) {
                    return ['status' => 403, 'body' => ['error' => 'Token does not belong to this device organization']];
                }

                // Prefer the policy bundled with the token, fall back to an explicit request value.
                $policyId = $enrollmentToken->policy_id ?? $request->input('policy_id');
                if (!$policyId) {
                    return ['status' => 422, 'body' => ['error' => 'No policy associated with token or request']];
                }

                $policy = Policy::withoutGlobalScopes()
                    ->where('id', $policyId)
                    ->where('org_id', $enrollmentToken->org_id)
                    ->first();

                if (!$policy) {
                    return ['status' => 404, 'body' => ['error' => 'Policy not found for this organization']];
                }

                if ($policy->status !== 'published') {
                    return ['status' => 422, 'body' => ['error' => 'Cannot assign a draft policy']];
                }

                $assignment = PolicyAssignment::updateOrCreate(
                    ['device_id' => $device->id],
                    [
                        'policy_id' => $policy->id,
                        'assigned_at' => now(),
                        'status' => 'pending',
                    ]
                );

                // enrollment_status transition + token consumption happen together in this
                // transaction so we never mark a token used without reassigning, or vice versa.
                $device->update(['enrollment_status' => 'enrolled']);

                if ($enrollmentToken->single_use) {
                    $enrollmentToken->update(['used_at' => now()]);
                }

                \App\Models\DeviceEvent::create([
                    'device_id' => $device->id,
                    'event_type' => 'policy_reassign',
                    'status' => 'success',
                    'details' => [
                        'policy_id' => $policy->id,
                        'initiated_by' => 'qr_scan',
                    ],
                ]);

                return [
                    'status' => 200,
                    'body' => [
                        'success' => true,
                        'device_id' => $device->id,
                        'policy_id' => $policy->id,
                        'assignment_status' => $assignment->status,
                    ],
                ];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['error' => 'Failed to reassign policy'], 500);
        }

        return response()->json($result['body'], $result['status']);
    }
}
