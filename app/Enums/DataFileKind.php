<?php

namespace App\Enums;

enum DataFileKind: string
{
    case Xdf = 'xdf';
    case EegRaw = 'eeg_raw';
    case GazeRaw = 'gaze_raw';
    case WearableExport = 'wearable_export';
    case BridgeMarkerLog = 'bridge_marker_log';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Xdf => 'LabRecorder XDF',
            self::EegRaw => 'EEG ham dosya (üretici formatı)',
            self::GazeRaw => 'Göz izleme ham dosya',
            self::WearableExport => 'Bileklik dışa aktarımı (UTC)',
            self::BridgeMarkerLog => 'LSL köprüsü marker yedeği (CSV)',
            self::Other => 'Diğer',
        };
    }
}
