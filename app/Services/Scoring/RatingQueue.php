<?php

namespace App\Services\Scoring;

use App\Enums\TaskKind;
use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Models\TaskAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Blind rating queue for explanation responses. Every response needs one human
 * rating; a fixed, hash-selected share needs a second independent rating for
 * reliability. Raters never see the condition, participant code or AI chat.
 */
class RatingQueue
{
    public function requiredHumanRatings(TaskAttempt $attempt, ProtocolVersion $protocol): int
    {
        $percent = (int) $protocol->setting('rating.double_rating_percent');

        return (crc32('rating-'.$attempt->id) % 100) < $percent ? 2 : 1;
    }

    /**
     * @return Collection<int, TaskAttempt>
     */
    public function pendingFor(User $rater, ProtocolVersion $protocol, int $limit = 50): Collection
    {
        return $this->explanationAttempts($protocol)
            ->whereDoesntHave('ratings', fn (Builder $query) => $query->where('rater_id', $rater->id))
            ->withCount(['ratings as human_ratings_count' => fn (Builder $query) => $query->where('rater_type', Rating::TYPE_HUMAN)])
            ->get()
            ->filter(fn (TaskAttempt $attempt) => $attempt->human_ratings_count < $this->requiredHumanRatings($attempt, $protocol))
            ->sortBy(fn (TaskAttempt $attempt) => crc32('order-'.$attempt->id))
            ->take($limit)
            ->values();
    }

    /**
     * @return Builder<TaskAttempt>
     */
    public function explanationAttempts(ProtocolVersion $protocol): Builder
    {
        return TaskAttempt::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('explanation_text')
            ->whereHas('task', fn (Builder $query) => $query
                ->where('kind', TaskKind::Explanation->value)
                ->where('protocol_version_id', $protocol->id));
    }

    /**
     * Neutral, non-identifying label shown to raters instead of the code.
     */
    public static function blindLabel(TaskAttempt $attempt): string
    {
        return 'R-'.strtoupper(substr(hash('sha256', 'blind-'.$attempt->id.config('app.key')), 0, 6));
    }
}
