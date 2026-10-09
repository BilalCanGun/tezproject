<?php

namespace App\Enums;

/**
 * Session numbers used across the study. Session 0 is the short pre-assessment
 * that runs before any sensor is attached; it feeds stratified randomisation.
 */
enum SessionKind: int
{
    case Baseline = 0;
    case Learning = 1;
    case Delayed = 2;

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Ön değerlendirme',
            self::Learning => '1. Oturum · Öğrenme',
            self::Delayed => '2. Oturum · Gecikmeli',
        };
    }

    public function recordsPhysiology(): bool
    {
        return $this !== self::Baseline;
    }
}
