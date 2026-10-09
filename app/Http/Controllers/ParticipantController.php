<?php

namespace App\Http\Controllers;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Http\Requests\ParticipantRequest;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Services\Participants\ParticipantRegistrar;
use App\Services\Sessions\SessionScheduler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::enum(ParticipantStatus::class)],
            'condition' => ['nullable', Rule::enum(AssistanceCondition::class)],
            'protocol' => ['nullable', 'integer'],
        ]);

        $protocolId = $filters['protocol'] ?? ProtocolVersion::active()->value('id');

        $participants = Participant::query()
            ->when($protocolId, fn ($query) => $query->where('protocol_version_id', $protocolId))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('code', 'like', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['condition'] ?? null, fn ($query, $condition) => $query->where('condition', $condition))
            ->with(['sessions:id,participant_id,session_number,status,scheduled_at,started_at'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Participant $participant) => [
                'id' => $participant->id,
                'code' => $participant->code,
                'status' => $participant->status->value,
                'status_label' => $participant->status->label(),
                'condition' => $participant->condition?->value,
                'condition_label' => $participant->condition?->shortLabel(),
                'stratum_label' => $participant->stratum?->label(),
                'baseline_pct' => $participant->baselinePercentage(),
                'sessions' => collect(SessionKind::cases())->map(fn (SessionKind $kind) => [
                    'number' => $kind->value,
                    'status' => $participant->session($kind)?->status->value,
                ]),
                'created_at' => $participant->created_at->toDateString(),
            ]);

        return Inertia::render('Participants/Index', [
            'participants' => $participants,
            'filters' => [...$filters, 'protocol' => $protocolId],
            'protocols' => ProtocolVersion::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            'statuses' => collect(ParticipantStatus::cases())->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()]),
            'conditions' => collect(AssistanceCondition::cases())->map(fn ($condition) => ['value' => $condition->value, 'label' => $condition->label()]),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (! ProtocolVersion::active()->exists()) {
            return redirect()->route('participants.index')->with('error', 'Etkin bir protokol sürümü yok. Yöneticinin bir sürümü etkinleştirmesi gerekiyor.');
        }

        return Inertia::render('Participants/Form', [
            'participant' => null,
            'screeningItems' => Participant::SCREENING_ITEMS,
        ]);
    }

    public function store(ParticipantRequest $request, ParticipantRegistrar $registrar): RedirectResponse
    {
        $protocol = ProtocolVersion::active()->firstOrFail();

        $participant = $registrar->register($protocol, $request->participantAttributes(), $request->contactAttributes(), $request->user());

        $message = $participant->status === ParticipantStatus::Ineligible
            ? "{$participant->code} kaydedildi ancak ön eleme kriterlerini karşılamıyor."
            : "{$participant->code} kaydedildi. Sıradaki adım: ön değerlendirme oturumu.";

        return redirect()->route('participants.show', $participant)->with('success', $message);
    }

    public function show(Participant $participant, SessionScheduler $scheduler): Response
    {
        $participant->load([
            'protocolVersion:id,code,name,phase,settings',
            'contact',
            'randomizationSlot',
            'sessions.qualityReview',
            'sessions.conductor:id,name',
            'creator:id,name',
        ]);

        $sessions = collect(SessionKind::cases())->map(function (SessionKind $kind) use ($participant, $scheduler) {
            $session = $participant->session($kind);

            return [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'session' => $session ? [
                    'id' => $session->id,
                    'status' => $session->status->value,
                    'status_label' => $session->status->label(),
                    'scheduled_at' => $session->scheduled_at?->toIso8601String(),
                    'started_at' => $session->started_at?->toIso8601String(),
                    'duration_min' => $session->durationMinutes(),
                    'conductor' => $session->conductor?->name,
                    'quality' => $session->qualityReview?->verdict->label(),
                ] : null,
                'can_schedule' => $scheduler->canSchedule($participant, $kind),
            ];
        });

        return Inertia::render('Participants/Show', [
            'participant' => [
                ...$participant->only([
                    'id', 'code', 'age', 'gender', 'occupation_type', 'education_level', 'programming_experience_months',
                    'primary_language', 'vision_correction', 'handedness', 'screening', 'consent_version',
                    'data_sharing_level', 'exclusion_reason', 'notes', 'baseline_score', 'baseline_max',
                ]),
                'status' => $participant->status->value,
                'status_label' => $participant->status->label(),
                'condition' => $participant->condition?->value,
                'condition_label' => $participant->condition?->label(),
                'stratum_label' => $participant->stratum?->label(),
                'baseline_pct' => $participant->baselinePercentage(),
                'consented_at' => $participant->consented_at?->toIso8601String(),
                'randomized_at' => $participant->randomized_at?->toIso8601String(),
                'protocol' => $participant->protocolVersion->only(['id', 'code', 'name']),
                'created_by' => $participant->creator?->name,
                'review_flags' => ParticipantRegistrar::reviewFlags($participant->screening),
                'contact' => $participant->contact ? [
                    'full_name' => $participant->contact->full_name,
                    'email' => $participant->contact->email,
                    'phone' => $participant->contact->phone,
                    'purge_after' => $participant->contact->purge_after?->toDateString(),
                ] : null,
            ],
            'sessions' => $sessions,
            'canRandomize' => $participant->status === ParticipantStatus::Eligible
                && $participant->baseline_score !== null
                && $participant->consented_at !== null,
            'screeningItems' => Participant::SCREENING_ITEMS,
            'delayWindow' => [
                'min' => $participant->protocolVersion->setting('session.delay_min_days'),
                'max' => $participant->protocolVersion->setting('session.delay_max_days'),
            ],
        ]);
    }

    public function edit(Participant $participant): Response
    {
        $participant->load('contact');

        return Inertia::render('Participants/Form', [
            'participant' => [
                ...$participant->only([
                    'id', 'code', 'consent_version', 'data_sharing_level', 'age', 'gender', 'occupation_type',
                    'education_level', 'programming_experience_months', 'primary_language', 'vision_correction',
                    'handedness', 'screening', 'notes',
                ]),
                'consented_at' => $participant->consented_at?->format('Y-m-d\TH:i'),
                'full_name' => $participant->contact?->full_name,
                'email' => $participant->contact?->email,
                'phone' => $participant->contact?->phone,
                'purge_after' => $participant->contact?->purge_after?->toDateString(),
            ],
            'screeningItems' => Participant::SCREENING_ITEMS,
        ]);
    }

    public function update(ParticipantRequest $request, Participant $participant, ParticipantRegistrar $registrar): RedirectResponse
    {
        $registrar->update($participant, $request->participantAttributes(), $request->contactAttributes());

        return redirect()->route('participants.show', $participant)->with('success', 'Katılımcı bilgileri güncellendi.');
    }
}
