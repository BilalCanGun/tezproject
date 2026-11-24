<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Enums\TaskKind;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Analytics\OutcomeDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConceptPrecheckTest extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->tester = User::factory()->create();
        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    /**
     * Answer every task step of a baseline session with the given answers in order.
     *
     * @param  list<string>  $answers
     */
    private function runBaselineSession(ExperimentSession $session, array $answers): void
    {
        $response = $this->actingAs($this->tester)->post(route('sessions.kiosk-link.store', $session));
        $api = parse_url($response->getSession()->get('kiosk_url'), PHP_URL_PATH).'/api';
        auth()->logout();

        while ($step = $this->getJson("{$api}/state")->json('step')) {
            $this->postJson("{$api}/steps/{$step['id']}/start")->assertOk();
            $step = $this->getJson("{$api}/state")->json('step');

            if ($step['type'] === 'task') {
                $this->postJson("{$api}/attempts/{$step['attempt']['id']}/submit", ['end_reason' => 'submitted', 'mcq_answer' => array_shift($answers)])->assertOk();
            } else {
                $this->postJson("{$api}/steps/{$step['id']}/complete")->assertOk();
            }

            if ($session->fresh()->status === SessionStatus::Completed) {
                break;
            }
        }
    }

    #[Test]
    public function the_concept_precheck_is_scored_apart_from_the_baseline_and_comes_after_it(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice('a')->kind(TaskKind::ConceptPrecheck)->count(2)->create(['sort_order' => 0]);
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice('a')->count(2)->create(['sort_order' => 5]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->create();
        $session = $participant->sessions()->create(['session_number' => 0, 'status' => SessionStatus::Scheduled]);

        // Baseline: 1 of 2 correct. Pre-check: 2 of 2 correct.
        $this->runBaselineSession($session, ['a', 'b', 'a', 'a']);

        $kinds = $session->steps()->with('task')->whereNotNull('task_id')->orderBy('position')->get()->map(fn ($step) => $step->task->kind);
        $this->assertSame([TaskKind::Baseline, TaskKind::Baseline, TaskKind::ConceptPrecheck, TaskKind::ConceptPrecheck], $kinds->all());

        $participant->refresh();
        $this->assertSame(SessionStatus::Completed, $session->fresh()->status);
        $this->assertEquals(1, $participant->baseline_score);
        $this->assertEquals(2, $participant->baseline_max);
        $this->assertEquals(2, $participant->concept_precheck_score);
        $this->assertEquals(2, $participant->concept_precheck_max);
        $this->assertSame(50.0, $participant->baselinePercentage());
        $this->assertSame(100.0, $participant->conceptPrecheckPercentage());
    }

    #[Test]
    public function a_high_precheck_score_only_flags_the_participant(): void
    {
        $flagged = Participant::factory()->for($this->protocol, 'protocolVersion')->withBaseline()
            ->create(['concept_precheck_score' => 4, 'concept_precheck_max' => 5]);
        $unflagged = Participant::factory()->for($this->protocol, 'protocolVersion')->withBaseline()
            ->create(['concept_precheck_score' => 3, 'concept_precheck_max' => 4]);

        $this->assertTrue($flagged->mayKnowConcept());
        $this->assertFalse($unflagged->mayKnowConcept());
        $this->assertNotSame('excluded', $flagged->fresh()->status->value);

        $row = app(OutcomeDataset::class)->row($flagged, $this->protocol);
        $this->assertSame(80.0, $row['concept_precheck_percent']);
        $this->assertSame(1, $row['concept_prior_knowledge_flag']);
    }
}
