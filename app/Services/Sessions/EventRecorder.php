<?php

namespace App\Services\Sessions;

use App\Enums\EventType;
use App\Models\ExperimentSession;
use App\Models\InteractionEvent;
use App\Models\TaskAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persists kiosk event batches. Batches are retried by the client until they
 * are acknowledged, so the (session, sequence) pair makes ingestion idempotent
 * and attempt counters are only updated for events seen for the first time.
 */
class EventRecorder
{
    public const SERVER_SEQUENCE_START = 1_000_000;

    /**
     * @param  list<array{seq: int, type: string, epoch_ms: int, perf_ms: float, attempt_id?: ?int, lsl_time?: ?float, payload?: ?array<string, mixed>}>  $events
     * @return int number of newly stored events
     */
    public function record(ExperimentSession $session, array $events): int
    {
        return DB::transaction(function () use ($session, $events) {
            $events = collect($events)->keyBy('seq');
            $known = $session->events()->whereIn('sequence', $events->keys())->pluck('sequence')->all();
            $fresh = $events->except($known);

            $acks = $fresh->where('type', EventType::MarkerAck->value);
            $this->applyLateAcks($session, $acks);

            $attemptIds = $session->attempts()->pluck('id')->all();
            $now = now();

            $rows = $fresh->map(function (array $event) use ($session, $attemptIds, $now) {
                $type = EventType::from($event['type']);
                $attemptId = in_array($event['attempt_id'] ?? null, $attemptIds, true) ? $event['attempt_id'] : null;

                return [
                    'experiment_session_id' => $session->id,
                    'task_attempt_id' => $attemptId,
                    'sequence' => $event['seq'],
                    'type' => $type->value,
                    'marker_code' => $type->markerCode(),
                    'payload' => isset($event['payload']) ? json_encode($event['payload']) : null,
                    'client_epoch_ms' => $event['epoch_ms'],
                    'client_perf_ms' => $event['perf_ms'],
                    'lsl_time' => $event['lsl_time'] ?? null,
                    'received_at' => $now,
                ];
            });

            foreach ($rows->chunk(200) as $chunk) {
                InteractionEvent::insert($chunk->values()->all());
            }

            $this->updateAttemptCounters($fresh->whereNotNull('attempt_id')->whereIn('attempt_id', $attemptIds));

            return $rows->count();
        });
    }

    /**
     * Server-originated events (tester notes, pause/resume) use a separate
     * sequence range. They have no LSL time yet; the kiosk relays them to the
     * bridge on its next poll and reports the LSL time back as a marker_ack.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordServerEvent(ExperimentSession $session, EventType $type, array $payload = []): InteractionEvent
    {
        return DB::transaction(function () use ($session, $type, $payload) {
            $sequence = max(
                self::SERVER_SEQUENCE_START,
                (int) $session->events()->where('sequence', '>=', self::SERVER_SEQUENCE_START)->lockForUpdate()->max('sequence') + 1,
            );

            $now = now();

            return $session->events()->create([
                'sequence' => $sequence,
                'type' => $type->value,
                'marker_code' => $type->markerCode(),
                'payload' => $payload ?: null,
                'client_epoch_ms' => $now->getTimestampMs(),
                'client_perf_ms' => 0,
                'received_at' => $now,
            ]);
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $acks
     */
    private function applyLateAcks(ExperimentSession $session, Collection $acks): void
    {
        foreach ($acks as $ack) {
            $target = $ack['payload']['target_seq'] ?? null;
            $lslTime = $ack['payload']['lsl_time'] ?? null;

            if ($target !== null && $lslTime !== null) {
                $session->events()
                    ->where('sequence', $target)
                    ->whereNull('lsl_time')
                    ->update(['lsl_time' => $lslTime]);
            }
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function updateAttemptCounters(Collection $events): void
    {
        $byAttempt = $events->sortBy('seq')->groupBy('attempt_id');

        foreach ($byAttempt as $attemptId => $attemptEvents) {
            $attempt = TaskAttempt::query()->lockForUpdate()->find($attemptId);

            foreach ($attemptEvents as $event) {
                $payload = $event['payload'] ?? [];

                match (EventType::from($event['type'])) {
                    EventType::CodeChange => $this->applyCodeChange($attempt, $payload),
                    EventType::Paste => $this->applyPaste($attempt, $payload),
                    EventType::CodeRun => $this->applyRun($attempt, $payload, (int) $event['epoch_ms']),
                    EventType::ResourceOpen => $attempt->resource_view_count++,
                    EventType::PlanningInputViolation => $attempt->planning_input_violations++,
                    EventType::FocusLost => $attempt->focus_loss_count++,
                    default => null,
                };
            }

            $attempt->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyCodeChange(TaskAttempt $attempt, array $payload): void
    {
        $attempt->typed_chars += max(0, (int) ($payload['typed'] ?? 0));
        $attempt->deleted_chars += max(0, (int) ($payload['deleted'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPaste(TaskAttempt $attempt, array $payload): void
    {
        if (($payload['target'] ?? null) !== 'editor') {
            return;
        }

        $length = max(0, (int) ($payload['length'] ?? 0));
        $attempt->pasted_chars += $length;

        if (! empty($payload['from_assistant'])) {
            $attempt->ai_pasted_chars += $length;
        }
    }

    /**
     * A run counts as "changed after failure" when the code differs from the
     * previous run and an earlier run had already failed — the operational
     * definition of persisting after an error.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyRun(TaskAttempt $attempt, array $payload, int $epochMs): void
    {
        $passed = (int) ($payload['tests_passed'] ?? 0);
        $total = (int) ($payload['tests_total'] ?? 0);
        $failed = ! empty($payload['error']) || $passed < $total;
        $hash = isset($payload['code_hash']) ? (string) $payload['code_hash'] : null;

        if ($attempt->first_failure_at !== null && $hash !== null && $hash !== $attempt->last_run_code_hash) {
            $attempt->changed_runs_after_failure++;
        }

        $attempt->run_count++;

        if ($failed) {
            $attempt->failed_run_count++;
            $attempt->first_failure_at ??= Carbon::createFromTimestampMs($epochMs);
        }

        $attempt->last_run_code_hash = $hash;
    }
}
