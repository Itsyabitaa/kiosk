<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Device;
use App\Models\EnrollmentToken;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private $orgA;
    private $orgB;
    private $adminA;
    private $publishedPolicyA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::create(['name' => 'Org A', 'plan_tier' => 'pro']);
        $this->orgB = Organization::create(['name' => 'Org B', 'plan_tier' => 'free']);

        $this->adminA = Admin::create([
            'org_id' => $this->orgA->id,
            'email' => 'admin.a@example.com',
            'password_hash' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->publishedPolicyA = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'name' => 'Kiosk Single App',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'status' => 'published',
            'version' => 1,
        ]);
        $this->publishedPolicyA->update(['group_id' => $this->publishedPolicyA->id]);
    }

    // ---------------------------------------------------------------------
    // 1. Android Enterprise extras bundle formatting
    // ---------------------------------------------------------------------

    public function test_deployment_qr_contains_android_enterprise_extras_bundle(): void
    {
        auth('api')->login($this->adminA);

        $response = $this->postJson('/api/admin/devices/deployment-qr', [
            'policy_id' => $this->publishedPolicyA->id,
            'wifi_ssid' => 'KioskNet',
            'wifi_password' => 'sup3rsecret',
            'wifi_security_type' => 'WPA',
            'expires_in_hours' => 24,
        ]);

        $response->assertStatus(200);

        // Android Enterprise mandated provisioning keys.
        $payload = $response->json('payload');
        $this->assertArrayHasKey('android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME', $payload);
        $this->assertArrayHasKey('android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE', $payload);
        $this->assertArrayHasKey('android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION', $payload);

        $this->assertSame(
            'com.kiosklock.kiosklock_agent/com.kiosklock.kiosklock_agent.KioskDeviceAdminReceiver',
            $payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME']
        );

        // WiFi provisioning fields bundled from the request.
        $this->assertSame('KioskNet', $payload['android.app.extra.PROVISIONING_WIFI_SSID']);
        $this->assertSame('WPA', $payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE']);
        $this->assertSame('sup3rsecret', $payload['android.app.extra.PROVISIONING_WIFI_PASSWORD']);

        // The nested admin extras bundle must carry the enrollment token + policy id.
        $extras = $payload['android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE'];
        $this->assertArrayHasKey('enrollment_token', $extras);
        $this->assertSame((string) $this->publishedPolicyA->id, $extras['policy_id']);
        $this->assertSame((string) $this->orgA->id, $extras['org_id']);
        $this->assertArrayHasKey('server_url', $extras);

        // The token in the payload must actually exist and be single-use + time-boxed.
        $token = $extras['enrollment_token'];
        $this->assertDatabaseHas('enrollment_tokens', [
            'token' => $token,
            'org_id' => $this->orgA->id,
            'policy_id' => $this->publishedPolicyA->id,
            'single_use' => true,
        ]);
        $this->assertNotNull($response->json('expires_at'));

        // A rendered SVG QR must be returned.
        $this->assertStringContainsString('<svg', $response->json('qr_svg'));
    }

    public function test_deployment_qr_rejects_draft_policy(): void
    {
        auth('api')->login($this->adminA);

        $draft = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'name' => 'Draft Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.draft',
            'status' => 'draft',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/admin/devices/deployment-qr', [
            'policy_id' => $draft->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_deployment_qr_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/devices/deployment-qr', []);
        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // 2. Single-use + expiration + race-condition guards
    // ---------------------------------------------------------------------

    public function test_single_use_token_cannot_be_redeemed_twice(): void
    {
        $token = EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'token' => 'single-use-token',
            'single_use' => true,
        ]);

        $first = $this->postJson('/api/enroll', [
            'hardware_fingerprint' => 'fp-device-1',
            'platform' => 'android',
            'enrollment_token' => 'single-use-token',
        ]);
        $first->assertStatus(200);

        $this->assertDatabaseHas('enrollment_tokens', [
            'id' => $token->id,
        ]);
        $this->assertNotNull(EnrollmentToken::withoutGlobalScopes()->find($token->id)->used_at);

        // A second, different device attempting to reuse the consumed token must fail.
        $second = $this->postJson('/api/enroll', [
            'hardware_fingerprint' => 'fp-device-2',
            'platform' => 'android',
            'enrollment_token' => 'single-use-token',
        ]);
        $second->assertStatus(401);

        // Only the first device should have been created.
        $this->assertSame(1, Device::withoutGlobalScopes()->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'token' => 'expired-token',
            'single_use' => true,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->postJson('/api/enroll', [
            'hardware_fingerprint' => 'fp-expired',
            'platform' => 'android',
            'enrollment_token' => 'expired-token',
        ]);

        $response->assertStatus(401);
        $this->assertSame(0, Device::withoutGlobalScopes()->count());
    }

    public function test_reassign_token_consumed_once_under_repeated_attempts(): void
    {
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-reassign-uid',
            'platform' => 'android',
            'enrollment_status' => 'enrolled',
        ]);

        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'policy_id' => $this->publishedPolicyA->id,
            'token' => 'reassign-token',
            'single_use' => true,
        ]);

        // Simulate repeated scans of the same QR (serialized). Only one may consume the token.
        $successCount = 0;
        $conflictCount = 0;

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson("/api/devices/{$device->id}/reassign", [
                'token' => 'reassign-token',
            ]);

            if ($response->status() === 200) {
                $successCount++;
            } elseif ($response->status() === 409) {
                $conflictCount++;
            }
        }

        $this->assertSame(1, $successCount);
        $this->assertSame(2, $conflictCount);

        // The policy was assigned exactly once.
        $this->assertSame(1, PolicyAssignment::where('device_id', $device->id)->count());
        $this->assertDatabaseHas('policy_assignments', [
            'device_id' => $device->id,
            'policy_id' => $this->publishedPolicyA->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // 3. Reassign endpoint validation
    // ---------------------------------------------------------------------

    public function test_reassign_requires_token(): void
    {
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-no-token',
            'platform' => 'android',
            'enrollment_status' => 'enrolled',
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/reassign", []);
        $response->assertStatus(422);
    }

    public function test_reassign_rejects_invalid_token(): void
    {
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-bad-token',
            'platform' => 'android',
            'enrollment_status' => 'enrolled',
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/reassign", [
            'token' => 'does-not-exist',
        ]);
        $response->assertStatus(401);
    }

    public function test_reassign_rejects_cross_tenant_token(): void
    {
        // Device belongs to org A, token belongs to org B.
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-cross-tenant',
            'platform' => 'android',
            'enrollment_status' => 'enrolled',
        ]);

        $policyB = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgB->id,
            'name' => 'Org B Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.b',
            'status' => 'published',
            'version' => 1,
        ]);

        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgB->id,
            'policy_id' => $policyB->id,
            'token' => 'org-b-token',
            'single_use' => true,
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/reassign", [
            'token' => 'org-b-token',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, PolicyAssignment::where('device_id', $device->id)->count());
    }

    public function test_reassign_rejects_draft_policy(): void
    {
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-draft-policy',
            'platform' => 'android',
            'enrollment_status' => 'enrolled',
        ]);

        $draft = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'name' => 'Draft',
            'policy_type' => 'single_app',
            'target' => 'com.example.draft',
            'status' => 'draft',
            'version' => 1,
        ]);

        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'policy_id' => $draft->id,
            'token' => 'draft-policy-token',
            'single_use' => true,
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/reassign", [
            'token' => 'draft-policy-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_reassign_success_assigns_policy_and_logs_event(): void
    {
        $device = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-reassign-success',
            'platform' => 'android',
            'enrollment_status' => 'pending',
        ]);

        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'policy_id' => $this->publishedPolicyA->id,
            'token' => 'good-reassign-token',
            'single_use' => true,
        ]);

        $response = $this->postJson("/api/devices/{$device->id}/reassign", [
            'token' => 'good-reassign-token',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'device_id' => $device->id,
            'policy_id' => $this->publishedPolicyA->id,
        ]);

        $this->assertDatabaseHas('policy_assignments', [
            'device_id' => $device->id,
            'policy_id' => $this->publishedPolicyA->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'enrollment_status' => 'enrolled',
        ]);

        $this->assertDatabaseHas('device_events', [
            'device_id' => $device->id,
            'event_type' => 'policy_reassign',
            'status' => 'success',
        ]);

        $this->assertNotNull(
            EnrollmentToken::withoutGlobalScopes()->where('token', 'good-reassign-token')->first()->used_at
        );
    }

    // ---------------------------------------------------------------------
    // iOS enrollment deep link
    // ---------------------------------------------------------------------

    public function test_ios_enroll_deep_link_returns_mobileconfig(): void
    {
        EnrollmentToken::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'token' => 'ios-enroll-token',
            'single_use' => true,
        ]);

        $response = $this->get('/api/devices/enroll-ios?token=ios-enroll-token');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/x-apple-aspen-config');

        $xml = $response->getContent();
        $this->assertStringContainsString('<key>PayloadType</key>', $xml);
        $this->assertStringContainsString('com.apple.mdm', $xml);
        $this->assertStringContainsString('ios-enroll-token', $xml);
    }

    public function test_ios_enroll_deep_link_rejects_invalid_token(): void
    {
        $response = $this->get('/api/devices/enroll-ios?token=nope');
        $response->assertStatus(401);
    }
}
