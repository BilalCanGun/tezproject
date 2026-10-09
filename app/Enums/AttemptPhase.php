<?php

namespace App\Enums;

/**
 * EEG-compatible task phases. Planning is a silent, input-locked window that
 * yields movement-free epochs for frontal theta / parietal alpha analysis.
 */
enum AttemptPhase: string
{
    case Reading = 'reading';
    case Planning = 'planning';
    case Coding = 'coding';
    case Checking = 'checking';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Problemi okuma',
            self::Planning => 'Sessiz planlama',
            self::Coding => 'Kod yazma / çalıştırma',
            self::Checking => 'Çözümü kontrol',
            self::Submitted => 'Teslim edildi',
        };
    }

    /**
     * Phases a participant may move to from the current phase.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Reading => [self::Planning, self::Coding, self::Submitted],
            self::Planning => [self::Coding, self::Submitted],
            self::Coding => [self::Checking, self::Submitted],
            self::Checking => [self::Coding, self::Submitted],
            self::Submitted => [],
        };
    }
}
