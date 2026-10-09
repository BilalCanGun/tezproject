<?php

namespace App\Http\Controllers;

use App\Models\ExperimentSession;
use App\Models\SessionDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionDeviceController extends Controller
{
    public function store(Request $request, ExperimentSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'device_profile_id' => [
                'required',
                Rule::exists('device_profiles', 'id')->where('is_active', true),
                Rule::unique('session_devices')->where('experiment_session_id', $session->id),
            ],
        ], ['device_profile_id.unique' => 'Bu cihaz oturuma zaten eklenmiş.']);

        $session->devices()->create($validated);

        return back()->with('success', 'Cihaz oturuma eklendi.');
    }

    public function update(Request $request, ExperimentSession $session, SessionDevice $device): RedirectResponse
    {
        $validated = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:80'],
            'lsl_stream_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'checks' => ['nullable', 'array'],
            'checks.signal_quality_ok' => ['nullable', 'boolean'],
            'checks.bad_channels' => ['nullable', 'string', 'max:200'],
            'checks.impedance_note' => ['nullable', 'string', 'max:200'],
            'checks.calibration_points' => ['nullable', 'integer', 'between:0,16'],
            'checks.calibration_accuracy_deg' => ['nullable', 'numeric', 'between:0,10'],
            'checks.calibration_precision_deg' => ['nullable', 'numeric', 'between:0,10'],
            'checks.tracked_eye' => ['nullable', Rule::in(['both', 'left', 'right'])],
            'checks.recording_started_utc' => ['nullable', 'date'],
            'checks.device_clock_offset_ms' => ['nullable', 'integer', 'between:-600000,600000'],
            'checks.worn_on' => ['nullable', Rule::in(['left', 'right'])],
        ]);

        $device->update($validated);

        return back()->with('success', 'Cihaz kontrolleri kaydedildi.');
    }

    public function destroy(ExperimentSession $session, SessionDevice $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', 'Cihaz oturumdan çıkarıldı.');
    }
}
