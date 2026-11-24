<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TaskKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Services\Analytics\PilotValidation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(ProtocolVersion $protocol, PilotValidation $validation): Response
    {
        return Inertia::render('Admin/Tasks/Index', [
            'protocol' => $this->protocolSummary($protocol),
            'designWarnings' => $validation->designWarnings($protocol),
            'tasks' => $protocol->tasks()
                ->withCount('attempts')
                ->orderBy('session_number')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Task $task) => [
                    'id' => $task->id,
                    'session_number' => $task->session_number,
                    'form' => $task->form,
                    'kind_label' => $task->kind->label(),
                    'title' => $task->title,
                    'isomorph_group' => $task->isomorph_group,
                    'sort_order' => $task->sort_order,
                    'max_score' => $task->max_score,
                    'planning_seconds' => $task->planning_seconds,
                    'time_limit_seconds' => $task->time_limit_seconds,
                    'help_allowed' => $task->help_allowed,
                    'is_active' => $task->is_active,
                    'tests_count' => count($task->test_cases ?? []),
                    'attempts_count' => $task->attempts_count,
                ])
                ->groupBy(fn (array $task) => $task['form'] ? 'form_'.$task['form'] : (string) $task['session_number']),
        ]);
    }

    public function create(ProtocolVersion $protocol): Response|RedirectResponse
    {
        if ($protocol->isFrozen()) {
            return $this->frozen($protocol);
        }

        return Inertia::render('Admin/Tasks/Form', [
            'protocol' => $this->protocolSummary($protocol),
            'task' => null,
            'kinds' => $this->kinds(),
            'measures' => $this->measures(),
            'pyodideIndexUrl' => config('experiment.pyodide_index_url'),
        ]);
    }

    public function store(TaskRequest $request, ProtocolVersion $protocol): RedirectResponse
    {
        if ($protocol->isFrozen()) {
            return $this->frozen($protocol);
        }

        $protocol->tasks()->create($request->validated());

        return redirect()->route('admin.protocols.tasks.index', $protocol)->with('success', 'Görev eklendi.');
    }

    public function edit(ProtocolVersion $protocol, Task $task): Response
    {
        return Inertia::render('Admin/Tasks/Form', [
            'protocol' => $this->protocolSummary($protocol),
            'task' => [
                ...$task->makeVisible(['reference_solution', 'mcq_answer', 'scoring_key'])->toArray(),
                'kind' => $task->kind->value,
            ],
            'kinds' => $this->kinds(),
            'measures' => $this->measures(),
            'pyodideIndexUrl' => config('experiment.pyodide_index_url'),
        ]);
    }

    public function update(TaskRequest $request, ProtocolVersion $protocol, Task $task): RedirectResponse
    {
        if ($protocol->isFrozen()) {
            return $this->frozen($protocol);
        }

        $task->update($request->validated());

        return redirect()->route('admin.protocols.tasks.index', $protocol)->with('success', 'Görev güncellendi.');
    }

    public function destroy(ProtocolVersion $protocol, Task $task): RedirectResponse
    {
        if ($protocol->isFrozen()) {
            return $this->frozen($protocol);
        }

        if ($task->attempts()->exists()) {
            $task->update(['is_active' => false]);

            return back()->with('success', 'Görevin kayıtlı denemeleri olduğu için silinmedi, pasifleştirildi.');
        }

        $task->delete();

        return back()->with('success', 'Görev silindi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function protocolSummary(ProtocolVersion $protocol): array
    {
        return [...$protocol->only(['id', 'code', 'name']), 'is_frozen' => $protocol->isFrozen()];
    }

    /**
     * @return list<array{value: string, label: string, format: string}>
     */
    private function kinds(): array
    {
        return collect(TaskKind::cases())
            ->map(fn (TaskKind $kind) => ['value' => $kind->value, 'label' => $kind->label(), 'format' => $kind->responseFormat()])
            ->all();
    }

    /**
     * Outcome variables a task can measure: every task kind plus persistence.
     *
     * @return list<array{value: string, label: string}>
     */
    private function measures(): array
    {
        return [
            ...collect(TaskKind::cases())->map(fn (TaskKind $kind) => ['value' => $kind->value, 'label' => $kind->label()])->all(),
            ['value' => TaskRequest::MEASURE_PERSISTENCE, 'label' => 'Görevde devam etme (ısrar)'],
        ];
    }

    private function frozen(ProtocolVersion $protocol): RedirectResponse
    {
        return redirect()->route('admin.protocols.tasks.index', $protocol)->with('error', 'Dondurulmuş protokolün görevleri değiştirilemez.');
    }
}
