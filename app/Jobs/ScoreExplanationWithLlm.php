<?php

namespace App\Jobs;

use App\Models\TaskAttempt;
use App\Services\Scoring\LlmRubricScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScoreExplanationWithLlm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public TaskAttempt $attempt) {}

    public function handle(LlmRubricScorer $scorer): void
    {
        $scorer->score($this->attempt);
    }
}
