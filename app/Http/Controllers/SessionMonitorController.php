<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\AiMessage;
use App\Models\ExperimentSession;
use App\Models\InteractionEvent;
use App\Models\SessionStep;
use Illuminate\Http\JsonResponse;

/**
 * Polled by the tester's live monitor every few seconds. Read-only.
 */
class SessionMonitorController extends Controller
{
    public function __invoke(ExperimentSession $session): JsonResponse
    {
        $session->load(['steps.attempt.task:id,kind,title,time_limit_seconds,planning_seconds']);
        $active = $session->steps->firstWhere('status', SessionStep::STATUS_ACTIVE)
            ?? $session->steps->firstWhere('status', SessionStep::STATUS_PENDING);
        $attempt = $active?->attempt;

        $events = $session->events()
            ->whereNotIn('type', [EventType::CodeChange->value, EventType::ClockSync->value, EventType::AoiLayout->value])
            ->latest('id')
            ->take(25)
            ->get(['id', 'sequence', 'type', 'marker_code', 'payload', 'client_epoch_ms', 'lsl_time']);

        $markers = $session->events()->whereNotNull('marker_code');

        return response()->json([
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'is_paused' => $session->is_paused,
            'kiosk_last_seen_at' => $session->kiosk_last_seen_at?->toIso8601String(),
            'kiosk_online' => $session->kiosk_last_seen_at?->gt(now()->subSeconds(15)) ?? false,
            'bridge' => $session->bridge_status,
            'client_clock_offset_ms' => $session->client_clock_offset_ms,
            'steps' => $session->steps->map(fn (SessionStep $step) => [
                'id' => $step->id,
                'position' => $step->position,
                'label' => $step->label,
                'status' => $step->status,
                'started_at' => $step->started_at?->toIso8601String(),
                'ended_at' => $step->ended_at?->toIso8601String(),
            ]),
            'current' => $active ? [
                'step_id' => $active->id,
                'label' => $active->label,
                'started_at' => $active->started_at?->toIso8601String(),
                'attempt' => $attempt ? [
                    'id' => $attempt->id,
                    'phase' => $attempt->phase->value,
                    'phase_label' => $attempt->phase->label(),
                    'time_limit_seconds' => $attempt->task->time_limit_seconds,
                    'help_available' => $attempt->help_available,
                    'run_count' => $attempt->run_count,
                    'failed_run_count' => $attempt->failed_run_count,
                    'help_request_count' => $attempt->help_request_count,
                    'resource_view_count' => $attempt->resource_view_count,
                    'typed_chars' => $attempt->typed_chars,
                    'pasted_chars' => $attempt->pasted_chars,
                    'planning_input_violations' => $attempt->planning_input_violations,
                    'focus_loss_count' => $attempt->focus_loss_count,
                ] : null,
            ] : null,
            'markers' => [
                'sent' => (clone $markers)->count(),
                'acknowledged' => (clone $markers)->whereNotNull('lsl_time')->count(),
            ],
            'events' => $events->map(fn (InteractionEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'label' => EventType::tryFrom($event->type)?->description() ?? $event->type,
                'marker_code' => $event->marker_code,
                'at' => $event->client_epoch_ms,
                'lsl' => $event->lsl_time !== null,
                'detail' => $event->payload['category'] ?? $event->payload['label'] ?? null,
            ]),
            'ai_messages' => $attempt
                ? $attempt->aiMessages()->latest('id')->take(6)->get()->reverse()->values()->map(fn (AiMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => mb_strimwidth($message->content, 0, 600, '…'),
                    'guard_action' => $message->guard_action,
                    'code_lines' => $message->metrics['code_lines'] ?? null,
                    'full_solution' => $message->metrics['full_solution'] ?? null,
                    'error' => $message->error !== null,
                ])
                : [],
        ]);
    }
}
