<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceTelemetry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TelemetryController extends Controller
{
    /**
     * Ingest a batch of telemetry snapshots reported by a device. Batched (not per-metric) to
     * keep device network chatter low. Also bumps last_seen_at from the newest snapshot.
     */
    public function store(Request $request, $id)
    {
        if (!$this->validateDeviceToken($id)) {
            return response()->json(['error' => 'Unauthorized device'], 401);
        }

        $validator = Validator::make($request->all(), [
            'snapshots' => 'required|array|min:1',
            'snapshots.*.battery_level' => 'nullable|integer|min:0|max:100',
            'snapshots.*.connectivity_type' => 'nullable|string|max:32',
            'snapshots.*.signal_strength' => 'nullable|integer',
            'snapshots.*.app_version' => 'nullable|string|max:64',
            'snapshots.*.os_version' => 'nullable|string|max:128',
            'snapshots.*.recorded_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $device = Device::withoutGlobalScopes()->findOrFail($id);
        $now = Carbon::now();
        $latestRecordedAt = null;

        $rows = [];
        foreach ($request->input('snapshots') as $snapshot) {
            $recordedAt = isset($snapshot['recorded_at'])
                ? Carbon::parse($snapshot['recorded_at'])
                : $now;

            if ($latestRecordedAt === null || $recordedAt->greaterThan($latestRecordedAt)) {
                $latestRecordedAt = $recordedAt;
            }

            $rows[] = [
                'device_id' => $device->id,
                'battery_level' => $snapshot['battery_level'] ?? null,
                'connectivity_type' => $snapshot['connectivity_type'] ?? null,
                'signal_strength' => $snapshot['signal_strength'] ?? null,
                'app_version' => $snapshot['app_version'] ?? null,
                'os_version' => $snapshot['os_version'] ?? null,
                'recorded_at' => $recordedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DeviceTelemetry::insert($rows);

        // Telemetry doubles as a heartbeat.
        $device->update(['last_seen_at' => $latestRecordedAt ?? $now]);

        return response()->json([
            'success' => true,
            'ingested' => count($rows),
        ], 201);
    }

    private function validateDeviceToken($id): bool
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $deviceId = $payload->get('sub');
            $tokenType = $payload->get('type');

            return $tokenType === 'device_token' && (string) $deviceId === (string) $id;
        } catch (\Exception $e) {
            return false;
        }
    }
}
