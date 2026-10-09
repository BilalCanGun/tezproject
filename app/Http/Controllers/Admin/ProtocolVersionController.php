<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssistantProvider;
use App\Enums\ProtocolPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProtocolVersionRequest;
use App\Models\ProtocolVersion;
use App\Services\Assistance\DefaultPrompts;
use App\Services\AuditLogger;
use App\Services\Protocols\ProtocolCloner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolVersionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Protocols/Index', [
            'protocols' => ProtocolVersion::query()
                ->withCount(['tasks', 'participants', 'resources', 'rubricCriteria'])
                ->with('frozenBy:id,name')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ProtocolVersion $protocol) => [
                    'id' => $protocol->id,
                    'code' => $protocol->code,
                    'name' => $protocol->name,
                    'phase_label' => $protocol->phase->label(),
                    'is_active' => $protocol->is_active,
                    'frozen_at' => $protocol->frozen_at?->toIso8601String(),
                    'frozen_by' => $protocol->frozenBy?->name,
                    'provider_label' => AssistantProvider::tryFrom($protocol->setting('assistant.provider'))?->label(),
                    'model' => $protocol->setting('assistant.model'),
                    'tasks_count' => $protocol->tasks_count,
                    'participants_count' => $protocol->participants_count,
                    'resources_count' => $protocol->resources_count,
                    'rubric_criteria_count' => $protocol->rubric_criteria_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Protocols/Form', [
            'protocol' => [
                'code' => '',
                'name' => '',
                'phase' => ProtocolPhase::Pilot->value,
                'description' => '',
                'concept_title' => '',
                'concept_rationale' => '',
                'system_prompt_direct' => DefaultPrompts::DIRECT,
                'system_prompt_socratic' => DefaultPrompts::SOCRATIC,
                'settings' => config('experiment.protocol_defaults'),
                'is_frozen' => false,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(ProtocolVersionRequest $request, ProtocolCloner $cloner, AuditLogger $audit): RedirectResponse
    {
        $protocol = DB::transaction(function () use ($request, $cloner) {
            $protocol = ProtocolVersion::create([
                ...$request->safe()->except(['settings', 'copy_from']),
                'settings' => $request->settings(),
            ]);

            if ($request->validated('copy_from')) {
                $cloner->copyContent(ProtocolVersion::findOrFail($request->validated('copy_from')), $protocol);
            }

            return $protocol;
        });

        $audit->record('protocol.created', $protocol, ['copied_from' => $request->validated('copy_from')]);

        return redirect()->route('admin.protocols.edit', $protocol)->with('success', 'Protokol sürümü oluşturuldu.');
    }

    public function edit(ProtocolVersion $protocol): Response
    {
        return Inertia::render('Admin/Protocols/Form', [
            'protocol' => [
                ...$protocol->only(['id', 'code', 'name', 'description', 'concept_title', 'concept_rationale', 'system_prompt_direct', 'system_prompt_socratic', 'is_active']),
                'phase' => $protocol->phase->value,
                'settings' => $protocol->resolvedSettings(),
                'is_frozen' => $protocol->isFrozen(),
                'frozen_at' => $protocol->frozen_at?->toIso8601String(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(ProtocolVersionRequest $request, ProtocolVersion $protocol, AuditLogger $audit): RedirectResponse
    {
        if ($protocol->isFrozen()) {
            return back()->with('error', 'Dondurulmuş protokol değiştirilemez. Değişiklik için yeni bir sürüm oluşturun.');
        }

        $protocol->update([
            ...$request->safe()->except(['settings', 'copy_from']),
            'settings' => $request->settings(),
        ]);

        $audit->record('protocol.updated', $protocol);

        return back()->with('success', 'Protokol kaydedildi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'phases' => collect(ProtocolPhase::cases())->map(fn ($phase) => ['value' => $phase->value, 'label' => $phase->label()]),
            'providers' => collect(AssistantProvider::cases())->map(fn ($provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'supports_temperature' => $provider->supportsTemperature(),
            ]),
            'existingProtocols' => ProtocolVersion::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            'providerKeys' => [
                'anthropic' => filled(config('experiment.providers.anthropic.api_key')),
                'openai' => filled(config('experiment.providers.openai.api_key')),
            ],
        ];
    }
}
