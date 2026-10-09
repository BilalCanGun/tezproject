<?php

namespace App\Enums;

enum QualityVerdict: string
{
    case Usable = 'usable';
    case Partial = 'partial';
    case Unusable = 'unusable';

    public function label(): string
    {
        return match ($this) {
            self::Usable => 'Kullanılabilir',
            self::Partial => 'Kısmen kullanılabilir',
            self::Unusable => 'Kullanılamaz',
        };
    }
}
