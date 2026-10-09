<?php

namespace App\Models;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use Database\Factories\DeviceProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'modality', 'name', 'manufacturer', 'model', 'channel_count', 'channel_labels', 'signals',
    'sampling_rate_hz', 'electrode_type', 'sync_method', 'raw_data_access', 'requires_subscription',
    'license_notes', 'specs_verified', 'notes', 'is_active',
])]
class DeviceProfile extends Model
{
    /** @use HasFactory<DeviceProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modality' => DeviceModality::class,
            'sync_method' => SyncMethod::class,
            'channel_labels' => 'array',
            'signals' => 'array',
            'sampling_rate_hz' => 'float',
            'raw_data_access' => 'boolean',
            'requires_subscription' => 'boolean',
            'specs_verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SessionDevice, $this>
     */
    public function sessionDevices(): HasMany
    {
        return $this->hasMany(SessionDevice::class);
    }
}
