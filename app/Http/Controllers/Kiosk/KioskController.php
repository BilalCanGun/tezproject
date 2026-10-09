<?php

namespace App\Http\Controllers\Kiosk;

use App\Enums\EventType;
use App\Enums\StepType;
use App\Models\AiMessage;
use App\Models\InteractionEvent;
use App\Models\SessionStep;
use App\Services\Sessions\EventRecorder;
use App\Services\Sessions\SessionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KioskController extends KioskApiController
{
    public function show(Request $request): Response
    {
        $session = $this->kioskSession($request);

        if (! $session->kiosk_connected_at) {
            $session->forceFill(['kiosk_connected_at' => now()])->save();
        }

        return Inertia::render('Kiosk/Run', [
            'apiBase' => url()->current().'/api',
            'records_physiology' => $session->kind()->recordsPhysiology(),
            'pyodideIndexUrl' => config('experiment.pyodide_index_url'),
            'bridgeUrl' => config('experiment.bridge_url'),
            'runTimeoutSeconds' => config('experiment.code_run_timeout_seconds'),
            'participantCode' => $session->participant->code,
        ]);
    }

    /**
     * Full kiosk state. The kiosk re-reads this after every transition and
     * polls it while idle, so a page reload always resumes at the right step.
     */
    public function state(Request $request, SessionRunner $runner): JsonResponse
    {
        $session = $this->kioskSession($request);
        $session->load('steps');
        $current = $session->status->isFinished() ? null : $runner->currentStep($session);
        $current?->load(['task', 'attempt']);

        return response()->json([
            'server_time_ms' => now()->getTimestampMs(),
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'is_paused' => $session->is_paused,
                'number' => $session->session_number,
            ],
            'progress' => [
                'done' => $session->steps->where('status', SessionStep::STATUS_DONE)->count(),
                'total' => $session->steps->count(),
            ],
            'last_sequence' => (int) $session->events()->where('sequence', '<', EventRecorder::SERVER_SEQUENCE_START)->max('sequence'),
            'step' => $current ? $this->presentStep($current, $session->condition?->usesAssistant()) : null,
            'relay' => $session->events()
                ->where('sequence', '>=', EventRecorder::SERVER_SEQUENCE_START)
                ->whereNull('lsl_time')
                ->whereNotNull('marker_code')
                ->where('received_at', '>=', now()->subMinutes(10))
                ->orderBy('sequence')
                ->get(['sequence', 'type', 'marker_code'])
                ->map(fn (InteractionEvent $event) => [
                    'seq' => $event->sequence,
                    'type' => $event->type,
                    'marker_code' => $event->marker_code,
                ]),
            'marker_codes' => collect(EventType::cases())
                ->mapWithKeys(fn (EventType $type) => [$type->value => $type->markerCode()]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStep(SessionStep $step, ?bool $assistantCondition): array
    {
        $attempt = $step->attempt;

        return [
            'id' => $step->id,
            'type' => $step->type->value,
            'label' => $step->type === StepType::Task ? $step->task->kind->label() : $step->label,
            'status' => $step->status,
            'config' => $step->config,
            'task' => $step->task?->toKioskArray(),
            'attempt' => $attempt ? [
                'id' => $attempt->id,
                'phase' => $attempt->phase->value,
                'help_mode' => $attempt->help_available ? ($assistantCondition ? 'assistant' : 'resource') : null,
                'elapsed_seconds' => (int) $attempt->started_at->diffInSeconds(now()),
                'coding_elapsed_seconds' => $attempt->coding_started_at ? (int) $attempt->coding_started_at->diffInSeconds(now()) : null,
                'messages' => $attempt->help_available && $assistantCondition
                    ? $attempt->aiMessages()->get()->map(fn (AiMessage $message) => [
                        'id' => $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'failed' => $message->error !== null,
                    ])
                    : [],
            ] : null,
        ];
    }
}
