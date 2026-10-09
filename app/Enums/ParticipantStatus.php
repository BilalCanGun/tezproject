<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Screening = 'screening';
    case Ineligible = 'ineligible';
    case Eligible = 'eligible';
    case Randomized = 'randomized';
    case FirstSessionDone = 'first_session_done';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Screening => 'Ön görüşme',
            self::Ineligible => 'Uygun değil',
            self::Eligible => 'Uygun, ön test bekliyor',
            self::Randomized => 'Atandı',
            self::FirstSessionDone => '1. oturum tamam',
            self::Completed => 'Tamamladı',
            self::Withdrawn => 'Çekildi',
            self::Excluded => 'Analiz dışı',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Ineligible, self::Withdrawn, self::Excluded, self::Completed], true);
    }
}
