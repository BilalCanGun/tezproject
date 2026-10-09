<?php

namespace App\Http\Controllers;

use App\Enums\ParticipantStatus;
use App\Models\Participant;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Withdrawal (participant's decision) and exclusion (pre-registered criterion)
 * are recorded separately because they are reported separately in the CONSORT
 * style flow diagram. Neither deletes collected data.
 */
class ParticipantExclusionController extends Controller
{
    public function store(Request $request, Participant $participant, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([ParticipantStatus::Withdrawn->value, ParticipantStatus::Excluded->value])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $status = ParticipantStatus::from($validated['status']);

        $participant->forceFill([
            'status' => $status,
            'exclusion_reason' => $validated['reason'],
            'withdrawn_at' => $status === ParticipantStatus::Withdrawn ? now() : $participant->withdrawn_at,
        ])->save();

        $audit->record("participant.{$status->value}", $participant, ['reason' => $validated['reason']]);

        return back()->with('success', "{$participant->code}: {$status->label()} olarak işaretlendi.");
    }
}
