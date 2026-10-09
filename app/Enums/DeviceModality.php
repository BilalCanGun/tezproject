<?php

namespace App\Enums;

enum DeviceModality: string
{
    case Eeg = 'eeg';
    case EyeTracker = 'eye_tracker';
    case Wearable = 'wearable';

    public function label(): string
    {
        return match ($this) {
            self::Eeg => 'EEG',
            self::EyeTracker => 'Göz izleyici',
            self::Wearable => 'Giyilebilir otonom sensör',
        };
    }
}
