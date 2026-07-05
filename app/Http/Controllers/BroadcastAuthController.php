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

        // Read the token from the current request's Authorization header directly. (Relying on
        // JWTAuth::getToken() can return a stale token cached on the singleton from a prior
        // request within the same long-lived process.)
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        try {
            // Decode straight from the token via the provider (symmetric to how tokens are
            // encoded). This reads the token's true claims and is immune to the shared JWT
            // factory's cross-call state, unlike parseToken()->getPayload().
            $claims = JWTAuth::getJWTProvider()->decode($token);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$this->authorizes($claims, $channel)) {
            return response()->json(['error' => 'Forbidden channel'], 403);
        }

        return response()->json($this->pusherAuth($socketId, $channel));
    }

    /**
     * Determine whether the JWT is allowed to subscribe to the requested channel.
     * - Devices may only subscribe to their own `private-device.{id}`.
     * - Admins may only subscribe to their org's `private-org.{orgId}`.
     *
     * @param array $claims
     */
    private function authorizes(array $claims, string $channel): bool
    {
        $type = $claims['type'] ?? null;

        if (preg_match('/^private-device\.(\d+)$/', $channel, $m)) {
            return $type === 'device_token' && (string) ($claims['sub'] ?? '') === $m[1];
        }

        if (preg_match('/^private-org\.(\d+)$/', $channel, $m)) {
            // Only non-device (admin) tokens may listen on an org channel.
            return $type !== 'device_token' && (string) ($claims['org_id'] ?? '') === $m[1];
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
