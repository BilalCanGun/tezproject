<?php

namespace App\Enums;

enum ProtocolPhase: string
{
    case Pilot = 'pilot';
    case Main = 'main';

    public function label(): string
    {
        return match ($this) {
            self::Pilot => 'Pilot',
            self::Main => 'Ana çalışma',
        };
    }

    public function participantCodePrefix(): string
    {
        return match ($this) {
            self::Pilot => 'PL',
            self::Main => 'P',
        };
    }
}
