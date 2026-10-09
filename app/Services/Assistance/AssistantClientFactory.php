<?php

namespace App\Services\Assistance;

use Anthropic\Client;
use App\Enums\AssistantProvider;
use App\Services\Assistance\Clients\AnthropicAssistantClient;
use App\Services\Assistance\Clients\OpenAiAssistantClient;
use App\Services\Assistance\Clients\ScriptedAssistantClient;

class AssistantClientFactory
{
    public function make(AssistantProvider $provider): AssistantClient
    {
        return match ($provider) {
            AssistantProvider::Scripted => new ScriptedAssistantClient,
            AssistantProvider::Anthropic => new AnthropicAssistantClient(new Client(
                apiKey: config('experiment.providers.anthropic.api_key'),
                requestOptions: ['timeout' => (float) config('experiment.providers.timeout_seconds'), 'maxRetries' => 1],
            )),
            AssistantProvider::OpenAi => new OpenAiAssistantClient,
        };
    }
}
