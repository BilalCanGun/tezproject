<?php

namespace App\Http\Requests;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modality' => ['required', Rule::enum(DeviceModality::class)],
            'name' => ['required', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'channel_count' => ['nullable', 'integer', 'between:1,512'],
            'channel_labels' => ['nullable', 'array', 'max:512'],
            'channel_labels.*' => ['string', 'max:12'],
            'signals' => ['nullable', 'array'],
            'signals.*' => ['string', Rule::in(['eeg', 'gaze', 'pupil', 'eda', 'bvp', 'acc', 'temp', 'gyro', 'hr'])],
            'sampling_rate_hz' => ['nullable', 'numeric', 'between:0.1,100000'],
            'electrode_type' => ['nullable', Rule::in(['dry', 'saline', 'gel', 'semi_dry'])],
            'sync_method' => ['required', Rule::enum(SyncMethod::class)],
            'raw_data_access' => ['boolean'],
            'requires_subscription' => ['boolean'],
            'license_notes' => ['nullable', 'string', 'max:2000'],
            'specs_verified' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['boolean'],
        ];
    }
}
