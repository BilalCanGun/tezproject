<?php

namespace App\Http\Controllers;

use App\Enums\DataFileKind;
use App\Enums\DeviceModality;
use App\Enums\QualityVerdict;
use App\Enums\SessionKind;
use App\Models\DataFile;
use App\Models\DeviceProfile;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\SessionDevice;
use App\Services\Sessions\SessionScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ExperimentSessionController extends Controller
{
    public function store(Request $request, Participant $participant, SessionScheduler $scheduler): RedirectResponse
    {
        $validated = $request->validate([
            'session_number' => ['required', Rule::enum(SessionKind::class)],
            'scheduled_at' => ['nullable', 'date'],
            'window_justification' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $scheduler->schedule(
                $participant,
                SessionKind::from((int) $validated['session_number']),
                isset($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null,
                $request->user(),
                $validated['window_justification'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['scheduled_at' => $exception->getMessage()]);
        }

        return redirect()->route('sessions.show', $session)->with('success', "{$session->kind()->label()} planlandı.");
    }

    public function show(ExperimentSession $session): Response
    {
        $session->load([
            'participant:id,code,condition,stratum,status,protocol_version_id,vision_correction',
            'participant.protocolVersion:id,code,settings',
            'conductor:id,name',
            'devices.profile',
            'qualityReview.reviewer:id,name',
            'files.uploader:id,name',
            'steps.task:id,kind,title',
        ]);

        $markerEvents = $session->events()->whereNotNull('marker_code');

        return Inertia::render('Sessions/Show', [
            'session' => [
                'id' => $session->id,
                'kind' => $session->session_number,
                'kind_label' => $session->kind()->label(),
                'records_physiology' => $session->kind()->recordsPhysiology(),
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                'is_paused' => $session->is_paused,
                'condition_label' => $session->condition?->label(),
                'scheduled_at' => $session->scheduled_at?->toIso8601String(),
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'duration_min' => $session->durationMinutes(),
                'conductor' => $session->conductor?->name,
                'abort_reason' => $session->abort_reason,
                'notes' => $session->notes,
                'environment' => $session->environment ?? (object) [],
                'setup_checklist' => $session->setup_checklist ?? (object) [],
                'kiosk' => [
                    'issued' => $session->kiosk_token_hash !== null,
                    'expires_at' => $session->kiosk_token_expires_at?->toIso8601String(),
                    'connected_at' => $session->kiosk_connected_at?->toIso8601String(),
                ],
                'ai_snapshot' => $session->ai_settings_snapshot ? [
                    'protocol_code' => $session->ai_settings_snapshot['protocol_code'],
                    'protocol_frozen' => $session->ai_settings_snapshot['protocol_frozen'],
                    'provider' => $session->ai_settings_snapshot['assistant']['provider'],
                    'model' => $session->ai_settings_snapshot['assistant']['model'],
                    'captured_at' => $session->ai_settings_snapshot['captured_at'],
                ] : null,
            ],
            'participant' => [
                'id' => $session->participant->id,
                'code' => $session->participant->code,
                'vision_correction' => $session->participant->vision_correction,
            ],
            'checklist' => $session->applicableChecklist(),
            'steps' => $session->steps->map(fn ($step) => [
                'id' => $step->id,
                'position' => $step->position,
                'label' => $step->label,
                'status' => $step->status,
            ]),
            'devices' => $session->devices->map(fn (SessionDevice $device) => [
                'id' => $device->id,
                'profile_id' => $device->device_profile_id,
                'name' => $device->profile->name,
                'modality' => $device->profile->modality->value,
                'modality_label' => $device->profile->modality->label(),
                'serial_number' => $device->serial_number,
                'lsl_stream_name' => $device->lsl_stream_name,
                'checks' => $device->checks ?? (object) [],
                'notes' => $device->notes,
            ]),
            'deviceProfiles' => DeviceProfile::query()
                ->where('is_active', true)
                ->orderBy('modality')
                ->orderBy('name')
                ->get(['id', 'name', 'modality'])
                ->map(fn (DeviceProfile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'modality' => $profile->modality->value,
                    'modality_label' => $profile->modality->label(),
                ]),
            'modalities' => collect(DeviceModality::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'quality' => [
                'review' => $session->qualityReview ? [
                    ...$session->qualityReview->only([
                        'eeg_usable_epoch_ratio', 'gaze_valid_ratio', 'wearable_loss_ratio', 'markers_expected',
                        'markers_acknowledged', 'all_steps_completed', 'needs_repeat', 'failed_checks', 'notes',
                    ]),
                    'verdict' => $session->qualityReview->verdict->value,
                    'marker_integrity' => $session->qualityReview->markerIntegrity(),
                    'reviewer' => $session->qualityReview->reviewer?->name,
                    'updated_at' => $session->qualityReview->updated_at->toIso8601String(),
                ] : null,
                'markers_expected' => (clone $markerEvents)->count(),
                'markers_acknowledged' => (clone $markerEvents)->whereNotNull('lsl_time')->count(),
                'thresholds' => $session->participant->protocolVersion->resolvedSettings()['quality'],
                'verdicts' => collect(QualityVerdict::cases())->map(fn ($v) => ['value' => $v->value, 'label' => $v->label()]),
            ],
            'files' => $session->files->map(fn (DataFile $file) => [
                'id' => $file->id,
                'kind_label' => $file->kind->label(),
                'original_name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'sha256' => $file->sha256,
                'recorded_start_utc' => $file->recorded_start_utc?->toIso8601String(),
                'uploader' => $file->uploader?->name,
                'created_at' => $file->created_at->toIso8601String(),
            ]),
            'fileKinds' => collect(DataFileKind::cases())->map(fn ($kind) => ['value' => $kind->value, 'label' => $kind->label()]),
            'kioskUrl' => session('kiosk_url'),
        ]);
    }

    public function update(Request $request, ExperimentSession $session): RedirectResponse
    {
        $checklistRules = collect(array_keys($session->applicableChecklist()))
            ->mapWithKeys(fn (string $key) => ["setup_checklist.{$key}" => ['boolean']])
            ->all();

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'setup_checklist' => ['nullable', 'array'],
            ...$checklistRules,
            'environment' => ['nullable', 'array'],
            'environment.room_lux' => ['nullable', 'numeric', 'between:0,5000'],
            'environment.screen_brightness_percent' => ['nullable', 'integer', 'between:0,100'],
            'environment.viewing_distance_cm' => ['nullable', 'integer', 'between:30,150'],
            'environment.room_temperature_c' => ['nullable', 'numeric', 'between:10,40'],
            'environment.monitor' => ['nullable', 'string', 'max:120'],
            'environment.screen_resolution' => ['nullable', 'string', 'max:30'],
        ]);

        $session->fill($validated)->save();

        return back()->with('success', 'Oturum bilgileri kaydedildi.');
    }
}
