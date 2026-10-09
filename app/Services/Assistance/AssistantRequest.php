<?php

namespace App\Services\Assistance;

use App\Enums\AssistanceCondition;

final readonly class AssistantRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{provider: string, model: ?string, temperature: ?float, effort: ?string, max_tokens: int}  $settings
     */
    public function __construct(
        public AssistanceCondition $condition,
        public string $system,
        public array $messages,
        public array $settings,
        public ?int $hintLevel = null,
        public ?string $referenceSolution = null,
    ) {}

    /**
     * Generation parameters as they are stored with every reply, for the
     * methods section and manipulation-check reporting.
     *
     * @return array<string, mixed>
     */
    public function auditParams(): array
    {
        return [
            'model' => $this->settings['model'],
            'temperature' => $this->settings['temperature'],
            'effort' => $this->settings['effort'],
            'max_tokens' => $this->settings['max_tokens'],
            'history_messages' => count($this->messages) - 1,
        ];
    }
}
