<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\MdmCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IosMdmTest extends TestCase
{
    use RefreshDatabase;

    private $orgA;
    private $orgB;
    private $adminA;
    private $adminB;
    private $deviceIos;
    private $deviceAndroid;

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

        $this->adminB = Admin::create([
            'org_id' => $this->orgB->id,
            'email' => 'admin.b@example.com',
            'password_hash' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->deviceIos = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-ios-uid',
            'platform' => 'ios',
            'enrollment_status' => 'pending',
        ]);

        $this->deviceAndroid = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-android-uid',
            'platform' => 'android',
            'enrollment_status' => 'pending',
        ]);
    }

    public function test_dynamic_ios_mobileconfig_generation(): void
    {
        $customClaims = [
            'sub' => $this->deviceIos->id,
            'type' => 'device_token',
            'device_uid' => $this->deviceIos->device_uid,
            'org_id' => $this->orgA->id,
            'exp' => now()->addDays(1)->timestamp,
        ];
        $deviceToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::getJWTProvider()->encode($customClaims);

        // 1. Setup policy assignment with restrictions
        $policy = Policy::create([
            'org_id' => $this->orgA->id,
            'name' => 'iOS Strict Kiosk Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'status' => 'published',
            'version' => 1,
            'restrictions' => [
                'block_factory_reset' => true,
                'block_settings' => true,
                'block_airdrop' => true,
                'block_airplay' => false,
            ]
        ]);
        $policy->update(['group_id' => $policy->id]);

        PolicyAssignment::create([
            'policy_id' => $policy->id,
            'device_id' => $this->deviceIos->id,
            'status' => 'pending',
        ]);

        // 2. Fetch the profile
        $response = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->get("/api/devices/{$this->deviceIos->id}/mdm/profile");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/x-apple-aspen-config');
        
        $xmlContent = $response->getContent();
        $this->assertStringContainsString('<key>allowSettings</key>', $xmlContent);
        $this->assertStringContainsString('<key>allowEraseContentAndSettings</key>', $xmlContent);
        
        // Assert true/false mapping values based on policy
        $this->assertStringContainsString('<key>allowSettings</key>' . "\n" . '            <false/>', $xmlContent);
        $this->assertStringContainsString('<key>allowEraseContentAndSettings</key>' . "\n" . '            <false/>', $xmlContent);
        $this->assertStringContainsString('<key>allowAirDrop</key>' . "\n" . '            <false/>', $xmlContent);
        $this->assertStringContainsString('<key>allowAirPlay</key>' . "\n" . '            <true/>', $xmlContent);
    }

    public function test_admin_remote_unlock_queues_command_and_logs(): void
    {
        auth('api')->login($this->adminA);

        $response = $this->postJson("/api/admin/devices/{$this->deviceIos->id}/unlock");
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Check command enqueued
        $this->assertDatabaseHas('mdm_commands', [
            'device_id' => $this->deviceIos->id,
            'command_type' => 'RemoveProfile',
            'status' => 'pending',
        ]);

        // Check event log logged
        $this->assertDatabaseHas('device_events', [
            'device_id' => $this->deviceIos->id,
            'event_type' => 'mdm_command',
            'status' => 'sent',
        ]);
    }

    public function test_device_polls_and_acknowledges_commands(): void
    {
        $customClaims = [
            'sub' => $this->deviceIos->id,
            'type' => 'device_token',
            'device_uid' => $this->deviceIos->device_uid,
            'org_id' => $this->orgA->id,
            'exp' => now()->addDays(1)->timestamp,
        ];
        $deviceToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::getJWTProvider()->encode($customClaims);

        // Enqueue command
        $command = MdmCommand::create([
            'device_id' => $this->deviceIos->id,
            'command_type' => 'RemoveProfile',
            'status' => 'pending',
        ]);

        // 1. Device retrieves pending commands
        $responseGet = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->getJson("/api/devices/{$this->deviceIos->id}/mdm/commands");

        $responseGet->assertStatus(200);
        $this->assertCount(1, $responseGet->json('commands'));
        $this->assertEquals('RemoveProfile', $responseGet->json('commands.0.command_type'));

        // 2. Device acknowledges command execution
        $responseAck = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->postJson("/api/devices/{$this->deviceIos->id}/mdm/commands/{$command->id}/ack");

        $responseAck->assertStatus(200);
        $responseAck->assertJson(['success' => true]);

        // Assert command marked as acknowledged in DB
        $this->assertDatabaseHas('mdm_commands', [
            'id' => $command->id,
            'status' => 'acknowledged',
        ]);

        // Assert event log created
        $this->assertDatabaseHas('device_events', [
            'device_id' => $this->deviceIos->id,
            'event_type' => 'mdm_command',
            'status' => 'acknowledged',
        ]);
    }

    public function test_mdm_tenancy_isolation_boundaries(): void
    {
        // Setup tokens
        $customClaimsA = [
            'sub' => $this->deviceIos->id,
            'type' => 'device_token',
            'device_uid' => $this->deviceIos->device_uid,
            'org_id' => $this->orgA->id,
            'exp' => now()->addDays(1)->timestamp,
        ];
        $deviceTokenA = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::getJWTProvider()->encode($customClaimsA);

        // Attempting to request unlock from another admin should fail or be 404
        auth('api')->login($this->adminB);
        $response = $this->postJson("/api/admin/devices/{$this->deviceIos->id}/unlock");
        $response->assertStatus(404);

        // Fetching iOS profile with invalid authorization should fail
        $responseGetNoAuth = $this->get("/api/devices/{$this->deviceIos->id}/mdm/profile");
        $responseGetNoAuth->assertStatus(401);
    }
}
