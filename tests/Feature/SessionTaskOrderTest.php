<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\TaskKind;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\SessionStep;
use App\Models\Task;
use App\Models\User;
use App\Services\Sessions\SessionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionTaskOrderTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    /**
     * @return list<string>
     */
    private function plannedKinds(ExperimentSession $session): array
    {
        return $session->steps()->with('task')->whereNotNull('task_id')->orderBy('position')->get()
            ->map(fn (SessionStep $step) => $step->task->kind->value)
            ->all();
    }

    private function task(TaskKind $kind, int $sortOrder, ?int $session = null, ?string $form = null): Task
    {
        $factory = Task::factory()->for($this->protocol, 'protocolVersion');
        $factory = $form ? $factory->withForm($form, $kind) : $factory->forSession($session)->kind($kind);

        return $factory->create(['sort_order' => $sortOrder]);
    }

    #[Test]
    public function the_delayed_session_always_opens_with_far_transfer(): void
    {
        // sort_order deliberately puts recall, near and debugging first.
        $this->task(TaskKind::Explanation, 0, 2);
        $this->task(TaskKind::DelayedRecall, 1, 2);
        $this->task(TaskKind::NearTransfer, 2, form: 'B');
        $this->task(TaskKind::Debugging, 3, form: 'B');
        $this->task(TaskKind::FarTransfer, 9, form: 'B');
        $this->task(TaskKind::FarTransfer, 5, form: 'B');
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::SocraticAi, formOrder: 'AB')->create();
        $session = $participant->sessions()->create(['session_number' => 2]);

        app(SessionRunner::class)->prepare($session);

        $this->assertSame(['far_transfer', 'far_transfer', 'near_transfer', 'debugging', 'delayed_recall', 'explanation'], $this->plannedKinds($session));
        $far = $session->steps()->with('task')->whereNotNull('task_id')->orderBy('position')->take(2)->get()->map(fn (SessionStep $step) => $step->task->sort_order)->all();
        $this->assertSame([5, 9], $far);
    }

    #[Test]
    public function the_learning_session_follows_the_learning_order(): void
    {
        $this->task(TaskKind::Explanation, 0, 1);
        $this->task(TaskKind::Debugging, 1, form: 'A');
        $this->task(TaskKind::FarTransfer, 2, form: 'A');
        $this->task(TaskKind::NearTransfer, 3, form: 'A');
        $this->task(TaskKind::Learning, 4, 1);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::SocraticAi, formOrder: 'AB')->create();
        $session = $participant->sessions()->create(['session_number' => 1]);

        app(SessionRunner::class)->prepare($session);

        $this->assertSame(['learning', 'near_transfer', 'far_transfer', 'debugging', 'explanation'], $this->plannedKinds($session));
    }

    #[Test]
    public function saving_the_protocol_form_keeps_the_task_order_in_the_stored_settings(): void
    {
        $this->protocol->update(['settings' => ['assistant' => ['provider' => 'scripted']]]);
        $settings = config('experiment.protocol_defaults');
        unset($settings['session']['task_order']);
        $settings['stratification']['block_sizes'] = [3];

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.protocols.update', $this->protocol), [
                ...$this->protocol->only(['code', 'name', 'system_prompt_direct', 'system_prompt_socratic']),
                'phase' => $this->protocol->phase->value,
                'settings' => $settings,
            ])
            ->assertSessionHasNoErrors();

        $stored = $this->protocol->fresh()->settings;
        $this->assertSame(config('experiment.protocol_defaults.session.task_order'), $stored['session']['task_order']);
        $this->assertSame(75, $stored['screening']['concept_precheck_exclude_percent']);
        $this->assertSame([3], $stored['stratification']['block_sizes']);
    }

    #[Test]
    public function the_task_order_is_part_of_the_protocol_settings(): void
    {
        $this->assertSame(
            ['far_transfer', 'near_transfer', 'debugging', 'delayed_recall', 'explanation'],
            $this->protocol->setting('session.task_order.2'),
        );
        $this->assertSame($this->protocol->setting('session.task_order'), $this->protocol->resolvedSettings()['session']['task_order']);
    }
}
