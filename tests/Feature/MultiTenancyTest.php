<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that two organizations' devices cannot see each other via the devices API.
     */
    public function test_devices_multi_tenancy(): void
    {
        // 1. Create two Organizations
        $orgA = Organization::create([
            'name' => 'Org A',
            'plan_tier' => 'pro',
        ]);

        $orgB = Organization::create([
            'name' => 'Org B',
            'plan_tier' => 'free',
        ]);

        // 2. Create an Admin for each Org
        $adminA = Admin::create([
            'org_id' => $orgA->id,
            'email' => 'admin.a@example.com',
            'password_hash' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $adminB = Admin::create([
            'org_id' => $orgB->id,
            'email' => 'admin.b@example.com',
            'password_hash' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        // 3. Create a Device for each Org
        $deviceA = Device::withoutGlobalScopes()->create([
            'org_id' => $orgA->id,
            'device_uid' => 'device-uid-a',
            'hardware_fingerprint' => 'fingerprint-a',
            'platform' => 'android',
            'enrollment_status' => 'pending',
        ]);

        $deviceB = Device::withoutGlobalScopes()->create([
            'org_id' => $orgB->id,
            'device_uid' => 'device-uid-b',
            'hardware_fingerprint' => 'fingerprint-b',
            'platform' => 'ios',
            'enrollment_status' => 'pending',
        ]);

        // 4. Authenticate as Admin A
        $tokenA = JWTAuth::fromUser($adminA);

        // 5. Get devices as Admin A
        $responseA = $this->withHeaders([
            'Authorization' => "Bearer $tokenA",
        ])->getJson('/api/admin/devices');

        $responseA->assertStatus(200);
        $responseA->assertJsonFragment(['device_uid' => 'device-uid-a']);
        $responseA->assertJsonMissing(['device_uid' => 'device-uid-b']);

        // 6. Authenticate as Admin B
        $tokenB = JWTAuth::fromUser($adminB);

        // 7. Get devices as Admin B
        $responseB = $this->withHeaders([
            'Authorization' => "Bearer $tokenB",
        ])->getJson('/api/admin/devices');

        $responseB->assertStatus(200);
        $responseB->assertJsonFragment(['device_uid' => 'device-uid-b']);
        $responseB->assertJsonMissing(['device_uid' => 'device-uid-a']);
    }
}
