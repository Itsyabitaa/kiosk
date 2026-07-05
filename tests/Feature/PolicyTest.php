<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private $orgA;
    private $orgB;
    private $adminA;
    private $adminB;
    private $deviceA;
    private $deviceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Organizations
        $this->orgA = Organization::create(['name' => 'Org A', 'plan_tier' => 'pro']);
        $this->orgB = Organization::create(['name' => 'Org B', 'plan_tier' => 'free']);

        // Create Admins
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

        // Create Devices
        $this->deviceA = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'device_uid' => 'device-uid-a',
            'platform' => 'android',
            'enrollment_status' => 'pending',
        ]);

        $this->deviceB = Device::withoutGlobalScopes()->create([
            'org_id' => $this->orgB->id,
            'device_uid' => 'device-uid-b',
            'platform' => 'ios',
            'enrollment_status' => 'pending',
        ]);
    }

    /**
     * Test policy CRUD: Create, update draft, publish, edit published to version.
     */
    public function test_policy_crud_and_versioning(): void
    {
        auth('api')->login($this->adminA);

        // 1. Create a policy (starts as draft, version 1)
        $response = $this->postJson('/api/admin/policies', [
            'name' => 'Kiosk App Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'restrictions' => ['block_notifications' => true],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name' => 'Kiosk App Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'restrictions' => ['block_notifications' => true],
            'version' => 1,
            'status' => 'draft',
        ]);

        $policyId = $response->json('id');
        $this->assertDatabaseHas('policies', ['id' => $policyId, 'status' => 'draft', 'version' => 1]);

        // 2. Update the draft in place (should NOT create a new version row)
        $responseUpdateDraft = $this->putJson("/api/admin/policies/{$policyId}", [
            'name' => 'Updated Kiosk App Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk.updated',
            'restrictions' => ['block_notifications' => true, 'block_recents' => true],
        ]);

        $responseUpdateDraft->assertStatus(200);
        $responseUpdateDraft->assertJson([
            'id' => $policyId,
            'name' => 'Updated Kiosk App Policy',
            'target' => 'com.example.kiosk.updated',
            'version' => 1,
            'status' => 'draft',
        ]);

        // Ensure there is only 1 policy in DB so far
        $this->assertEquals(1, Policy::withoutGlobalScopes()->count());

        // 3. Publish the policy
        $responsePublish = $this->patchJson("/api/admin/policies/{$policyId}/publish");
        $responsePublish->assertStatus(200);
        $responsePublish->assertJson(['status' => 'published']);

        $this->assertDatabaseHas('policies', ['id' => $policyId, 'status' => 'published']);

        // 4. Edit the published policy (should create a NEW version row, v2 in draft status)
        $responseEditPublished = $this->putJson("/api/admin/policies/{$policyId}", [
            'name' => 'Kiosk App Policy v2',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk.v2',
            'restrictions' => ['block_notifications' => false],
        ]);

        $responseEditPublished->assertStatus(201);
        $responseEditPublished->assertJson([
            'version' => 2,
            'status' => 'draft',
            'name' => 'Kiosk App Policy v2',
            'target' => 'com.example.kiosk.v2',
        ]);

        $newVersionId = $responseEditPublished->json('id');
        $this->assertNotEquals($policyId, $newVersionId);

        // Ensure both versions exist in DB
        $this->assertEquals(2, Policy::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('policies', ['id' => $policyId, 'version' => 1, 'status' => 'published']);
        $this->assertDatabaseHas('policies', ['id' => $newVersionId, 'version' => 2, 'status' => 'draft']);
    }

    /**
     * Test tenancy isolation on policies.
     */
    public function test_policies_tenancy_isolation(): void
    {
        // Create policy as Admin A
        auth('api')->login($this->adminA);
        $policyA = Policy::create([
            'org_id' => $this->orgA->id,
            'name' => 'Org A Policy',
            'policy_type' => 'url_whitelist',
            'status' => 'draft',
            'version' => 1,
        ]);
        $policyA->update(['group_id' => $policyA->id]);

        // Login as Admin B
        auth('api')->login($this->adminB);

        // Attempting to see Org A's policy should fail or not list it
        $responseList = $this->getJson('/api/admin/policies');
        $responseList->assertStatus(200);
        $responseList->assertJsonMissing(['name' => 'Org A Policy']);

        // Attempting to update or publish Org A's policy should return 404
        $responseUpdate = $this->putJson("/api/admin/policies/{$policyA->id}", [
            'name' => 'Hacked',
            'policy_type' => 'url_whitelist',
        ]);
        $responseUpdate->assertStatus(404);

        $responsePublish = $this->patchJson("/api/admin/policies/{$policyA->id}/publish");
        $responsePublish->assertStatus(404);
    }

    /**
     * Test policy assignments: success and failure cases.
     */
    public function test_policy_assignments(): void
    {
        auth('api')->login($this->adminA);

        // Create draft policy
        $policyDraft = Policy::create([
            'org_id' => $this->orgA->id,
            'name' => 'Draft Policy',
            'policy_type' => 'single_app',
            'status' => 'draft',
            'version' => 1,
        ]);

        // Attempt to assign draft (should fail 400)
        $responseAssignDraft = $this->postJson("/api/admin/policies/{$policyDraft->id}/assign", [
            'device_id' => $this->deviceA->id,
        ]);
        $responseAssignDraft->assertStatus(400);

        // Publish the policy
        $policyDraft->update(['status' => 'published']);

        // Assign published policy to Org A's device (success)
        $responseAssignSuccess = $this->postJson("/api/admin/policies/{$policyDraft->id}/assign", [
            'device_id' => $this->deviceA->id,
        ]);
        $responseAssignSuccess->assertStatus(200);
        $responseAssignSuccess->assertJson([
            'policy_id' => $policyDraft->id,
            'device_id' => $this->deviceA->id,
            'status' => 'pending',
        ]);

        // Attempt to assign to a device from another organization (should fail 404)
        $responseAssignCrossOrg = $this->postJson("/api/admin/policies/{$policyDraft->id}/assign", [
            'device_id' => $this->deviceB->id,
        ]);
        $responseAssignCrossOrg->assertStatus(404);
    }

    /**
     * Test device policy sync and acknowledgment endpoints.
     */
    public function test_device_policy_sync_and_ack(): void
    {
        // 1. Create a device token (JWT) for Device A using custom claims
        $customClaims = [
            'sub' => $this->deviceA->id,
            'type' => 'device_token',
            'device_uid' => $this->deviceA->device_uid,
            'org_id' => $this->orgA->id,
            'exp' => now()->addDays(1)->timestamp,
        ];
        $deviceToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::getJWTProvider()->encode($customClaims);

        // 2. Fetch policy (none assigned yet)
        $response = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->getJson("/api/devices/{$this->deviceA->id}/policy");

        $response->assertStatus(200);
        $response->assertJson(['policy' => null]);

        // 3. Create and assign a policy
        $policy = Policy::create([
            'org_id' => $this->orgA->id,
            'name' => 'Kiosk Mode Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'status' => 'published',
            'version' => 1,
        ]);
        $policy->update(['group_id' => $policy->id]);

        PolicyAssignment::create([
            'policy_id' => $policy->id,
            'device_id' => $this->deviceA->id,
            'status' => 'pending',
        ]);

        // 4. Fetch policy (should return the assigned policy now)
        $response = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->getJson("/api/devices/{$this->deviceA->id}/policy");

        $response->assertStatus(200);
        $response->assertJson([
            'policy' => [
                'id' => $policy->id,
                'name' => 'Kiosk Mode Policy',
                'policy_type' => 'single_app',
                'target' => 'com.example.kiosk',
            ]
        ]);

        // 5. Acknowledge the policy
        $responseAck = $this->withHeaders([
            'Authorization' => "Bearer $deviceToken",
        ])->postJson("/api/devices/{$this->deviceA->id}/policy-ack", [
            'policy_id' => $policy->id,
            'status' => 'applied',
        ]);

        $responseAck->assertStatus(200);
        $responseAck->assertJson(['success' => true]);

        // Ensure status in database is updated
        $this->assertDatabaseHas('policy_assignments', [
            'policy_id' => $policy->id,
            'device_id' => $this->deviceA->id,
            'status' => 'applied',
        ]);
    }
}
