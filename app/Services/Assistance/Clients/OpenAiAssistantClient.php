<?php

namespace App\Services\Assistance\Clients;

use App\Services\Assistance\AssistantClient;
use App\Services\Assistance\AssistantReply;
use App\Services\Assistance\AssistantRequest;
use App\Services\Assistance\AssistantUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat completions over HTTP. Kept intentionally thin: the experiment
 * only needs a single non-streaming completion per participant request.
 */
class OpenAiAssistantClient implements AssistantClient
{
    public function complete(AssistantRequest $request): AssistantReply
    {
        $startedAt = hrtime(true);

        if (! $request->settings['model']) {
            throw new AssistantUnavailable('OpenAI sağlayıcısı için protokolde model adı tanımlanmamış.');
        }

        try {
            $response = Http::baseUrl(config('experiment.providers.openai.base_url'))
                ->withToken(config('experiment.providers.openai.api_key'))
                ->connectTimeout(5)
                ->timeout(config('experiment.providers.timeout_seconds'))
                ->post('/chat/completions', array_filter([
                    'model' => $request->settings['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $request->system],
                        ...$request->messages,
                    ],
                    'temperature' => $request->settings['temperature'],
                    'max_completion_tokens' => $request->settings['max_tokens'],
                ], fn ($value) => $value !== null))
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $exception) {
            throw new AssistantUnavailable($exception->getMessage(), previous: $exception);
        }

        return new AssistantReply(
            text: (string) data_get($response, 'choices.0.message.content', ''),
            model: data_get($response, 'model'),
            inputTokens: data_get($response, 'usage.prompt_tokens'),
            outputTokens: data_get($response, 'usage.completion_tokens'),
            stopReason: data_get($response, 'choices.0.finish_reason'),
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );
    }
}
