<?php

namespace App\Services\Sessions;

use App\Enums\AttemptPhase;
use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Enums\StepType;
use App\Models\ExperimentSession;
use App\Models\SessionStep;
use App\Models\TaskAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the session state machine: preparation, step progression, phase
 * transitions inside a task, submission and completion. The kiosk can only
 * move forward; it can never reopen a finished step.
 */
class SessionRunner
{
    public function __construct(private SessionPlanBuilder $planBuilder) {}

    /**
     * Freeze the assistant configuration for this session and materialise its
     * step plan. Called when the tester opens the kiosk link.
     */
    public function prepare(ExperimentSession $session): void
    {
        if ($session->status->isFinished()) {
            throw new RuntimeException('Tamamlanmış veya kesilmiş bir oturum yeniden hazırlanamaz.');
        }

        $protocol = $session->participant->protocolVersion;

        DB::transaction(function () use ($session, $protocol) {
            $session->forceFill([
                'status' => $session->status === SessionStatus::Scheduled ? SessionStatus::Preparing : $session->status,
                'condition' => $session->kind() === SessionKind::Baseline ? null : $session->participant->condition,
                'ai_settings_snapshot' => $session->ai_settings_snapshot ?? [
                    'protocol_version_id' => $protocol->id,
                    'protocol_code' => $protocol->code,
                    'protocol_frozen' => $protocol->isFrozen(),
                    'assistant' => $protocol->resolvedSettings()['assistant'],
                    'system_prompt_direct' => $protocol->system_prompt_direct,
                    'system_prompt_socratic' => $protocol->system_prompt_socratic,
                    'captured_at' => now()->toIso8601String(),
                ],
            ])->save();

            if (! $session->steps()->exists()) {
                $this->planBuilder->build($session);
            }
        });
    }

    public function currentStep(ExperimentSession $session): ?SessionStep
    {
        return $session->steps()
            ->whereIn('status', [SessionStep::STATUS_ACTIVE, SessionStep::STATUS_PENDING])
            ->orderBy('position')
            ->first();
    }

    public function startStep(ExperimentSession $session, SessionStep $step): SessionStep
    {
        $this->guardRunnable($session);

        $next = $this->currentStep($session);

        if (! $next || $next->id !== $step->id) {
            throw new RuntimeException('Adımlar sırayla yürütülmelidir.');
        }

        if ($step->status === SessionStep::STATUS_ACTIVE) {
            return $step->load('attempt');
        }

        return DB::transaction(function () use ($session, $step) {
            if ($session->status !== SessionStatus::Running) {
                $session->forceFill([
                    'status' => SessionStatus::Running,
                    'started_at' => $session->started_at ?? now(),
                ])->save();
            }

            $step->forceFill(['status' => SessionStep::STATUS_ACTIVE, 'started_at' => now()])->save();

            if ($step->type === StepType::Task) {
                $step->attempt()->create([
                    'experiment_session_id' => $session->id,
                    'task_id' => $step->task_id,
                    'phase' => AttemptPhase::Reading,
                    'help_available' => $session->allowsAssistance() && $step->task->help_allowed,
                    'started_at' => now(),
                ]);
            }

            return $step->load('attempt');
        });
    }

    /**
     * Finish a non-task step (instructions, baseline recording, sync gesture).
     */
    public function completeStep(ExperimentSession $session, SessionStep $step): void
    {
        $this->guardRunnable($session);

        if ($step->status !== SessionStep::STATUS_ACTIVE) {
            throw new RuntimeException('Yalnızca etkin adım tamamlanabilir.');
        }

        if ($step->type === StepType::Task) {
            throw new RuntimeException('Görev adımları teslim ile tamamlanır.');
        }

        DB::transaction(function () use ($session, $step) {
            $step->forceFill(['status' => SessionStep::STATUS_DONE, 'ended_at' => now()])->save();

            if ($step->type === StepType::Closing) {
                $this->finish($session);
            }
        });
    }

