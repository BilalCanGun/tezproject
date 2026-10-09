<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_attempt_id', 'rater_id', 'rater_type', 'scores', 'total', 'notes', 'model'])]
class Rating extends Model
{
    public const TYPE_HUMAN = 'human';

    public const TYPE_LLM = 'llm';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'total' => 'float',
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
