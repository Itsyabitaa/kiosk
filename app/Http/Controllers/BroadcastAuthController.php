<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Custom broadcasting auth endpoint. The stock Laravel /broadcasting/auth relies on session
 * (web) auth, but our clients authenticate with JWTs: devices hold a `device_token` JWT and
 * admins hold an admin JWT. We authorize the requested private channel against the caller's
 * JWT and return the Pusher-compatible auth signature Reverb expects.
 */
class BroadcastAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $channel = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (!$channel || !$socketId) {
            return response()->json(['error' => 'channel_name and socket_id are required'], 422);
        }

        try {
            $payload = JWTAuth::parseToken()->getPayload();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$this->authorizes($payload, $channel)) {
            return response()->json(['error' => 'Forbidden channel'], 403);
        }

        return response()->json($this->pusherAuth($socketId, $channel));
    }

    /**
     * Determine whether the JWT is allowed to subscribe to the requested channel.
     * - Devices may only subscribe to their own `private-device.{id}`.
     * - Admins may only subscribe to their org's `private-org.{orgId}`.
     */
    private function authorizes($payload, string $channel): bool
    {
        $type = $payload->get('type');

        if ($type === 'device_token') {
            return preg_match('/^private-device\.(\d+)$/', $channel, $m)
                && (string) $payload->get('sub') === $m[1];
        }

        // Admin token: resolve the org from the authenticated admin model so we don't depend on
        // a specific claim shape.
        if (preg_match('/^private-org\.(\d+)$/', $channel, $m)) {
            $orgId = $payload->get('org_id');
            if ($orgId === null) {
                $admin = auth('api')->user();
                $orgId = $admin?->org_id;
            }

            return $orgId !== null && (string) $orgId === $m[1];
        }

        return false;
    }

    /**
     * Build the Pusher private-channel auth response: "{key}:{hmac_sha256(socket:channel)}".
     */
    private function pusherAuth(string $socketId, string $channel): array
    {
        $config = config('broadcasting.connections.reverb');
        $key = $config['key'] ?? '';
        $secret = $config['secret'] ?? '';

        $signature = hash_hmac('sha256', $socketId . ':' . $channel, $secret);

        return ['auth' => $key . ':' . $signature];
    }
}
