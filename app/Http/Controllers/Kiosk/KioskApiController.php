<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ExperimentSession;
use App\Models\SessionStep;
use App\Models\TaskAttempt;
use Illuminate\Http\Request;

abstract class KioskApiController extends Controller
{
    protected function kioskSession(Request $request): ExperimentSession
    {
        return $request->attributes->get('kioskSession');
    }

    protected function ownedStep(ExperimentSession $session, SessionStep $step): SessionStep
    {
        abort_unless($step->experiment_session_id === $session->id, 404);

        return $step;
    }

    protected function ownedAttempt(ExperimentSession $session, TaskAttempt $attempt): TaskAttempt
    {
        abort_unless($attempt->experiment_session_id === $session->id, 404);

        return $attempt->setRelation('session', $session);
    }
}
