<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\ExperimentSession;
use App\Services\AuditLogger;
use App\Services\Sessions\EventRecorder;
use App\Services\Sessions\KioskAccess;
use App\Services\Sessions\SessionRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionControlController extends Controller
{
    public function pause(ExperimentSession $session, SessionRunner $runner, EventRecorder $events): RedirectResponse
    {
        $runner->setPaused($session, true);
        $events->recordServerEvent($session, EventType::SessionPaused);

        return back()->with('success', 'Oturum duraklatıldı. Katılımcı ekranı bekleme moduna geçti.');
    }

    public function resume(ExperimentSession $session, SessionRunner $runner, EventRecorder $events): RedirectResponse
    {
        $runner->setPaused($session, false);
        $events->recordServerEvent($session, EventType::SessionResumed);

        return back()->with('success', 'Oturum devam ediyor.');
    }

    public function abort(Request $request, ExperimentSession $session, SessionRunner $runner, KioskAccess $access, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $runner->abort($session, $validated['reason']);
        $access->revoke($session);
        $audit->record('session.aborted', $session, ['reason' => $validated['reason']]);

        return back()->with('success', 'Oturum sonlandırıldı.');
    }
}
