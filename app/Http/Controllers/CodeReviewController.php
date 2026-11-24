<?php

namespace App\Http\Controllers;

use App\Models\CodeReview;
use App\Models\ProtocolVersion;
use App\Models\TaskAttempt;
use App\Services\Scoring\RatingQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Blind human scoring of coding attempts against the task's scoring key, so
 * alternative and partially correct solutions are not judged by the tests
 * alone. Raters never see the condition, participant code or AI chat.
 */
class CodeReviewController extends Controller
{
    public function index(Request $request, RatingQueue $queue): Response
    {
        $protocol = ProtocolVersion::active()->first();

        return Inertia::render('CodeReviews/Index', [
            'protocol' => $protocol?->only(['id', 'code']),
            'pending' => $protocol
                ? $queue->pendingCodeReviewsFor($request->user(), $protocol)->load('task:id,title,kind')->map(fn (TaskAttempt $attempt) => [
                    'id' => $attempt->id,
                    'blind_label' => RatingQueue::blindLabel($attempt),
                    'task_title' => $attempt->task->title,
                    'kind_label' => $attempt->task->kind->label(),
                ])
                : [],
            'completedCount' => CodeReview::query()->where('rater_id', $request->user()->id)->count(),
        ]);
    }

    public function edit(Request $request, TaskAttempt $attempt): Response
    {
        $attempt->load('task');
        $this->ensureCoding($attempt);

        $existing = $attempt->codeReviews()->where('rater_id', $request->user()->id)->first();

        return Inertia::render('CodeReviews/Edit', [
            'attempt' => [
                'id' => $attempt->id,
                'blind_label' => RatingQueue::blindLabel($attempt),
                'task_title' => $attempt->task->title,
                'kind_label' => $attempt->task->kind->label(),
                'task_prompt' => $attempt->task->prompt,
                'starter_code' => $attempt->task->starter_code,
                'reference_solution' => $attempt->task->reference_solution,
                'final_code' => $attempt->final_code,
                'tests_passed' => $attempt->tests_passed,
                'tests_total' => $attempt->tests_total,
                'auto_score' => $attempt->auto_score,
                'max_score' => $attempt->task->max_score,
                'end_reason' => $attempt->end_reason,
                'scoring_key' => $attempt->task->scoring_key,
            ],
            'existing' => $existing?->only(['score', 'partial_credit', 'misconceptions', 'bugs_found', 'alternative_solution', 'notes']),
        ]);
    }

    public function update(Request $request, TaskAttempt $attempt, RatingQueue $queue): RedirectResponse
    {
        $attempt->load('task.protocolVersion');
        $this->ensureCoding($attempt);
        $key = $attempt->task->scoring_key ?? [];

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'between:0,'.$attempt->task->max_score],
            'partial_credit' => ['nullable', 'array'],
            'partial_credit.*' => ['integer', Rule::in(array_keys($key['partial_credit'] ?? []))],
            'misconceptions' => ['nullable', 'array'],
            'misconceptions.*' => ['string', Rule::in(array_column($key['misconceptions'] ?? [], 'code'))],
            'bugs_found' => ['nullable', 'array'],
            'bugs_found.*' => ['integer', Rule::in(array_keys($key['target_bugs'] ?? []))],
            'alternative_solution' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        CodeReview::updateOrCreate(
            ['task_attempt_id' => $attempt->id, 'rater_id' => $request->user()->id],
            [
                'score' => $validated['score'],
                'partial_credit' => array_values($validated['partial_credit'] ?? []),
                'misconceptions' => array_values($validated['misconceptions'] ?? []),
                'bugs_found' => array_values($validated['bugs_found'] ?? []),
                'alternative_solution' => $request->boolean('alternative_solution'),
                'notes' => $validated['notes'] ?? null,
            ],
        );

        $next = $queue->pendingCodeReviewsFor($request->user(), $attempt->task->protocolVersion, 1)->first();

        return $next
            ? redirect()->route('code-reviews.edit', $next)->with('success', 'Puan kaydedildi. Sıradaki çözüm açıldı.')
            : redirect()->route('code-reviews.index')->with('success', 'Puan kaydedildi. Kuyrukta bekleyen çözüm kalmadı.');
    }

    private function ensureCoding(TaskAttempt $attempt): void
    {
        abort_unless($attempt->submitted_at !== null && $attempt->task->kind->isCoding(), 404);
    }
}
