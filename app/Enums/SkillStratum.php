<?php

namespace App\Enums;

enum SkillStratum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Düşük',
            self::Medium => 'Orta',
            self::High => 'Yüksek',
        };
    }

    /**
     * Resolve a stratum from a baseline percentage using the protocol cut-offs.
     */
    public static function fromPercentage(float $percentage, float $lowUpperBound, float $mediumUpperBound): self
    {
        if ($percentage < $lowUpperBound) {
            return self::Low;
        }

        if ($percentage < $mediumUpperBound) {
            return self::Medium;
        }

        return self::High;
    }
}
