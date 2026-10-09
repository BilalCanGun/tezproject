<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[WithoutTimestamps]
#[Fillable([
    'experiment_session_id', 'task_attempt_id', 'sequence', 'type', 'marker_code', 'payload',
    'client_epoch_ms', 'client_perf_ms', 'lsl_time', 'received_at',
])]
class InteractionEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'client_epoch_ms' => 'integer',
            'client_perf_ms' => 'float',
            'lsl_time' => 'float',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ExperimentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperimentSession::class, 'experiment_session_id');
    }

    /**
     * @return BelongsTo<TaskAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TaskAttempt::class, 'task_attempt_id');
    }
}
