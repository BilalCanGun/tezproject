<?php

namespace App\Models;

use App\Enums\AttemptPhase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'experiment_session_id', 'session_step_id', 'task_id', 'phase', 'help_available', 'started_at',
])]
class TaskAttempt extends Model
{
    public const END_SUBMITTED = 'submitted';

    public const END_TIMEOUT = 'timeout';

    public const END_GAVE_UP = 'gave_up';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => AttemptPhase::class,
            'help_available' => 'boolean',
            'started_at' => 'datetime',
            'planning_started_at' => 'datetime',
            'coding_started_at' => 'datetime',
            'checking_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'first_failure_at' => 'datetime',
            'auto_score' => 'float',
        ];
    }

    /**
     * Share of code characters that were typed rather than pasted.
     */
    public function selfWrittenRatio(): ?float
    {
        $total = $this->typed_chars + $this->pasted_chars;

        return $total > 0 ? round($this->typed_chars / $total, 3) : null;
    }

    /**
     * Seconds from first coding moment to the end of the attempt.
     */
    public function codingSeconds(): ?int
    {
        if (! $this->coding_started_at || ! $this->submitted_at) {
            return null;
        }

        return (int) $this->coding_started_at->diffInSeconds($this->submitted_at);
    }

    /**
     * Seconds spent working after the first failed run, a persistence indicator.
     */
    public function secondsAfterFirstFailure(): ?int
    {
        if (! $this->first_failure_at || ! $this->submitted_at) {
            return null;
        }

        return (int) $this->first_failure_at->diffInSeconds($this->submitted_at);
    }

    /**
     * @return BelongsTo<ExperimentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperimentSession::class, 'experiment_session_id');
    }

    /**
     * @return BelongsTo<SessionStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(SessionStep::class, 'session_step_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function aiMessages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<InteractionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InteractionEvent::class);
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }
}
