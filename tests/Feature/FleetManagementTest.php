<?php

namespace Tests\Feature;

use App\Events\AlertRaised;
use App\Events\DeviceCommandIssued;
use App\Events\DeviceDeliveryConfirmed;
use App\Models\Admin;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceTelemetry;
use App\Models\MdmCommand;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\PolicyRollout;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class FleetManagementTest extends TestCase
{
    use RefreshDatabase;

    private $orgA;
    private $orgB;
    private $adminA;
    private $policyA;

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

        $this->policyA = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id,
            'name' => 'Kiosk Policy',
            'policy_type' => 'single_app',
            'target' => 'com.example.kiosk',
            'status' => 'published',
            'version' => 3,
        ]);
        $this->policyA->update(['group_id' => $this->policyA->id]);
    }

    private function makeDevices(int $count, ?int $orgId = null, array $attrs = []): array
    {
        $orgId ??= $this->orgA->id;
        $devices = [];
        for ($i = 0; $i < $count; $i++) {
            $devices[] = Device::withoutGlobalScopes()->create(array_merge([
                'org_id' => $orgId,
                'device_uid' => 'dev-' . $orgId . '-' . uniqid('', true),
                'platform' => 'android',
                'enrollment_status' => 'enrolled',
                'last_seen_at' => Carbon::now(),
            ], $attrs));
        }

        return $devices;
    }

    private function deviceToken(Device $device): string
    {
        return JWTAuth::getJWTProvider()->encode([
            'sub' => $device->id,
            'type' => 'device_token',
            'device_uid' => $device->device_uid,
            'org_id' => $device->org_id,
            'exp' => now()->addDay()->timestamp,
        ]);
    }

    // ---------------------------------------------------------------- Grouping

    public function test_group_crud_and_membership(): void
    {
        auth('api')->login($this->adminA);
        $devices = $this->makeDevices(3);

        $create = $this->postJson('/api/admin/groups', ['name' => 'Lobby Kiosks']);
        $create->assertStatus(201);
        $groupId = $create->json('id');

        $add = $this->postJson("/api/admin/groups/{$groupId}/members", [
            'device_ids' => array_map(fn ($d) => $d->id, $devices),
        ]);
        $add->assertStatus(200)->assertJson(['added' => 3, 'member_count' => 3]);

        $this->getJson("/api/admin/groups/{$groupId}")->assertStatus(200);

        $remove = $this->deleteJson("/api/admin/groups/{$groupId}/members", [
            'device_ids' => [$devices[0]->id],
        ]);
        $remove->assertStatus(200)->assertJson(['member_count' => 2]);
    }

    public function test_group_membership_excludes_cross_tenant_devices(): void
    {
        auth('api')->login($this->adminA);
        $foreign = $this->makeDevices(1, $this->orgB->id)[0];

        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'G']);
        $add = $this->postJson("/api/admin/groups/{$group->id}/members", [
            'device_ids' => [$foreign->id],
        ]);

        $add->assertStatus(200)->assertJson(['added' => 0]);
    }

    public function test_bulk_group_policy_assignment_pushes_commands(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        auth('api')->login($this->adminA);

        $devices = $this->makeDevices(3);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        $response = $this->postJson("/api/admin/groups/{$group->id}/assign-policy", [
            'policy_id' => $this->policyA->id,
        ]);

        $response->assertStatus(200)->assertJson(['devices_targeted' => 3]);

        foreach ($devices as $d) {
            $this->assertDatabaseHas('policy_assignments', [
                'device_id' => $d->id,
                'policy_id' => $this->policyA->id,
            ]);
        }

        $this->assertEquals(3, MdmCommand::where('command_type', MdmCommand::TYPE_POLICY_UPDATE)->count());
        Event::assertDispatchedTimes(DeviceCommandIssued::class, 3);
    }

    public function test_bulk_assign_rejects_draft_policy(): void
    {
        auth('api')->login($this->adminA);
        $draft = Policy::withoutGlobalScopes()->create([
            'org_id' => $this->orgA->id, 'name' => 'Draft', 'policy_type' => 'single_app',
            'target' => 'com.x', 'status' => 'draft', 'version' => 1,
        ]);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'G']);

        $this->postJson("/api/admin/groups/{$group->id}/assign-policy", ['policy_id' => $draft->id])
            ->assertStatus(422);
    }

    public function test_device_tags_add_and_remove(): void
    {
        auth('api')->login($this->adminA);
        $device = $this->makeDevices(1)[0];

        $this->postJson("/api/admin/devices/{$device->id}/tags", ['tag' => 'lobby'])->assertStatus(201);
        $this->assertDatabaseHas('device_tags', ['device_id' => $device->id, 'tag' => 'lobby']);

        $this->deleteJson("/api/admin/devices/{$device->id}/tags/lobby")->assertStatus(200);
        $this->assertDatabaseMissing('device_tags', ['device_id' => $device->id, 'tag' => 'lobby']);
    }

    // ---------------------------------------------------------- Real-time commands

    public function test_issue_command_to_device_broadcasts(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        auth('api')->login($this->adminA);
        $device = $this->makeDevices(1)[0];

        $response = $this->postJson("/api/admin/devices/{$device->id}/commands", [
            'command_type' => MdmCommand::TYPE_LOCK,
        ]);

        $response->assertStatus(201)->assertJson(['command_type' => 'lock_command']);
        $this->assertDatabaseHas('mdm_commands', [
            'device_id' => $device->id,
            'command_type' => 'lock_command',
            'status' => 'pending',
        ]);
        Event::assertDispatched(DeviceCommandIssued::class);
    }

    public function test_command_validation_rejects_unknown_type(): void
    {
        auth('api')->login($this->adminA);
        $device = $this->makeDevices(1)[0];

        $this->postJson("/api/admin/devices/{$device->id}/commands", ['command_type' => 'explode'])
            ->assertStatus(422);
    }

    public function test_device_ack_confirms_delivery(): void
    {
        Event::fake([DeviceDeliveryConfirmed::class]);
        $device = $this->makeDevices(1)[0];
        $command = MdmCommand::create([
            'device_id' => $device->id,
            'command_type' => MdmCommand::TYPE_LOCK,
            'status' => 'pending',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
            ->postJson("/api/devices/{$device->id}/mdm/commands/{$command->id}/ack");

        $response->assertStatus(200);
        $command->refresh();
        $this->assertEquals('acknowledged', $command->status);
        $this->assertNotNull($command->acked_at);
        Event::assertDispatched(DeviceDeliveryConfirmed::class);
    }

    public function test_broadcasting_auth_authorizes_device_and_admin(): void
    {
        $device = $this->makeDevices(1)[0];

        // Device may subscribe to its own channel.
        $ok = $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-device.' . $device->id,
            ]);
        $ok->assertStatus(200);
        $this->assertStringContainsString(':', $ok->json('auth'));

        // Device may NOT subscribe to another device's channel.
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-device.999999',
            ])->assertStatus(403);

        // Admin may subscribe to their org channel.
        $adminToken = JWTAuth::fromUser($this->adminA);
        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-org.' . $this->orgA->id,
            ])->assertStatus(200);
    }

    // ---------------------------------------------------------------- Rollouts

    public function test_staged_rollout_partial_then_complete(): void
    {
        Event::fake([DeviceCommandIssued::class, DeviceDeliveryConfirmed::class]);
        auth('api')->login($this->adminA);

        $devices = $this->makeDevices(10);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        $create = $this->postJson('/api/admin/rollouts', [
            'policy_id' => $this->policyA->id,
            'group_id' => $group->id,
            'rollout_percentage' => 50,
        ]);
        $create->assertStatus(201);
        $rolloutId = $create->json('id');

        // 50% of 10 => 5 devices rolled out first.
        $this->assertEquals(5, PolicyAssignment::where('rollout_id', $rolloutId)->count());
        $this->assertEquals('in_progress', $create->json('status'));

        // Pause halts further waves.
        $this->postJson("/api/admin/rollouts/{$rolloutId}/pause")
            ->assertStatus(200)->assertJson(['status' => 'paused']);

        // Resume returns to in_progress.
        $this->postJson("/api/admin/rollouts/{$rolloutId}/resume")
            ->assertStatus(200)->assertJson(['status' => 'in_progress']);

        // Complete pushes to 100%.
        $this->postJson("/api/admin/rollouts/{$rolloutId}/complete")->assertStatus(200);
        $this->assertEquals(10, PolicyAssignment::where('rollout_id', $rolloutId)->count());
    }

    public function test_staged_rollout_rollback_reverts_devices(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        auth('api')->login($this->adminA);

        $devices = $this->makeDevices(4);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        $create = $this->postJson('/api/admin/rollouts', [
            'policy_id' => $this->policyA->id,
            'group_id' => $group->id,
            'rollout_percentage' => 100,
        ]);
        $rolloutId = $create->json('id');
        $this->assertEquals(4, PolicyAssignment::where('rollout_id', $rolloutId)->count());

        $this->postJson("/api/admin/rollouts/{$rolloutId}/rollback")
            ->assertStatus(200)->assertJson(['status' => 'rolled_back']);

        // Devices had no previous policy, so their assignments are cleared on rollback.
        $this->assertEquals(0, PolicyAssignment::where('rollout_id', $rolloutId)->count());
    }

    public function test_scheduled_rollout_is_not_started_until_due(): void
    {
        auth('api')->login($this->adminA);
        $devices = $this->makeDevices(4);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        $create = $this->postJson('/api/admin/rollouts', [
            'policy_id' => $this->policyA->id,
            'group_id' => $group->id,
            'rollout_percentage' => 100,
            'scheduled_at' => Carbon::now()->addHour()->toIso8601String(),
        ]);

        $create->assertStatus(201)->assertJson(['status' => 'scheduled']);
        $this->assertEquals(0, PolicyAssignment::where('rollout_id', $create->json('id'))->count());
    }

    // --------------------------------------------------------------- Telemetry

    public function test_telemetry_batch_ingest_updates_last_seen(): void
    {
        $device = $this->makeDevices(1, $this->orgA->id, ['last_seen_at' => Carbon::now()->subDay()])[0];

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
            ->postJson("/api/devices/{$device->id}/telemetry", [
                'snapshots' => [
                    ['battery_level' => 88, 'connectivity_type' => 'wifi', 'app_version' => '1.0.0+1', 'os_version' => 'Android 14', 'recorded_at' => Carbon::now()->subMinutes(5)->toIso8601String()],
                    ['battery_level' => 84, 'connectivity_type' => 'mobile', 'recorded_at' => Carbon::now()->toIso8601String()],
                ],
            ]);

        $response->assertStatus(201)->assertJson(['ingested' => 2]);
        $this->assertEquals(2, DeviceTelemetry::where('device_id', $device->id)->count());

        $device->refresh();
        $this->assertTrue($device->last_seen_at->greaterThan(Carbon::now()->subHour()));
    }

    public function test_telemetry_rejects_foreign_device_token(): void
    {
        $deviceA = $this->makeDevices(1)[0];
        $deviceB = $this->makeDevices(1)[0];

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($deviceB)])
            ->postJson("/api/devices/{$deviceA->id}/telemetry", ['snapshots' => [['battery_level' => 50]]])
            ->assertStatus(401);
    }

    // ------------------------------------------------------- Analytics + alerts

    public function test_fleet_stats_reports_uptime_and_compliance(): void
    {
        auth('api')->login($this->adminA);

        // 3 online, 1 offline
        $online = $this->makeDevices(3, $this->orgA->id, ['last_seen_at' => Carbon::now()]);
        $offline = $this->makeDevices(1, $this->orgA->id, ['last_seen_at' => Carbon::now()->subHour()])[0];

        // compliant assignment (applied == assigned version 3)
        PolicyAssignment::create([
            'device_id' => $online[0]->id, 'policy_id' => $this->policyA->id,
            'assigned_at' => now(), 'status' => 'applied', 'applied_version' => 3,
        ]);
        // non-compliant (applied 2 != assigned 3)
        PolicyAssignment::create([
            'device_id' => $online[1]->id, 'policy_id' => $this->policyA->id,
            'assigned_at' => now(), 'status' => 'applied', 'applied_version' => 2,
        ]);
        // unknown (never acked)
        PolicyAssignment::create([
            'device_id' => $online[2]->id, 'policy_id' => $this->policyA->id,
            'assigned_at' => now(), 'status' => 'pending', 'applied_version' => null,
        ]);

        $response = $this->getJson('/api/admin/fleet/stats');
        $response->assertStatus(200);
        $response->assertJson([
            'total_devices' => 4,
            'online' => 3,
            'offline' => 1,
            'compliance' => [
                'compliant' => 1,
                'non_compliant' => 1,
                'unknown' => 1,
            ],
        ]);
    }

    public function test_tamper_event_creates_alert(): void
    {
        Event::fake([AlertRaised::class]);
        $device = $this->makeDevices(1)[0];

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
            ->postJson("/api/devices/{$device->id}/events", [
                'event_type' => 'tamper_alert',
                'status' => 'detected',
                'details' => ['reason' => 'usb_debugging'],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('alerts', [
            'device_id' => $device->id,
            'type' => Alert::TYPE_TAMPER,
            'status' => Alert::STATUS_OPEN,
        ]);
        Event::assertDispatched(AlertRaised::class);
    }

    public function test_fleet_health_scan_raises_offline_alert_without_duplicates(): void
    {
        $this->makeDevices(1, $this->orgA->id, ['last_seen_at' => Carbon::now()->subHours(2)]);

        $this->artisan('fleet:scan-health')->assertExitCode(0);
        $this->assertEquals(1, Alert::withoutGlobalScopes()->where('type', Alert::TYPE_OFFLINE)->count());

        // Re-running must not duplicate an already-open alert.
        $this->artisan('fleet:scan-health')->assertExitCode(0);
        $this->assertEquals(1, Alert::withoutGlobalScopes()->where('type', Alert::TYPE_OFFLINE)->count());
    }

    public function test_alert_feed_filter_and_ack(): void
    {
        auth('api')->login($this->adminA);
        $device = $this->makeDevices(1)[0];

        $tamper = Alert::create([
            'org_id' => $this->orgA->id, 'device_id' => $device->id,
            'type' => Alert::TYPE_TAMPER, 'severity' => 'critical', 'message' => 'x', 'status' => 'open',
        ]);
        Alert::create([
            'org_id' => $this->orgA->id, 'device_id' => $device->id,
            'type' => Alert::TYPE_OFFLINE, 'severity' => 'warning', 'message' => 'y', 'status' => 'open',
        ]);

        $this->getJson('/api/admin/alerts')->assertStatus(200)->assertJsonPath('total', 2);
        $this->getJson('/api/admin/alerts?type=tamper')->assertStatus(200)->assertJsonPath('total', 1);

        $this->postJson("/api/admin/alerts/{$tamper->id}/ack")->assertStatus(200)->assertJson(['status' => 'acknowledged']);
    }

    // ---------------------------------------------------- Timeline + exports

    public function test_device_timeline_merges_sources(): void
    {
        auth('api')->login($this->adminA);
        $device = $this->makeDevices(1)[0];

        \App\Models\DeviceEvent::create(['device_id' => $device->id, 'event_type' => 'unlock', 'status' => 'success']);
        Alert::create(['org_id' => $this->orgA->id, 'device_id' => $device->id, 'type' => 'tamper', 'severity' => 'critical', 'message' => 'x', 'status' => 'open']);
        DeviceTelemetry::create(['device_id' => $device->id, 'battery_level' => 90, 'recorded_at' => Carbon::now()]);
        PolicyAssignment::create(['device_id' => $device->id, 'policy_id' => $this->policyA->id, 'assigned_at' => now(), 'status' => 'applied', 'applied_version' => 3]);

        $response = $this->getJson("/api/admin/devices/{$device->id}/timeline");
        $response->assertStatus(200);

        $types = collect($response->json('timeline'))->pluck('type')->unique()->values()->all();
        $this->assertContains('event', $types);
        $this->assertContains('alert', $types);
        $this->assertContains('telemetry', $types);
        $this->assertContains('policy_change', $types);
    }

    public function test_compliance_report_csv_and_pdf(): void
    {
        auth('api')->login($this->adminA);
        $devices = $this->makeDevices(2);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        PolicyAssignment::create(['device_id' => $devices[0]->id, 'policy_id' => $this->policyA->id, 'assigned_at' => now(), 'status' => 'applied', 'applied_version' => 3]);

        $csv = $this->get("/api/admin/groups/{$group->id}/compliance-report?format=csv");
        $csv->assertStatus(200);
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('device_uid', $csv->streamedContent());
        $this->assertStringContainsString('compliant', $csv->streamedContent());

        $pdf = $this->get("/api/admin/groups/{$group->id}/compliance-report?format=pdf");
        $pdf->assertStatus(200);
        $this->assertEquals('application/pdf', $pdf->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------- Performance

    public function test_device_list_is_paginated_and_query_bounded(): void
    {
        auth('api')->login($this->adminA);
        $this->makeDevices(60);

        DB::enableQueryLog();
        $response = $this->getJson('/api/admin/devices?per_page=25');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        $response->assertJsonPath('per_page', 25);
        $this->assertCount(25, $response->json('data'));
        $this->assertEquals(60, $response->json('total'));

        // Eager loading must keep this well below one-query-per-device (N+1 guard).
        $this->assertLessThan(15, $queryCount, "Device list issued {$queryCount} queries (possible N+1).");
    }

    public function test_device_list_filters_by_group(): void
    {
        auth('api')->login($this->adminA);
        $devices = $this->makeDevices(5);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Subset']);
        $group->devices()->sync([$devices[0]->id, $devices[1]->id]);

        $response = $this->getJson("/api/admin/devices?group_id={$group->id}");
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('total'));
    }

    // ------------------------------------- Definition of Done: 50-device push

    public function test_group_push_to_50_devices_with_delivery_confirmation(): void
    {
        Event::fake([DeviceCommandIssued::class, DeviceDeliveryConfirmed::class]);
        auth('api')->login($this->adminA);

        $devices = $this->makeDevices(50);
        $group = DeviceGroup::create(['org_id' => $this->orgA->id, 'name' => 'Big Fleet']);
        $group->devices()->sync(array_map(fn ($d) => $d->id, $devices));

        $push = $this->postJson("/api/admin/groups/{$group->id}/commands", [
            'command_type' => MdmCommand::TYPE_LOCK,
        ]);
        $push->assertStatus(202)->assertJson(['devices_targeted' => 50]);

        // One durable command per device (queue ran synchronously under sync driver).
        $this->assertEquals(50, MdmCommand::where('command_type', MdmCommand::TYPE_LOCK)->count());
        Event::assertDispatchedTimes(DeviceCommandIssued::class, 50);

        // Every device acknowledges -> per-device delivery confirmation.
        foreach ($devices as $device) {
            $command = MdmCommand::where('device_id', $device->id)->first();
            $this->withHeaders(['Authorization' => 'Bearer ' . $this->deviceToken($device)])
                ->postJson("/api/devices/{$device->id}/mdm/commands/{$command->id}/ack")
                ->assertStatus(200);
        }

        $this->assertEquals(50, MdmCommand::where('status', 'acknowledged')->count());
        Event::assertDispatchedTimes(DeviceDeliveryConfirmed::class, 50);
    }
}
