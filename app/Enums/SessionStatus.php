<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Preparing = 'preparing';
    case Running = 'running';
    case Completed = 'completed';
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Planlandı',
            self::Preparing => 'Hazırlık',
            self::Running => 'Sürüyor',
            self::Completed => 'Tamamlandı',
            self::Aborted => 'Yarıda kesildi',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Aborted;
    }
}
