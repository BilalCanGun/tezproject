<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\TaskKind;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\SessionStep;
use App\Models\Task;
use App\Services\Analytics\PilotValidation;
use App\Services\Sessions\SessionRunner;
use Database\Seeders\PilotProtocolV2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PilotProtocolV2SeederTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotProtocolV2Seeder::class);
        $this->protocol = ProtocolVersion::where('code', PilotProtocolV2Seeder::CODE)->sole();
    }

    #[Test]
    public function the_draft_battery_meets_every_design_check(): void
    {
        $this->assertFalse($this->protocol->is_active);
        $this->assertSame([], app(PilotValidation::class)->designWarnings($this->protocol));

        $tasks = $this->protocol->tasks;
        $this->assertCount(10, $tasks->where('kind', TaskKind::Baseline));
        $this->assertCount(4, $tasks->where('kind', TaskKind::ConceptPrecheck));
        $this->assertCount(3, $tasks->where('kind', TaskKind::FarTransfer)->where('form', 'A'));
        $this->assertCount(3, $tasks->where('kind', TaskKind::FarTransfer)->where('form', 'B'));
        $this->assertTrue($tasks->every(fn (Task $task) => ($task->scoring_key['measures'] ?? null) === $task->kind->value));
        $this->assertTrue($tasks->filter(fn (Task $task) => $task->kind->isCoding())->every(fn (Task $task) => count($task->test_cases) >= 6));
    }

    #[Test]
    public function a_ba_participant_gets_the_lesson_and_form_b_first_and_far_transfer_first_a_week_later(): void
    {
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')
            ->randomized(AssistanceCondition::SocraticAi, formOrder: 'BA')->create();
        $runner = app(SessionRunner::class);

        $first = $participant->sessions()->create(['session_number' => 1]);
        $runner->prepare($first);
        $steps = $first->steps()->with('task')->orderBy('position')->get();

        $this->assertNotNull($steps->firstWhere('label', 'Ortak öğrenme içeriği'));
        $this->assertSame(
            ['learning', 'learning', 'near_transfer', 'far_transfer', 'far_transfer', 'far_transfer', 'debugging', 'explanation'],
            $steps->whereNotNull('task_id')->map(fn (SessionStep $step) => $step->task->kind->value)->values()->all(),
        );
        $this->assertSame(['B'], $steps->pluck('task.form')->filter()->unique()->values()->all());

        $second = $participant->sessions()->create(['session_number' => 2]);
        $runner->prepare($second);
        $kinds = $second->steps()->with('task')->whereNotNull('task_id')->orderBy('position')->get()->map(fn (SessionStep $step) => $step->task->kind->value)->all();

        $this->assertSame(['far_transfer', 'far_transfer', 'far_transfer', 'near_transfer', 'debugging', 'delayed_recall', 'delayed_recall', 'delayed_recall', 'explanation'], $kinds);
    }
}
