<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Services\AuditLogger;
use App\Services\Randomization\ParticipantRandomizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ParticipantRandomizationController extends Controller
{
    public function store(Request $request, Participant $participant, ParticipantRandomizer $randomizer, AuditLogger $audit): RedirectResponse
    {
        try {
            $slot = $randomizer->assign($participant, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record('participant.randomized', $participant, [
            'stratum' => $slot->stratum->value,
            'slot_position' => $slot->position,
            'condition' => $slot->condition->value,
        ]);

        return back()->with('success', "{$participant->code} → {$slot->condition->label()} ({$slot->stratum->label()} tabaka).");
    }
}
