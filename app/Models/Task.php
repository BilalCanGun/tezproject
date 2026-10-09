<?php

namespace App\Models;

use App\Enums\TaskKind;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'protocol_version_id', 'session_number', 'kind', 'title', 'isomorph_group', 'sort_order',
    'prompt', 'starter_code', 'reference_solution', 'test_cases', 'mcq_options', 'mcq_answer',
    'max_score', 'min_reading_seconds', 'planning_seconds', 'time_limit_seconds',
    'help_allowed', 'is_active',
])]
#[Hidden(['reference_solution', 'mcq_answer'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'session_number' => 'integer',
            'test_cases' => 'array',
            'mcq_options' => 'array',
            'max_score' => 'float',
            'help_allowed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Fields the participant kiosk may see. Reference solutions and answer keys
     * never leave the server.
     *
     * @return array<string, mixed>
     */
    public function toKioskArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'response_format' => $this->kind->responseFormat(),
            'title' => $this->title,
            'prompt' => $this->prompt,
            'starter_code' => $this->starter_code,
            'tests' => collect($this->test_cases ?? [])
                ->map(fn (array $test) => [
                    'call' => $test['call'],
                    'expected' => $test['expected'],
                    'hidden' => (bool) ($test['hidden'] ?? false),
                ])
                ->values()
                ->all(),
            'mcq_options' => $this->mcq_options,
            'min_reading_seconds' => $this->min_reading_seconds,
            'planning_seconds' => $this->planning_seconds,
            'time_limit_seconds' => $this->time_limit_seconds,
        ];
    }

    /**
     * @return BelongsTo<ProtocolVersion, $this>
     */
    public function protocolVersion(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class);
    }

    /**
     * @return HasMany<TaskAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(TaskAttempt::class);
    }
}
