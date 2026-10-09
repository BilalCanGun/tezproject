<?php

namespace App\Services\Scoring;

use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Models\RubricCriterion;
use App\Models\TaskAttempt;
use App\Services\Analytics\Statistics;

class ReliabilityReport
{
    public function __construct(private RatingQueue $queue) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ProtocolVersion $protocol): array
    {
        $criteria = $protocol->rubricCriteria()->orderBy('sort_order')->get();
        $attempts = $this->queue->explanationAttempts($protocol)->with('ratings')->get();

        $doubleRated = $attempts
            ->map(fn (TaskAttempt $attempt) => $attempt->ratings->where('rater_type', Rating::TYPE_HUMAN)->sortBy('id')->take(2)->values())
            ->filter(fn ($ratings) => $ratings->count() === 2)
            ->values();

        $llmPairs = $attempts
            ->map(function (TaskAttempt $attempt) {
                $llm = $attempt->ratings->firstWhere('rater_type', Rating::TYPE_LLM);
                $humans = $attempt->ratings->where('rater_type', Rating::TYPE_HUMAN);

                return $llm && $humans->isNotEmpty() ? [(float) $llm->total, (float) $humans->avg('total')] : null;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'responses' => $attempts->count(),
            'rated_once' => $attempts->filter(fn (TaskAttempt $attempt) => $attempt->ratings->where('rater_type', Rating::TYPE_HUMAN)->isNotEmpty())->count(),
            'double_rated' => $doubleRated->count(),
            'llm_rated' => $attempts->filter(fn (TaskAttempt $attempt) => $attempt->ratings->contains('rater_type', Rating::TYPE_LLM))->count(),
            'total_icc' => $this->withLabel(Statistics::iccOneWay(
                $doubleRated->map(fn ($pair) => [(float) $pair[0]->total, (float) $pair[1]->total])->all()
            )),
            'criteria' => $criteria->map(fn (RubricCriterion $criterion) => [
                'key' => $criterion->key,
                'label' => $criterion->label,
                'kappa' => $this->withLabel(Statistics::weightedKappa(
                    $doubleRated
                        ->map(fn ($pair) => [(int) ($pair[0]->scores[$criterion->key] ?? $criterion->min_score), (int) ($pair[1]->scores[$criterion->key] ?? $criterion->min_score)])
                        ->all(),
                    $criterion->min_score,
                    $criterion->max_score,
                )),
            ])->all(),
            'llm_vs_human_icc' => $this->withLabel(Statistics::iccTwoWayAgreement($llmPairs)),
            'llm_pairs' => count($llmPairs),
        ];
    }

    /**
     * @return array{value: ?float, label: string}
     */
    private function withLabel(?float $value): array
    {
        return ['value' => $value, 'label' => Statistics::interpretReliability($value)];
    }
}
