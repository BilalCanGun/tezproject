<?php

namespace Database\Seeders;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use App\Models\DeviceProfile;
use Illuminate\Database\Seeder;

/**
 * Candidate devices from the budget discussion. Specifications are taken from
 * public vendor material and marked unverified until confirmed in writing on
 * the pro forma invoice.
 */
class DeviceProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            [
                'modality' => DeviceModality::Eeg,
                'name' => 'g.tec Unicorn Hybrid Black',
                'manufacturer' => 'g.tec medical engineering',
                'model' => 'Unicorn Hybrid Black',
                'channel_count' => 8,
                'channel_labels' => ['Fz', 'C3', 'Cz', 'C4', 'Pz', 'PO7', 'Oz', 'PO8'],
                'signals' => ['eeg', 'acc', 'gyro'],
                'sampling_rate_hz' => 250,
                'electrode_type' => 'dry',
                'sync_method' => SyncMethod::Lsl,
                'raw_data_access' => true,
                'requires_subscription' => false,
                'license_notes' => 'Tek seferlik satın alma; Unicorn Suite LSL arayüzü ile akış.',
                'notes' => 'Hibrit elektrot (kuru veya jelli kullanılabilir). 8 kanal: ICA, topografi, küme permütasyonu ve bağlantısallık savunulamaz; yalnızca önceden tanımlı Fz/Cz ve Pz/PO7/PO8/Oz ROI analizleri.',
            ],
            [
                'modality' => DeviceModality::Eeg,
                'name' => 'Emotiv EPOC Flex Saline',
                'manufacturer' => 'Emotiv',
                'model' => 'EPOC Flex Saline (32 kanal)',
                'channel_count' => 32,
                'channel_labels' => ['Fp1', 'Fp2', 'AF3', 'AF4', 'F7', 'F3', 'Fz', 'F4', 'F8', 'FC5', 'FC1', 'FCz', 'FC2', 'FC6', 'T7', 'C3', 'Cz', 'C4', 'T8', 'CP5', 'CP1', 'CP2', 'CP6', 'P7', 'P3', 'Pz', 'P4', 'P8', 'PO3', 'PO4', 'O1', 'O2'],
                'signals' => ['eeg', 'acc'],
                'sampling_rate_hz' => 128,
                'electrode_type' => 'saline',
                'sync_method' => SyncMethod::Lsl,
                'raw_data_access' => true,
                'requires_subscription' => true,
                'license_notes' => 'Ham veri ve LSL çıkışı EmotivPRO aboneliği gerektiriyor; yıllık ücret bütçeye proje süresi boyunca eklenmeli.',
                'notes' => 'Kanal yerleşimi başlıkta seçilebilir; buradaki dizilim önerilen 10-20 alt kümesidir. 128 Hz, başvurudaki ≥250 Hz ölçütünü karşılamıyor. Theta (4–8 Hz) ve alpha (8–13 Hz) için 128 Hz teknik olarak yeterli; ölçüt ya revize edilmeli ya da daha yüksek örneklemeli bir sistem seçilmeli.',
            ],
            [
                'modality' => DeviceModality::Eeg,
                'name' => 'Emotiv EPOC X',
                'manufacturer' => 'Emotiv',
                'model' => 'EPOC X (14 kanal)',
                'channel_count' => 14,
                'channel_labels' => ['AF3', 'F7', 'F3', 'FC5', 'T7', 'P7', 'O1', 'O2', 'P8', 'T8', 'FC6', 'F4', 'F8', 'AF4'],
                'signals' => ['eeg', 'acc'],
                'sampling_rate_hz' => 128,
                'electrode_type' => 'saline',
                'sync_method' => SyncMethod::Lsl,
                'raw_data_access' => true,
                'requires_subscription' => true,
                'license_notes' => 'Ham veri için EmotivPRO aboneliği gerekiyor.',
                'notes' => 'Orta hat elektrotu (Fz, FCz, Cz, Pz) yok: frontal orta hat theta doğrudan ölçülemez. Bu çalışmanın birincil EEG ölçümü için uygun değil.',
            ],
            [
                'modality' => DeviceModality::EyeTracker,
                'name' => 'Göz izleyici (genel profil, ≥120 Hz)',
                'manufacturer' => null,
                'model' => 'Belirlenecek',
                'signals' => ['gaze', 'pupil'],
                'sampling_rate_hz' => 120,
                'sync_method' => SyncMethod::Lsl,
                'raw_data_access' => true,
                'requires_subscription' => false,
                'license_notes' => 'Satın alınacak modelin SDK/LSL desteği ve analiz yazılımı lisansı proforma aşamasında yazılı doğrulanmalı.',
                'notes' => 'Ekran tabanlı, çene desteği önerilir. AOI koordinatları deney arayüzünden otomatik kaydediliyor (aoi_layout olayları).',
            ],
            [
                'modality' => DeviceModality::Wearable,
                'name' => 'Empatica EmbracePlus',
                'manufacturer' => 'Empatica',
                'model' => 'EmbracePlus (Academic)',
                'signals' => ['eda', 'bvp', 'acc', 'temp'],
                'sampling_rate_hz' => null,
                'sync_method' => SyncMethod::UtcPosthoc,
                'raw_data_access' => true,
                'requires_subscription' => true,
                'license_notes' => 'Cihaz $1.166,40 + yazılım paketi (3 yıl $1.749,60 / 5 yıl $2.187,00). Akademik indirim (%25) teyit edilecek; gümrük ve KDV ayrıca hesaplanmalı.',
                'notes' => 'Canlı LSL akışı yok; veriler UTC zaman damgalı dosyalarla sonradan hizalanır. Sinyal bazında örnekleme oranları üretici dokümanından doğrulanmalı. Kısa olaylara kilitli analiz yapılmamalı; görev/blok pencereleri kullanılmalı. Senkron doğrulaması için oturum başında ve sonunda bilek vuruşu (sync_gesture) markerı alınır.',
            ],
        ];

        foreach ($profiles as $profile) {
            DeviceProfile::updateOrCreate(['name' => $profile['name']], [...$profile, 'specs_verified' => false, 'is_active' => true]);
        }
    }
}
