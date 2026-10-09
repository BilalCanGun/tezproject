<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssistanceCondition;
use App\Enums\SkillStratum;
use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Models\RandomizationSlot;
use App\Services\AuditLogger;
use App\Services\Randomization\AllocationListGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AllocationListController extends Controller
{
    /**
     * Assigned slots are listed; unassigned future slots are only counted so
     * the allocation sequence stays concealed even from administrators who
     * browse the page casually.
     */
    public function show(ProtocolVersion $protocol): Response
    {
        $slots = $protocol->randomizationSlots()->with(['participant:id,code', 'assigner:id,name'])->orderBy('stratum')->orderBy('position')->get();

        return Inertia::render('Admin/Allocation/Show', [
            'protocol' => $protocol->only(['id', 'code', 'name']),
            'hasList' => $slots->isNotEmpty(),
            'blockSizes' => $protocol->setting('stratification.block_sizes'),
            'defaultSlots' => (int) $protocol->setting('sample.enrolment_target'),
            'strata' => collect(SkillStratum::cases())->map(fn (SkillStratum $stratum) => [
                'value' => $stratum->value,
                'label' => $stratum->label(),
                'total' => $slots->where('stratum', $stratum)->count(),
                'used' => $slots->where('stratum', $stratum)->whereNotNull('participant_id')->count(),
                'by_condition' => collect(AssistanceCondition::cases())->mapWithKeys(fn ($condition) => [
                    $condition->value => $slots->where('stratum', $stratum)->where('condition', $condition)->whereNotNull('participant_id')->count(),
                ]),
            ]),
            'assigned' => $slots->whereNotNull('participant_id')->values()->map(fn (RandomizationSlot $slot) => [
                'stratum_label' => $slot->stratum->label(),
                'position' => $slot->position,
                'block_number' => $slot->block_number,
                'condition_label' => $slot->condition->label(),
                'participant_code' => $slot->participant?->code,
                'assigned_at' => $slot->assigned_at?->toIso8601String(),
                'assigned_by' => $slot->assigner?->name,
            ]),
            'conditions' => collect(AssistanceCondition::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->shortLabel()]),
        ]);
    }

    public function store(Request $request, ProtocolVersion $protocol, AllocationListGenerator $generator, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'slots_per_stratum' => ['required', 'integer', 'between:3,1000'],
            'seed' => ['required', 'integer', 'between:1,2147483647'],
        ]);

        try {
            $created = $generator->generate(
                $protocol,
                (int) $validated['slots_per_stratum'],
                (int) $validated['seed'],
                array_map('intval', $protocol->setting('stratification.block_sizes')),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record('allocation.generated', $protocol, [
            'slots_per_stratum' => $validated['slots_per_stratum'],
            'seed_sha256' => hash('sha256', (string) $validated['seed']),
            'created' => $created,
        ]);

        return back()->with('success', 'Atama listesi oluşturuldu. Tohum değerini (seed) güvenli bir yerde saklayın; liste bu değerle yeniden üretilebilir.');
    }
}
