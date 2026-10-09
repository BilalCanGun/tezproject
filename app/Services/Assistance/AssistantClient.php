<?php

namespace App\Services\Assistance;

interface AssistantClient
{
    /**
     * @throws AssistantUnavailable when the provider cannot produce a reply
     */
    public function complete(AssistantRequest $request): AssistantReply;
}
