<?php

namespace App\Services\Assistance\Clients;

use Anthropic\Client;
use App\Services\Assistance\AssistantClient;
use App\Services\Assistance\AssistantReply;
use App\Services\Assistance\AssistantRequest;
use App\Services\Assistance\AssistantUnavailable;
use Throwable;

/**
 * Claude via the official SDK. Current Claude models do not accept sampling
 * parameters, so generation is pinned with the effort level instead of
 * temperature. Server-side refusal fallbacks are deliberately not enabled:
 * a silent switch to another model would contaminate the experimental
 * manipulation, so refusals are stored as-is and show up in the reports.
 */
class AnthropicAssistantClient implements AssistantClient
{
    public function __construct(private Client $client) {}

    public function complete(AssistantRequest $request): AssistantReply
    {
        $startedAt = hrtime(true);

        try {
            $message = $this->client->messages->create(
                maxTokens: $request->settings['max_tokens'],
                messages: $request->messages,
                model: $request->settings['model'] ?: 'claude-opus-5-5',
                system: $request->system,
                outputConfig: $request->settings['effort'] ? ['effort' => $request->settings['effort']] : null,
            );
        } catch (Throwable $exception) {
            throw new AssistantUnavailable($exception->getMessage(), previous: $exception);
        }

        $text = collect($message->content)
            ->filter(fn ($block) => $block->type === 'text')
            ->map(fn ($block) => $block->text)
            ->implode("\n\n");

        return new AssistantReply(
            text: $message->stopReason === 'refusal' && $text === '' ? 'Bu isteğe yanıt veremiyorum.' : $text,
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            stopReason: $message->stopReason,
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );
    }
}