    public function advancePhase(TaskAttempt $attempt, AttemptPhase $phase): TaskAttempt
    {
        $this->guardRunnable($attempt->session);

        if ($attempt->phase === $phase) {
            return $attempt;
        }

        if (! in_array($phase, $attempt->phase->allowedNext(), true) || $phase === AttemptPhase::Submitted) {
            throw new RuntimeException("{$attempt->phase->label()} fazından {$phase->label()} fazına geçilemez.");
        }

        $column = match ($phase) {
            AttemptPhase::Planning => 'planning_started_at',
            AttemptPhase::Coding => 'coding_started_at',
            AttemptPhase::Checking => 'checking_started_at',
            default => null,
        };

        $attempt->phase = $phase;

        if ($column && $attempt->{$column} === null) {
            $attempt->{$column} = now();
        }

        $attempt->save();

        return $attempt;
    }

    /**
     * @param  array{end_reason: string, code?: ?string, tests_passed?: ?int, tests_total?: ?int, mcq_answer?: ?string, explanation?: ?string}  $data
     */
    public function submit(TaskAttempt $attempt, array $data): TaskAttempt
    {
        $this->guardRunnable($attempt->session);

        if ($attempt->phase === AttemptPhase::Submitted) {
            return $attempt;
        }

        $task = $attempt->task;

        return DB::transaction(function () use ($attempt, $task, $data) {
            $attempt->forceFill([
                'phase' => AttemptPhase::Submitted,
                'submitted_at' => now(),
                'end_reason' => $data['end_reason'],
                'final_code' => $data['code'] ?? null,
                'explanation_text' => $data['explanation'] ?? null,
                'mcq_answer' => $data['mcq_answer'] ?? null,
                'tests_passed' => $data['tests_passed'] ?? null,
                'tests_total' => $data['tests_total'] ?? null,
                'auto_score' => $this->autoScore($task->kind->responseFormat(), $task->max_score, $task->mcq_answer, $data),
            ])->save();

            $attempt->step->forceFill(['status' => SessionStep::STATUS_DONE, 'ended_at' => now()])->save();

            return $attempt;
        });
    }

    public function abort(ExperimentSession $session, string $reason): void
    {
        if ($session->status->isFinished()) {
            return;
        }

        $session->forceFill([
            'status' => SessionStatus::Aborted,
            'abort_reason' => $reason,
            'ended_at' => now(),
        ])->save();
    }

    public function setPaused(ExperimentSession $session, bool $paused): void
    {
        $session->forceFill(['is_paused' => $paused])->save();
    }

    private function finish(ExperimentSession $session): void
    {
        $session->forceFill(['status' => SessionStatus::Completed, 'ended_at' => now()])->save();

        $participant = $session->participant;

        match ($session->kind()) {
            SessionKind::Baseline => $this->storeBaselineScore($session),
            SessionKind::Learning => $participant->forceFill(['status' => ParticipantStatus::FirstSessionDone])->save(),
            SessionKind::Delayed => $participant->forceFill(['status' => ParticipantStatus::Completed])->save(),
        };
    }

    private function storeBaselineScore(ExperimentSession $session): void
    {
        $attempts = $session->attempts()->with('task')->get();

        $session->participant->forceFill([
            'baseline_score' => round($attempts->sum(fn (TaskAttempt $attempt) => $attempt->auto_score ?? 0), 2),
            'baseline_max' => round($attempts->sum(fn (TaskAttempt $attempt) => $attempt->task->max_score), 2),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function autoScore(string $format, float $maxScore, ?string $answerKey, array $data): ?float
    {
        return match ($format) {
            'mcq' => ($data['mcq_answer'] ?? null) !== null && $data['mcq_answer'] === $answerKey ? $maxScore : 0.0,
            'code' => ($data['tests_total'] ?? 0) > 0
                ? round($maxScore * min($data['tests_passed'] ?? 0, $data['tests_total']) / $data['tests_total'], 2)
                : 0.0,
            default => null,
        };
    }

    private function guardRunnable(ExperimentSession $session): void
    {
        if ($session->status->isFinished()) {
            throw new RuntimeException('Oturum sona ermiş.');
        }

        if ($session->is_paused) {
            throw new RuntimeException('Oturum araştırmacı tarafından duraklatıldı.');
        }
    }
}
