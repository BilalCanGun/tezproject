<?php

namespace App\Services\Assistance;

final readonly class AssistantReply
{
    public function __construct(
        public string $text,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $stopReason = null,
        public int $latencyMs = 0,
    ) {}
}
