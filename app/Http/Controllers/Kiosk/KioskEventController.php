<?php

namespace App\Http\Controllers\Kiosk;

use App\Enums\EventType;
use App\Services\Sessions\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KioskEventController extends KioskApiController
{
    public function __invoke(Request $request, EventRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:500'],
            'events.*.seq' => ['required', 'integer', 'min:1', 'lt:'.EventRecorder::SERVER_SEQUENCE_START],
            'events.*.type' => ['required', Rule::in(EventType::kioskValues())],
            'events.*.epoch_ms' => ['required', 'integer', 'min:1600000000000'],
            'events.*.perf_ms' => ['required', 'numeric', 'min:0'],
            'events.*.attempt_id' => ['nullable', 'integer'],
            'events.*.lsl_time' => ['nullable', 'numeric'],
            'events.*.payload' => ['nullable', 'array'],
        ]);

        $stored = $recorder->record($this->kioskSession($request), $validated['events']);

        return response()->json([
            'stored' => $stored,
            'acknowledged' => collect($validated['events'])->pluck('seq'),
        ]);
    }
}
