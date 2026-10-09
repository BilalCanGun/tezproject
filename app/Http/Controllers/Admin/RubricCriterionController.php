<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Models\RubricCriterion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The explanation-quality rubric is edited as a whole and must be final
 * before main data collection; changing it after rating starts would make
 * earlier ratings incomparable.
 */
class RubricCriterionController extends Controller
{
    public function index(ProtocolVersion $protocol): Response
    {
        return Inertia::render('Admin/Rubric/Index', [
            'protocol' => [...$protocol->only(['id', 'code', 'name']), 'is_frozen' => $protocol->isFrozen()],
            'criteria' => $protocol->rubricCriteria()->orderBy('sort_order')->get()
                ->map(fn (RubricCriterion $criterion) => $criterion->only(['id', 'key', 'label', 'description', 'min_score', 'max_score', 'anchors', 'sort_order'])),
        ]);
    }

    public function update(Request $request, ProtocolVersion $protocol): RedirectResponse
    {
        abort_if($protocol->isFrozen(), 403, 'Dondurulmuş protokol değiştirilemez.');

        $validated = $request->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:10'],
            'criteria.*.key' => ['required', 'string', 'max:40', 'alpha_dash', 'distinct'],
            'criteria.*.label' => ['required', 'string', 'max:120'],
            'criteria.*.description' => ['nullable', 'string', 'max:1000'],
            'criteria.*.min_score' => ['required', 'integer', 'between:0,10'],
            'criteria.*.max_score' => ['required', 'integer', 'between:1,10'],
            'criteria.*.anchors' => ['nullable', 'array'],
            'criteria.*.anchors.*' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($validated['criteria'] as $index => $criterion) {
            if ($criterion['max_score'] <= $criterion['min_score']) {
                throw ValidationException::withMessages([
                    "criteria.{$index}.max_score" => 'Üst puan alt puandan büyük olmalı.',
                ]);
            }
        }

        DB::transaction(function () use ($protocol, $validated) {
            $protocol->rubricCriteria()->whereNotIn('key', collect($validated['criteria'])->pluck('key'))->delete();

            foreach (array_values($validated['criteria']) as $index => $criterion) {
                $protocol->rubricCriteria()->updateOrCreate(
                    ['key' => $criterion['key']],
                    [...$criterion, 'sort_order' => $index],
                );
            }
        });

        return back()->with('success', 'Rubrik kaydedildi.');
    }
}
