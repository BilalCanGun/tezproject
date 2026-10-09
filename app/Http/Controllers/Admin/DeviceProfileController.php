<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceModality;
use App\Enums\SyncMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceProfileRequest;
use App\Models\DeviceProfile;
use App\Services\Devices\DeviceSuitability;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DeviceProfileController extends Controller
{
    public function index(DeviceSuitability $suitability): Response
    {
        return Inertia::render('Admin/Devices/Index', [
            'devices' => DeviceProfile::query()
                ->withCount('sessionDevices')
                ->orderBy('modality')
                ->orderBy('name')
                ->get()
                ->map(fn (DeviceProfile $device) => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'manufacturer' => $device->manufacturer,
                    'modality' => $device->modality->value,
                    'modality_label' => $device->modality->label(),
                    'channel_count' => $device->channel_count,
                    'sampling_rate_hz' => $device->sampling_rate_hz,
                    'sync_label' => $device->sync_method->label(),
                    'is_active' => $device->is_active,
                    'specs_verified' => $device->specs_verified,
                    'sessions_count' => $device->session_devices_count,
                    'license_notes' => $device->license_notes,
                    'suitability' => $suitability->evaluate($device),
                ])
                ->groupBy('modality'),
            'modalities' => collect(DeviceModality::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Devices/Form', ['device' => null, ...$this->options()]);
    }

    public function store(DeviceProfileRequest $request): RedirectResponse
    {
        DeviceProfile::create($request->validated());

        return redirect()->route('admin.devices.index')->with('success', 'Cihaz profili eklendi.');
    }

    public function edit(DeviceProfile $deviceProfile): Response
    {
        return Inertia::render('Admin/Devices/Form', [
            'device' => [
                ...$deviceProfile->toArray(),
                'modality' => $deviceProfile->modality->value,
                'sync_method' => $deviceProfile->sync_method->value,
            ],
            ...$this->options(),
        ]);
    }

    public function update(DeviceProfileRequest $request, DeviceProfile $deviceProfile): RedirectResponse
    {
        $deviceProfile->update($request->validated());

        return redirect()->route('admin.devices.index')->with('success', 'Cihaz profili güncellendi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'modalities' => collect(DeviceModality::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'syncMethods' => collect(SyncMethod::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'precision' => $s->expectedPrecision()]),
        ];
    }
}
