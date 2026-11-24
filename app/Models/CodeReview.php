<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blind human score of a coding attempt against the task's scoring key. The
 * automatic test score on the attempt is kept unchanged next to it.
 */
#[Fillable(['task_attempt_id', 'rater_id', 'score', 'partial_credit', 'misconceptions', 'bugs_found', 'alternative_solution', 'notes'])]
class CodeReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'partial_credit' => 'array',
            'misconceptions' => 'array',
            'bugs_found' => 'array',
            'alternative_solution' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TaskAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TaskAttempt::class, 'task_attempt_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }
}
