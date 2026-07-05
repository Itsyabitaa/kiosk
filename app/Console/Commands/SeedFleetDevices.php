<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Policy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a large simulated fleet for load testing the dashboard device-list endpoint.
 *
 *   php artisan fleet:seed-devices 1000 --org=1
 *
 * Bulk-inserts devices + a telemetry snapshot each, and assigns a published policy to a
 * random subset so compliance/telemetry columns are populated.
 */
class SeedFleetDevices extends Command
{
    protected $signature = 'fleet:seed-devices {count=1000 : Number of devices to create} {--org= : Organization id (created if omitted)}';

    protected $description = 'Seed simulated devices for load testing the fleet dashboard';

    public function handle(): int
    {
        $count = (int) $this->argument('count');

        $orgId = $this->option('org');
        if (!$orgId) {
            $org = Organization::create(['name' => 'Load Test Org ' . Str::random(4), 'plan_tier' => 'pro']);
            $orgId = $org->id;
            $this->info("Created organization #{$orgId}");
        }

        $policy = Policy::withoutGlobalScopes()->where('org_id', $orgId)->where('status', 'published')->first();
        if (!$policy) {
            $policy = Policy::withoutGlobalScopes()->create([
                'org_id' => $orgId,
                'name' => 'Load Test Kiosk Policy',
                'policy_type' => 'single_app',
                'target' => 'com.example.kiosk',
                'status' => 'published',
                'version' => 1,
            ]);
            $policy->update(['group_id' => $policy->id]);
        }

        $now = Carbon::now();
        $platforms = ['android', 'ios'];
        $created = 0;
        $bar = $this->output->createProgressBar($count);

        collect(range(1, $count))->chunk(500)->each(function ($chunk) use ($orgId, $now, $platforms, $policy, &$created, $bar) {
            $devices = [];
            foreach ($chunk as $i) {
                $devices[] = [
                    'org_id' => $orgId,
                    'device_uid' => (string) Str::uuid(),
                    'hardware_fingerprint' => 'seed-' . Str::random(16),
                    'platform' => $platforms[array_rand($platforms)],
                    'enrollment_status' => 'enrolled',
                    // Spread last_seen so ~10% look offline for realistic dashboard stats.
                    'last_seen_at' => $now->copy()->subMinutes(random_int(0, 120)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('devices')->insert($devices);

            $ids = DB::table('devices')
                ->where('org_id', $orgId)
                ->orderByDesc('id')
                ->limit(count($devices))
                ->pluck('id')
                ->all();

            $assignments = [];
            $telemetry = [];
            foreach ($ids as $id) {
                // Assign the policy to ~70% of devices.
                if (random_int(1, 10) <= 7) {
                    $assignments[] = [
                        'device_id' => $id,
                        'policy_id' => $policy->id,
                        'assigned_at' => $now,
                        'status' => 'applied',
                        'applied_version' => random_int(1, 10) <= 9 ? $policy->version : $policy->version - 1,
                    ];
                }

                $telemetry[] = [
                    'device_id' => $id,
                    'battery_level' => random_int(10, 100),
                    'connectivity_type' => random_int(0, 1) ? 'wifi' : 'mobile',
                    'signal_strength' => random_int(-100, -40),
                    'app_version' => '1.0.0+1',
                    'os_version' => 'Android 14',
                    'recorded_at' => $now->copy()->subMinutes(random_int(0, 30)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($assignments) {
                // applied_version may dip below 1; clamp.
                foreach ($assignments as &$a) {
                    $a['applied_version'] = max(1, (int) $a['applied_version']);
                }
                unset($a);
                DB::table('policy_assignments')->insert($assignments);
            }
            DB::table('device_telemetry')->insert($telemetry);

            $created += count($devices);
            $bar->advance(count($devices));
        });

        $bar->finish();
        $this->newLine();
        $this->info("Seeded {$created} devices into org #{$orgId}.");

        return self::SUCCESS;
    }
}
