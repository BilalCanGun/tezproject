<?php

namespace App\Enums;

enum StepType: string
{
    case Instructions = 'instructions';
    case RestingBaseline = 'resting_baseline';
    case SyncGesture = 'sync_gesture';
    case Task = 'task';
    case Break = 'break';
    case Closing = 'closing';

    public function label(): string
    {
        return match ($this) {
            self::Instructions => 'Yönerge',
            self::RestingBaseline => 'Dinlenim baseline kaydı',
            self::SyncGesture => 'Bileklik senkron hareketi',
            self::Task => 'Görev',
            self::Break => 'Ara',
            self::Closing => 'Kapanış',
        };
    }
}
