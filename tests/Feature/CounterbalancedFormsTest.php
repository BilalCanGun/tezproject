<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\SkillStratum;
use App\Enums\TaskKind;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\RandomizationSlot;
use App\Models\SessionStep;
use App\Models\Task;
use App\Models\User;
use App\Services\Randomization\AllocationListGenerator;
use App\Services\Randomization\ParticipantRandomizer;
use App\Services\Sessions\SessionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CounterbalancedFormsTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function generatedList(int $seed): array
    {
        app(AllocationListGenerator::class)->generate($this->protocol, 30, $seed, [3, 6]);

        return $this->protocol->randomizationSlots()->orderBy('stratum')->orderBy('position')->get()
            ->map(fn (RandomizationSlot $slot) => [$slot->stratum->value, $slot->condition->value, $slot->form_order])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function plannedForms(ExperimentSession $session): array
    {
        return $session->steps()->with('task')->whereNotNull('task_id')->orderBy('position')->get()
            ->map(fn (SessionStep $step) => $step->task->form ?? '-')
            ->all();
    }

    #[Test]
    public function form_orders_are_balanced_in_every_stratum_and_condition(): void
    {
        $slots = collect($this->generatedList(20261124));

        foreach (SkillStratum::cases() as $stratum) {
            foreach (AssistanceCondition::cases() as $condition) {
                $cell = $slots->filter(fn (array $slot) => $slot[0] === $stratum->value && $slot[1] === $condition->value)->pluck(2);

                $this->assertNotEmpty($cell);
                $this->assertLessThanOrEqual(1, abs($cell->filter(fn ($order) => $order === 'AB')->count() - $cell->filter(fn ($order) => $order === 'BA')->count()));

                // Balance also holds at every point of the sequence, as slots are used in order.
                $this->assertSame($cell->values()->all(), $cell->values()->map(fn ($order, $index) => $index % 2 === 0 ? $cell->first() : ($cell->first() === 'AB' ? 'BA' : 'AB'))->all());
            }
        }
    }

    #[Test]
    public function the_same_seed_reproduces_the_form_order_sequence(): void
    {
        $first = $this->generatedList(987654);
        $second = $this->generatedList(987654);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function randomisation_copies_the_form_order_to_the_participant(): void
    {
        app(AllocationListGenerator::class)->generate($this->protocol, 6, 42, [3]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->withBaseline(6, 10)->create();

        $slot = app(ParticipantRandomizer::class)->assign($participant, User::factory()->create());

        $this->assertContains($slot->form_order, ['AB', 'BA']);
        $this->assertSame($slot->form_order, $participant->fresh()->form_order);
    }

    #[Test]
    public function a_ba_participant_sees_form_b_first_and_form_a_in_the_delayed_session(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->forSession(1)->create(['sort_order' => 0]);
        Task::factory()->for($this->protocol, 'protocolVersion')->withForm('A')->create(['help_allowed' => true]);
        Task::factory()->for($this->protocol, 'protocolVersion')->withForm('B')->create(['help_allowed' => true]);
        Task::factory()->for($this->protocol, 'protocolVersion')->forSession(2)->kind(TaskKind::DelayedRecall)->create();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')
            ->randomized(AssistanceCondition::DirectAi, formOrder: 'BA')->create();

        $first = $participant->sessions()->create(['session_number' => 1]);
        app(SessionRunner::class)->prepare($first);
        $this->assertSame(['-', 'B'], $this->plannedForms($first));

        $second = $participant->sessions()->create(['session_number' => 2]);
        app(SessionRunner::class)->prepare($second);
        $this->assertSame(['A', '-'], $this->plannedForms($second));
    }

    #[Test]
    public function help_is_never_available_for_a_form_task_in_the_delayed_session(): void
    {
        $formA = Task::factory()->for($this->protocol, 'protocolVersion')->withForm('A')->create(['help_allowed' => true]);
        $formB = Task::factory()->for($this->protocol, 'protocolVersion')->withForm('B')->create(['help_allowed' => true]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')
            ->randomized(AssistanceCondition::DirectAi, formOrder: 'AB')->create();
        $runner = app(SessionRunner::class);

        foreach ([1 => $formA, 2 => $formB] as $number => $expectedTask) {
            $session = $participant->sessions()->create(['session_number' => $number]);
            $runner->prepare($session);

            while (($step = $runner->currentStep($session->fresh()))->type->value !== 'task') {
                $runner->startStep($session->fresh(), $step);
                $runner->completeStep($session->fresh(), $step->fresh());
            }

            $attempt = $runner->startStep($session->fresh(), $step)->attempt;

            $this->assertSame($expectedTask->id, $attempt->task_id);
            $this->assertSame($number === 1, $attempt->help_available, "Oturum {$number}");
        }
    }

    #[Test]
    public function form_tasks_need_a_form_order(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->withForm('A')->create();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')
            ->randomized(AssistanceCondition::StandardResource)->create(['form_order' => null]);
        $session = $participant->sessions()->create(['session_number' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('form sırası');

        app(SessionRunner::class)->prepare($session);
    }

    #[Test]
    public function form_tasks_are_validated_by_the_admin_form(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = Task::factory()->make()->makeVisible(['reference_solution'])->toArray();

        $this->actingAs($admin)
            ->post(route('admin.protocols.tasks.store', $this->protocol), [...$payload, 'kind' => 'learning', 'form' => 'A'])
            ->assertSessionHasErrors('form');

        $this->actingAs($admin)
            ->post(route('admin.protocols.tasks.store', $this->protocol), [...$payload, 'kind' => 'far_transfer', 'form' => 'B', 'session_number' => 1, 'help_allowed' => true])
            ->assertSessionHasNoErrors();

        $task = $this->protocol->tasks()->firstOrFail();
        $this->assertSame('B', $task->form);
        $this->assertNull($task->session_number);
    }
}
