<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class ParticipantContactController extends Controller
{
    /**
     * Irreversibly delete identifying contact data. Research data keyed by the
     * participant code is kept.
     */
    public function destroy(Participant $participant, AuditLogger $audit): RedirectResponse
    {
        $participant->contact()->delete();
        $audit->record('participant.contact_purged', $participant);

        return back()->with('success', "{$participant->code} için kişisel iletişim verileri silindi.");
    }
}
