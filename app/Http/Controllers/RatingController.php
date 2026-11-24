<?php

namespace App\Http\Controllers;

use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Models\RubricCriterion;
use App\Models\TaskAttempt;
use App\Services\Scoring\RatingQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatingController extends Controller
{
    public function index(Request $request, RatingQueue $queue): Response
    {
        $protocol = ProtocolVersion::active()->first();

        return Inertia::render('Ratings/Index', [
            'protocol' => $protocol?->only(['id', 'code']),
            'pending' => $protocol
                ? $queue->pendingFor($request->user(), $protocol)->load('task:id,title')->map(fn (TaskAttempt $attempt) => [
                    'id' => $attempt->id,
                    'blind_label' => RatingQueue::blindLabel($attempt),
                    'task_title' => $attempt->task->title,
                    'length' => mb_strlen((string) $attempt->explanation_text),
                ])
                : [],
            'completedCount' => $request->user()->ratings()->count(),
        ]);
    }

    public function edit(Request $request, TaskAttempt $attempt): Response
    {
        $attempt->load('task.protocolVersion');
        $this->ensureExplanation($attempt);

        $existing = $attempt->ratings()->where('rater_id', $request->user()->id)->first();

        return Inertia::render('Ratings/Edit', [
            'attempt' => [
                'id' => $attempt->id,
                'blind_label' => RatingQueue::blindLabel($attempt),
                'task_title' => $attempt->task->title,
                'task_prompt' => $attempt->task->prompt,
                'explanation' => $attempt->explanation_text,
                'scoring_key' => $attempt->task->scoring_key,
            ],
            'criteria' => $attempt->task->protocolVersion->rubricCriteria()->orderBy('sort_order')->get()
                ->map(fn (RubricCriterion $criterion) => $criterion->only(['key', 'label', 'description', 'min_score', 'max_score', 'anchors'])),
            'existing' => $existing?->only(['scores', 'notes']),
        ]);
    }

    public function update(Request $request, TaskAttempt $attempt, RatingQueue $queue): RedirectResponse
    {
        $attempt->load('task.protocolVersion');
        $this->ensureExplanation($attempt);

        $criteria = $attempt->task->protocolVersion->rubricCriteria()->get();
        $rules = $criteria->mapWithKeys(fn (RubricCriterion $criterion) => [
            "scores.{$criterion->key}" => ['required', 'integer', "between:{$criterion->min_score},{$criterion->max_score}"],
        ])->all();

        $validated = $request->validate([
            'scores' => ['required', 'array'],
            ...$rules,
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $scores = collect($validated['scores'])->only($criteria->pluck('key'))->map(fn ($score) => (int) $score)->all();

        Rating::updateOrCreate(
            ['task_attempt_id' => $attempt->id, 'rater_id' => $request->user()->id],
            [
                'rater_type' => Rating::TYPE_HUMAN,
                'scores' => $scores,
                'total' => array_sum($scores),
                'notes' => $validated['notes'] ?? null,
            ],
        );

        $next = $queue->pendingFor($request->user(), $attempt->task->protocolVersion, 1)->first();

        return $next
            ? redirect()->route('ratings.edit', $next)->with('success', 'Puan kaydedildi. Sıradaki yanıt açıldı.')
            : redirect()->route('ratings.index')->with('success', 'Puan kaydedildi. Kuyrukta bekleyen yanıt kalmadı.');
    }

    private function ensureExplanation(TaskAttempt $attempt): void
    {
        abort_unless($attempt->explanation_text !== null && $attempt->submitted_at !== null, 404);
    }
}
