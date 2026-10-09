<?php

namespace Database\Factories;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use App\Models\DeviceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceProfile>
 */
class DeviceProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modality' => DeviceModality::Eeg,
            'name' => fake()->unique()->word().' EEG',
            'channel_count' => 32,
            'channel_labels' => ['Fz', 'FCz', 'Cz', 'Pz', 'P3', 'P4', 'PO3', 'PO4', 'O1', 'O2'],
            'signals' => ['eeg'],
            'sampling_rate_hz' => 500,
            'sync_method' => SyncMethod::Lsl,
            'raw_data_access' => true,
            'requires_subscription' => false,
            'specs_verified' => true,
            'is_active' => true,
        ];
    }

    public function eightChannel(): static
    {
        return $this->state(fn () => [
            'channel_count' => 8,
            'channel_labels' => ['Fz', 'C3', 'Cz', 'C4', 'Pz', 'PO7', 'Oz', 'PO8'],
            'sampling_rate_hz' => 250,
        ]);
    }
}
