<?php

namespace App\Http\Controllers;

use App\Enums\QualityVerdict;
use App\Models\ExperimentSession;
use App\Models\SessionStep;
use App\Services\Sessions\QualityAssessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QualityReviewController extends Controller
{
    public function update(Request $request, ExperimentSession $session, QualityAssessor $assessor): RedirectResponse
    {
        $validated = $request->validate([
            'eeg_usable_epoch_ratio' => ['nullable', 'numeric', 'between:0,1'],
            'gaze_valid_ratio' => ['nullable', 'numeric', 'between:0,1'],
            'wearable_loss_ratio' => ['nullable', 'numeric', 'between:0,1'],
            'verdict' => ['nullable', Rule::enum(QualityVerdict::class)],
            'needs_repeat' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $markers = $session->events()->whereNotNull('marker_code');
        $assessment = $assessor->assess($session, $validated);

        $session->qualityReview()->updateOrCreate([], [
            ...$validated,
            'markers_expected' => (clone $markers)->count(),
            'markers_acknowledged' => (clone $markers)->whereNotNull('lsl_time')->count(),
            'all_steps_completed' => ! $session->steps()->where('status', '!=', SessionStep::STATUS_DONE)->exists(),
            'verdict' => $validated['verdict'] ?? $assessment['suggested_verdict'],
            'failed_checks' => $assessment['failed_checks'],
            'reviewed_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Veri kalite değerlendirmesi kaydedildi.');
    }
}
