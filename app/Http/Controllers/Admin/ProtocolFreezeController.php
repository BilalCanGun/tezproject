<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TaskKind;
use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Freezing locks model parameters, prompts, task battery, rubric and
 * thresholds before confirmatory data collection. It cannot be undone.
 */
class ProtocolFreezeController extends Controller
{
    public function store(Request $request, ProtocolVersion $protocol, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:'.$protocol->code]], [
            'confirmation.in' => 'Onay için protokol kodunu aynen yazın.',
        ]);

        if ($protocol->isFrozen()) {
            return back()->with('error', 'Protokol zaten dondurulmuş.');
        }

        $problems = $this->readinessProblems($protocol);

        if ($problems) {
            return back()->with('error', 'Dondurulamaz: '.implode(' ', $problems));
        }

        $protocol->forceFill(['frozen_at' => now(), 'frozen_by' => $request->user()->id])->save();

        $audit->record('protocol.frozen', $protocol, [
            'settings_sha256' => hash('sha256', json_encode($protocol->resolvedSettings())),
            'prompts_sha256' => hash('sha256', $protocol->system_prompt_direct."\n---\n".$protocol->system_prompt_socratic),
            'task_ids' => $protocol->tasks()->pluck('id')->all(),
        ]);

        return back()->with('success', "{$protocol->code} donduruldu. Bu sürümde artık değişiklik yapılamaz.");
    }

    /**
     * @return list<string>
     */
    private function readinessProblems(ProtocolVersion $protocol): array
    {
        $problems = [];
        $tasks = $protocol->tasks()->where('is_active', true)->get();

        $formTasks = $tasks->whereNotNull('form');

        foreach ([0 => 'ön değerlendirme', 1 => '1. oturum', 2 => '2. oturum'] as $session => $label) {
            if ($tasks->whereNull('form')->where('session_number', $session)->isEmpty() && ($session === 0 || $formTasks->isEmpty())) {
                $problems[] = "{$label} için görev yok.";
            }
        }

        if ($formTasks->isNotEmpty() && $formTasks->pluck('form')->unique()->count() < 2) {
            $problems[] = 'Formlu görevler var ama A ve B formlarından biri boş; karşı dengeleme için iki form da gerekir.';
        }

        $secondSessionFar = $tasks->filter(fn ($task) => $task->kind === TaskKind::FarTransfer && ($task->form !== null || $task->session_number === 2));

        if ($secondSessionFar->isEmpty()) {
            $problems[] = '2. oturumda birincil sonuç için uzak transfer görevi yok.';
        }

        if ($tasks->filter(fn ($task) => $task->kind->isCoding() && empty($task->test_cases))->isNotEmpty()) {
            $problems[] = 'Test durumu tanımlanmamış kodlama görevleri var.';
        }

        if (! $protocol->rubricCriteria()->exists()) {
            $problems[] = 'Açıklama rubriği tanımlanmamış.';
        }

        if (! $protocol->resources()->exists()) {
            $problems[] = 'Standart kaynak içeriği yok.';
        }

        return $problems;
    }
}
