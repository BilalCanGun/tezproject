<?php

namespace App\Http\Controllers\Kiosk;

use App\Models\SessionStep;
use App\Services\Sessions\SessionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class KioskStepController extends KioskApiController
{
    public function start(Request $request, SessionStep $step, SessionRunner $runner): JsonResponse
    {
        $session = $this->kioskSession($request);
        $this->ownedStep($session, $step);

        try {
            $step = $runner->startStep($session, $step);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['step_id' => $step->id, 'attempt_id' => $step->attempt?->id]);
    }

    public function complete(Request $request, SessionStep $step, SessionRunner $runner): JsonResponse
    {
        $session = $this->kioskSession($request);
        $this->ownedStep($session, $step);

        try {
            $runner->completeStep($session, $step);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['ok' => true]);
    }
}
