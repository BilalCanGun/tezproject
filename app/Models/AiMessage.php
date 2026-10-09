<?php

namespace App\Models;

use App\Enums\AssistanceCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'experiment_session_id', 'task_attempt_id', 'role', 'content', 'raw_content', 'condition',
    'provider', 'model', 'request_params', 'hint_level', 'input_tokens', 'output_tokens',
    'latency_ms', 'stop_reason', 'metrics', 'guard_action', 'error', 'created_at',
])]
class AiMessage extends Model
{
    public const UPDATED_AT = null;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => AssistanceCondition::class,
            'request_params' => 'array',
            'metrics' => 'array',
            'created_at' => 'datetime',
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
     * @return BelongsTo<ExperimentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperimentSession::class, 'experiment_session_id');
    }
}
