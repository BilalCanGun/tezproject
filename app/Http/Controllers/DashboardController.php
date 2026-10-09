<?php

namespace App\Http\Controllers;

use App\Enums\QualityVerdict;
use App\Models\ProtocolVersion;
use App\Models\QualityReview;
use App\Services\Analytics\StudyProgress;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(StudyProgress $progress): Response
    {
        $protocol = ProtocolVersion::active()->first();

        return Inertia::render('Dashboard', [
            'protocol' => $protocol?->only(['id', 'code', 'name']),
            'summary' => $protocol ? $progress->summary($protocol) : null,
            'delayedWindow' => $protocol ? $progress->delayedSessionWindow($protocol) : [],
            'upcoming' => $progress->upcomingSessions(),
            'qualityFlags' => QualityReview::query()
                ->where(fn ($query) => $query->where('needs_repeat', true)->orWhere('verdict', QualityVerdict::Unusable))
                ->with('session.participant:id,code')
                ->latest()
                ->take(8)
                ->get()
                ->map(fn (QualityReview $review) => [
                    'session_id' => $review->experiment_session_id,
                    'participant_code' => $review->session->participant->code,
                    'session_label' => $review->session->kind()->label(),
                    'verdict' => $review->verdict->label(),
                    'needs_repeat' => $review->needs_repeat,
                ]),
        ]);
    }
}
