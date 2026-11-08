<?php

namespace App\Console\Commands;

use App\Models\DeviceProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the active device profiles to the analysis folder so the Python
 * device validation emulates exactly the hardware listed in the admin panel.
 */
#[Signature('devices:export-profiles')]
#[Description('Aktif cihaz profillerini Python cihaz doğrulama hattı için JSON olarak dışa aktarır')]
class ExportDeviceProfiles extends Command
{
    public const PATH = 'analysis/device_profiles.json';

    public function handle(): int
    {
        $profiles = DeviceProfile::query()
            ->where('is_active', true)
            ->orderBy('modality')
            ->orderBy('name')
            ->get()
            ->map(fn (DeviceProfile $device) => [
                'name' => $device->name,
                'modality' => $device->modality->value,
                'sampling_rate_hz' => $device->sampling_rate_hz,
                'channel_labels' => $device->channel_labels ?? [],
                'signals' => $device->signals ?? [],
                'sync_method' => $device->sync_method->value,
                'specs_verified' => $device->specs_verified,
            ])
            ->values();

        Storage::disk('local')->put(self::PATH, $profiles->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("{$profiles->count()} cihaz profili yazıldı: storage/app/private/".self::PATH);

        return self::SUCCESS;
    }
}
