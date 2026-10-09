<?php

namespace App\Services\Devices;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use App\Models\DeviceProfile;

/**
 * Checks a device profile against the protocol's measurement requirements and
 * derives which analyses the method section can defend with that hardware.
 */
class DeviceSuitability
{
    private const MIDLINE = ['Fz', 'FCz', 'Cz', 'Pz'];

    /**
     * @return array{checks: list<array{label: string, requirement: string, value: string, status: string}>, analyses: list<array{name: string, allowed: bool, reason: string}>, sync_precision: string}
     */
    public function evaluate(DeviceProfile $device): array
    {
        $checks = match ($device->modality) {
            DeviceModality::Eeg => $this->eegChecks($device),
            DeviceModality::EyeTracker => $this->eyeTrackerChecks($device),
            DeviceModality::Wearable => $this->wearableChecks($device),
        };

        $checks[] = [
            'label' => 'Teknik özellikler proforma ile doğrulandı',
            'requirement' => 'Satın alma öncesi yazılı teyit',
            'value' => $device->specs_verified ? 'Evet' : 'Hayır',
            'status' => $device->specs_verified ? 'ok' : 'warn',
        ];

        return [
            'checks' => $checks,
            'analyses' => $device->modality === DeviceModality::Eeg ? $this->eegAnalyses($device) : [],
            'sync_precision' => $device->sync_method->expectedPrecision(),
        ];
    }

    /**
     * @return list<array{label: string, requirement: string, value: string, status: string}>
     */
    private function eegChecks(DeviceProfile $device): array
    {
        $labels = $device->channel_labels ?? [];
        $midline = array_values(array_intersect(self::MIDLINE, $labels));
        $parietoOccipital = array_values(array_filter($labels, fn (string $label) => preg_match('/^(P|PO|O)\d*$/i', $label) === 1 || preg_match('/^(P|PO|O)z$/i', $label) === 1));
        $channels = (int) $device->channel_count;

        return [
            [
                'label' => 'Kanal sayısı',
                'requirement' => '≥16 (8 kanal yalnızca önceden tanımlı ROI analizi için)',
                'value' => (string) $channels,
                'status' => $channels >= 16 ? 'ok' : ($channels >= 8 ? 'warn' : 'fail'),
            ],
            [
                'label' => 'Örnekleme hızı',
                'requirement' => '≥250 Hz',
                'value' => $device->sampling_rate_hz ? "{$device->sampling_rate_hz} Hz" : 'Belirtilmemiş',
                'status' => $device->sampling_rate_hz >= 250 ? 'ok' : 'fail',
            ],
            [
                'label' => 'Orta hat kapsamı (Fz, FCz, Cz, Pz)',
                'requirement' => 'Frontal orta hat theta için en az Fz veya FCz',
                'value' => $midline ? implode(', ', $midline) : 'Yok',
                'status' => array_intersect(['Fz', 'FCz'], $midline) ? (count($midline) >= 3 ? 'ok' : 'warn') : 'fail',
            ],
            [
                'label' => 'Paryetal-oksipital kapsam',
                'requirement' => 'Alpha gücü/ERD için ≥2 P/PO/O kanalı',
                'value' => $parietoOccipital ? implode(', ', $parietoOccipital) : 'Yok',
                'status' => count($parietoOccipital) >= 2 ? 'ok' : 'fail',
            ],
            $this->rawAccessCheck($device),
            $this->syncCheck($device, true),
        ];
    }

    /**
     * @return list<array{label: string, requirement: string, value: string, status: string}>
     */
    private function eyeTrackerChecks(DeviceProfile $device): array
    {
        $signals = $device->signals ?? [];

        return [
            [
                'label' => 'Örnekleme hızı',
                'requirement' => '≥120 Hz',
                'value' => $device->sampling_rate_hz ? "{$device->sampling_rate_hz} Hz" : 'Belirtilmemiş',
                'status' => $device->sampling_rate_hz >= 120 ? 'ok' : 'fail',
            ],
            [
                'label' => 'Pupil çapı ölçümü',
                'requirement' => 'Ham pupil çapı (mm veya piksel)',
                'value' => in_array('pupil', $signals, true) ? 'Var' : 'Yok',
                'status' => in_array('pupil', $signals, true) ? 'ok' : 'fail',
            ],
            [
                'label' => 'Ham bakış koordinatları',
                'requirement' => 'Ekran koordinatında ham gaze',
                'value' => in_array('gaze', $signals, true) ? 'Var' : 'Yok',
                'status' => in_array('gaze', $signals, true) ? 'ok' : 'fail',
            ],
            $this->rawAccessCheck($device),
            $this->syncCheck($device, true),
        ];
    }

