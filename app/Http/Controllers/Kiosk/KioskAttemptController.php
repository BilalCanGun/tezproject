<?php

namespace App\Http\Controllers\Kiosk;

use App\Enums\AttemptPhase;
use App\Models\TaskAttempt;
use App\Services\Sessions\SessionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class KioskAttemptController extends KioskApiController
{
    public function phase(Request $request, TaskAttempt $attempt, SessionRunner $runner): JsonResponse
    {
        $attempt = $this->ownedAttempt($this->kioskSession($request), $attempt);

        $validated = $request->validate([
            'phase' => ['required', Rule::in([AttemptPhase::Planning->value, AttemptPhase::Coding->value, AttemptPhase::Checking->value])],
        ]);

        try {
            $attempt = $runner->advancePhase($attempt, AttemptPhase::from($validated['phase']));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['phase' => $attempt->phase->value]);
    }

    public function submit(Request $request, TaskAttempt $attempt, SessionRunner $runner): JsonResponse
    {
        $attempt = $this->ownedAttempt($this->kioskSession($request), $attempt);

        $validated = $request->validate([
            'end_reason' => ['required', Rule::in([TaskAttempt::END_SUBMITTED, TaskAttempt::END_TIMEOUT, TaskAttempt::END_GAVE_UP])],
            'code' => ['nullable', 'string', 'max:50000'],
            'tests_passed' => ['nullable', 'integer', 'min:0', 'lte:tests_total'],
            'tests_total' => ['nullable', 'integer', 'min:0', 'max:200'],
            'mcq_answer' => ['nullable', 'string', 'max:20'],
            'explanation' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            $runner->submit($attempt, $validated);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['ok' => true]);
    }
}
