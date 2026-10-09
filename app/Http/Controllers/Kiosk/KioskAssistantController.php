<?php

namespace App\Http\Controllers\Kiosk;

use App\Models\TaskAttempt;
use App\Services\Assistance\AssistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class KioskAssistantController extends KioskApiController
{
    public function __invoke(Request $request, TaskAttempt $attempt, AssistanceService $assistance): JsonResponse
    {
        $attempt = $this->ownedAttempt($this->kioskSession($request), $attempt);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'code' => ['nullable', 'string', 'max:50000'],
            'last_output' => ['nullable', 'string', 'max:8000'],
        ]);

        try {
            $reply = $assistance->ask($attempt, $validated['message'], $validated['code'] ?? null, $validated['last_output'] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'id' => $reply->id,
            'role' => $reply->role,
            'content' => $reply->content,
            'failed' => $reply->error !== null,
        ]);
    }
}
