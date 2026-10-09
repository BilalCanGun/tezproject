<?php

namespace App\Http\Controllers\Kiosk;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kiosk reports the LSL bridge state and its own clock every few seconds,
 * which feeds the tester's live monitor and the clock-drift check.
 */
class KioskHeartbeatController extends KioskApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_epoch_ms' => ['required', 'integer'],
            'bridge' => ['nullable', 'array'],
            'bridge.connected' => ['required_with:bridge', 'boolean'],
            'bridge.outlet' => ['nullable', 'string', 'max:120'],
            'bridge.offset_s' => ['nullable', 'numeric'],
            'bridge.rtt_ms' => ['nullable', 'numeric'],
            'bridge.streams' => ['nullable', 'array', 'max:20'],
            'bridge.streams.*.name' => ['required', 'string', 'max:120'],
            'bridge.streams.*.type' => ['nullable', 'string', 'max:60'],
            'bridge.streams.*.channel_count' => ['nullable', 'integer'],
            'bridge.streams.*.nominal_srate' => ['nullable', 'numeric'],
            'pending_events' => ['nullable', 'integer', 'min:0'],
        ]);

        $now = now();

        $this->kioskSession($request)->forceFill([
            'bridge_status' => [
                ...($validated['bridge'] ?? ['connected' => false]),
                'pending_events' => $validated['pending_events'] ?? 0,
                'reported_at' => $now->toIso8601String(),
            ],
            'client_clock_offset_ms' => $validated['client_epoch_ms'] - $now->getTimestampMs(),
            'kiosk_last_seen_at' => $now,
        ])->save();

        return response()->json(['server_time_ms' => $now->getTimestampMs()]);
    }
}
