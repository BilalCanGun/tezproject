<?php

namespace App\Enums;

enum AssistantProvider: string
{
    case Scripted = 'scripted';
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::Scripted => 'Simülasyon (kuru prova, API yok)',
            self::Anthropic => 'Anthropic Claude',
            self::OpenAi => 'OpenAI',
        };
    }

    /**
     * Current Claude models reject sampling parameters; generation is pinned
     * through the effort level instead of temperature.
     */
    public function supportsTemperature(): bool
    {
        return $this === self::OpenAi;
    }
}
