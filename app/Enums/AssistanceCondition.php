<?php

namespace App\Enums;

enum AssistanceCondition: string
{
    case DirectAi = 'direct_ai';
    case SocraticAi = 'socratic_ai';
    case StandardResource = 'standard_resource';

    public function label(): string
    {
        return match ($this) {
            self::DirectAi => 'Doğrudan çözüm veren YZ',
            self::SocraticAi => 'Sokratik YZ',
            self::StandardResource => 'Standart kaynak',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::DirectAi => 'D-YZ',
            self::SocraticAi => 'S-YZ',
            self::StandardResource => 'SK',
        };
    }

    public function usesAssistant(): bool
    {
        return $this !== self::StandardResource;
    }
}
