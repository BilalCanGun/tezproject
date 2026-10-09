<?php

namespace App\Http\Controllers;

use App\Models\ExperimentSession;
use App\Services\AuditLogger;
use App\Services\Sessions\KioskAccess;
use App\Services\Sessions\SessionRunner;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class KioskLinkController extends Controller
{
    /**
     * Prepare the session (freeze assistant settings, build the step plan) and
     * issue a fresh kiosk link. Any previously issued link stops working.
     */
    public function store(ExperimentSession $session, SessionRunner $runner, KioskAccess $access, AuditLogger $audit): RedirectResponse
    {
        try {
            $runner->prepare($session);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $token = $access->issue($session);
        $audit->record('kiosk.link_issued', $session);

        return back()
            ->with('kiosk_url', route('kiosk.show', $token))
            ->with('success', 'Katılımcı ekranı bağlantısı oluşturuldu. Bağlantı yalnızca bir kez gösterilir.');
    }

    public function destroy(ExperimentSession $session, KioskAccess $access, AuditLogger $audit): RedirectResponse
    {
        $access->revoke($session);
        $audit->record('kiosk.link_revoked', $session);

        return back()->with('success', 'Katılımcı ekranı bağlantısı iptal edildi.');
    }
}
