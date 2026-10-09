<?php

namespace App\Http\Controllers\Kiosk;

use App\Enums\AssistanceCondition;
use App\Models\ResourceArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Static reference material for the standard-resource condition. The same
 * content for everyone; nothing here is generated for the current problem.
 */
class KioskResourceController extends KioskApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $session = $this->kioskSession($request);

        abort_unless($session->allowsAssistance() && $session->condition === AssistanceCondition::StandardResource, 403);

        $articles = $session->participant->protocolVersion->resources()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'section', 'title', 'body']);

        return response()->json([
            'sections' => ResourceArticle::SECTIONS,
            'articles' => $articles,
        ]);
    }
}
