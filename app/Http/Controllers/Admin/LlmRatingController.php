<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreExplanationWithLlm;
use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Services\AuditLogger;
use App\Services\Scoring\RatingQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LlmRatingController extends Controller
{
    public function store(Request $request, RatingQueue $queue, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['protocol_id' => ['required', 'integer', 'exists:protocol_versions,id']]);
        $protocol = ProtocolVersion::findOrFail($validated['protocol_id']);

        $attempts = $queue->explanationAttempts($protocol)
            ->whereDoesntHave('ratings', fn ($query) => $query->where('rater_type', Rating::TYPE_LLM))
            ->get();

        $attempts->each(fn ($attempt) => ScoreExplanationWithLlm::dispatch($attempt));

        $audit->record('ratings.llm_dispatched', $protocol, ['count' => $attempts->count()]);

        return back()->with('success', "{$attempts->count()} açıklama LLM puanlama kuyruğuna eklendi. Kuyruk işçisi çalışıyor olmalı (php artisan queue:work).");
    }
}
