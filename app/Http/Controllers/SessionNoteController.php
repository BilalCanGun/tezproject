<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\ExperimentSession;
use App\Services\Sessions\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Timestamped researcher annotations (movement, talking, sneeze, device
 * re-seating). They become LSL markers so artefact segments can be located
 * in the recording.
 */
class SessionNoteController extends Controller
{
    public const CATEGORIES = [
        'movement' => 'Baş/gövde hareketi',
        'speech' => 'Konuşma',
        'device' => 'Cihaz/sensör müdahalesi',
        'external' => 'Dış kesinti (ses, kapı vb.)',
        'participant_question' => 'Katılımcı soru sordu',
        'other' => 'Diğer',
    ];

    public function store(Request $request, ExperimentSession $session, EventRecorder $events): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'text' => ['nullable', 'string', 'max:500'],
        ]);

        $event = $events->recordServerEvent($session, EventType::TesterNote, [
            'category' => $validated['category'],
            'text' => $validated['text'] ?? null,
            'author_id' => $request->user()->id,
        ]);

        return response()->json(['sequence' => $event->sequence], 201);
    }
}
