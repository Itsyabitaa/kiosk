<?php

namespace App\Console\Commands;

use App\Events\AlertRaised;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceTelemetry;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Periodic fleet-health scan. Raises alerts for devices that have gone offline (no check-in
 * within the threshold) or stopped reporting telemetry. De-duplicates against existing open
 * alerts so a persistently offline device doesn't spam the feed.
 */
class ScanFleetHealth extends Command
{
    protected $signature = 'fleet:scan-health';

    protected $description = 'Scan the fleet and raise offline / telemetry-stopped alerts';

    public function handle(): int
    {
        $offlineThreshold = Carbon::now()->subMinutes((int) env('FLEET_OFFLINE_THRESHOLD_MINUTES', 15));
        $telemetryThreshold = Carbon::now()->subMinutes((int) env('FLEET_TELEMETRY_STALE_MINUTES', 60));

        $raised = 0;

        Device::withoutGlobalScopes()
            ->where('enrollment_status', 'enrolled')
            ->chunkById(500, function ($devices) use ($offlineThreshold, $telemetryThreshold, &$raised) {
                foreach ($devices as $device) {
                    // Offline: never checked in, or last check-in older than threshold.
                    if ($device->last_seen_at === null || $device->last_seen_at < $offlineThreshold) {
                        $raised += $this->raiseIfAbsent(
                            $device,
                            Alert::TYPE_OFFLINE,
                            'warning',
                            'Device has not checked in since ' . ($device->last_seen_at ?? 'enrollment')
                        );
                        // If it's offline we don't also flag telemetry_stopped.
                        continue;
                    }

                    $lastTelemetry = DeviceTelemetry::where('device_id', $device->id)
                        ->orderByDesc('recorded_at')
                        ->value('recorded_at');

                    if ($lastTelemetry !== null && $lastTelemetry < $telemetryThreshold) {
                        $raised += $this->raiseIfAbsent(
                            $device,
                            Alert::TYPE_TELEMETRY_STOPPED,
                            'warning',
                            'Device stopped reporting telemetry at ' . $lastTelemetry
                        );
                    }
                }
            });

        $this->info("Fleet health scan complete. Alerts raised: {$raised}");

        return self::SUCCESS;
    }

    private function raiseIfAbsent(Device $device, string $type, string $severity, string $message): int
    {
        $exists = Alert::withoutGlobalScopes()
            ->where('device_id', $device->id)
            ->where('type', $type)
            ->where('status', Alert::STATUS_OPEN)
            ->exists();

        if ($exists) {
            return 0;
        }

        $alert = Alert::create([
            'org_id' => $device->org_id,
            'device_id' => $device->id,
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'status' => Alert::STATUS_OPEN,
        ]);

        broadcast(new AlertRaised($alert));

        return 1;
    }
}