    /**
     * @return list<array{label: string, requirement: string, value: string, status: string}>
     */
    private function wearableChecks(DeviceProfile $device): array
    {
        $signals = $device->signals ?? [];
        $required = ['eda', 'bvp', 'acc', 'temp'];
        $missing = array_diff($required, $signals);

        return [
            [
                'label' => 'Sinyaller',
                'requirement' => 'EDA, BVP/PPG, ivme, cilt sıcaklığı',
                'value' => strtoupper(implode(', ', $signals)) ?: 'Belirtilmemiş',
                'status' => $missing ? 'fail' : 'ok',
            ],
            $this->rawAccessCheck($device),
            $this->syncCheck($device, false),
        ];
    }

    /**
     * @return array{label: string, requirement: string, value: string, status: string}
     */
    private function rawAccessCheck(DeviceProfile $device): array
    {
        $value = $device->raw_data_access ? 'Var' : 'Yok';

        if ($device->raw_data_access && $device->requires_subscription) {
            $value .= ' (ücretli lisans/abonelik gerekli)';
        }

        return [
            'label' => 'Ham veri erişimi',
            'requirement' => 'Analiz hattına ham veri aktarımı',
            'value' => $value,
            'status' => ! $device->raw_data_access ? 'fail' : ($device->requires_subscription ? 'warn' : 'ok'),
        ];
    }

    /**
     * @return array{label: string, requirement: string, value: string, status: string}
     */
    private function syncCheck(DeviceProfile $device, bool $needsEventLocking): array
    {
        $status = match ($device->sync_method) {
            SyncMethod::Lsl => 'ok',
            SyncMethod::Sdk => 'warn',
            SyncMethod::UtcPosthoc => $needsEventLocking ? 'fail' : 'warn',
        };

        return [
            'label' => 'Senkronizasyon',
            'requirement' => $needsEventLocking ? 'LSL veya harici marker (olay kilitli analiz)' : 'Güvenilir UTC zaman damgası (blok düzeyi analiz)',
            'value' => $device->sync_method->label(),
            'status' => $status,
        ];
    }

    /**
     * @return list<array{name: string, allowed: bool, reason: string}>
     */
    private function eegAnalyses(DeviceProfile $device): array
    {
        $channels = (int) $device->channel_count;
        $dense = $channels >= 16;

        return [
            ['name' => 'Önceden tanımlı ROI bant gücü (frontal theta, paryetal-oksipital alpha)', 'allowed' => $channels >= 8, 'reason' => 'Frontal ve paryetal elektrotlar yeterli.'],
            ['name' => 'Alpha ERD/ERS (planlama penceresi, baseline’a göre)', 'allowed' => $channels >= 8, 'reason' => 'Olay kilitli epok ve baseline kaydı gerektirir.'],
            ['name' => 'ICA ile artefakt ayrıştırma', 'allowed' => $dense, 'reason' => $dense ? 'Kanal sayısı bileşen ayrıştırması için yeterli.' : 'Kanal sayısı ICA için yetersiz; eşik tabanlı reddetme kullanılmalı.'],
            ['name' => 'Geniş topografik analiz', 'allowed' => $dense, 'reason' => $dense ? 'Kafa yüzeyi kapsamı yeterli.' : 'Seyrek kapsam topografik yorumu desteklemez.'],
            ['name' => 'Uzamsal küme tabanlı permütasyon testi', 'allowed' => $dense, 'reason' => $dense ? 'Komşuluk matrisi kurulabilir.' : 'Komşuluk yapısı anlamlı değil.'],
            ['name' => 'Bağlantısallık analizi', 'allowed' => $dense && $device->raw_data_access, 'reason' => $dense ? 'Hacim iletimi sınırlamaları raporlanarak keşifsel yapılabilir.' : '8 kanalda savunulamaz.'],
        ];
    }
}
