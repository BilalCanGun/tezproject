<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskKind;
use App\Models\AiMessage;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KioskSessionFlowTest extends TestCase
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
     * Prepare the session through the tester endpoint and return the kiosk API base.
     */
    private function openKiosk(ExperimentSession $session): string
    {
        $response = $this->actingAs($this->tester)->post(route('sessions.kiosk-link.store', $session));
        $url = $response->getSession()->get('kiosk_url');
        $this->assertNull($response->getSession()->get('error'), (string) $response->getSession()->get('error'));
        $this->assertNotNull($url);
        auth()->logout();

        return parse_url($url, PHP_URL_PATH).'/api';
    }

    private function startCurrentStep(string $api): array
    {
        $step = $this->getJson("{$api}/state")->assertOk()->json('step');
        $this->postJson("{$api}/steps/{$step['id']}/start")->assertOk();

        return $this->getJson("{$api}/state")->json('step');
    }

    #[Test]
    public function the_baseline_session_runs_end_to_end_and_stores_the_score(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice('a')->count(2)->create();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->create();
        $session = $participant->sessions()->create(['session_number' => 0, 'status' => SessionStatus::Scheduled]);

        $api = $this->openKiosk($session);

        $instructions = $this->startCurrentStep($api);
        $this->assertSame('instructions', $instructions['type']);
        $this->postJson("{$api}/steps/{$instructions['id']}/complete")->assertOk();

        foreach (['a', 'b'] as $answer) {
            $step = $this->startCurrentStep($api);
            $this->assertArrayNotHasKey('mcq_answer', $step['task']);
            $this->postJson("{$api}/attempts/{$step['attempt']['id']}/submit", ['end_reason' => 'submitted', 'mcq_answer' => $answer])->assertOk();
        }

        $closing = $this->startCurrentStep($api);
        $this->postJson("{$api}/steps/{$closing['id']}/complete")->assertOk();

        $participant->refresh();
        $this->assertSame(SessionStatus::Completed, $session->fresh()->status);
        $this->assertEquals(1, $participant->baseline_score);
        $this->assertEquals(2, $participant->baseline_max);
        $this->assertNull($session->fresh()->condition);
    }

    #[Test]
    public function steps_cannot_be_skipped(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice()->create();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->create();
        $session = $participant->sessions()->create(['session_number' => 0, 'status' => SessionStatus::Scheduled]);
        $api = $this->openKiosk($session);

        $last = $session->steps()->reorder('position', 'desc')->first();

        $this->postJson("{$api}/steps/{$last->id}/start")->assertStatus(409);
    }

    #[Test]
    public function a_kiosk_token_cannot_touch_another_sessions_steps(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice()->create();
        $first = Participant::factory()->for($this->protocol, 'protocolVersion')->create()->sessions()->create(['session_number' => 0]);
        $second = Participant::factory()->for($this->protocol, 'protocolVersion')->create()->sessions()->create(['session_number' => 0]);

        $api = $this->openKiosk($first);
        $this->openKiosk($second);
        $foreignStep = $second->steps()->first();

        $this->postJson("{$api}/steps/{$foreignStep->id}/start")->assertNotFound();
    }

    #[Test]
    public function coding_runs_are_scored_and_persistence_counters_follow_the_events(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->forSession(1)->create(['help_allowed' => false]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::StandardResource)->create();
        $session = $participant->sessions()->create(['session_number' => 1]);
        $api = $this->openKiosk($session);

        $step = $this->getJson("{$api}/state")->json('step');
        while ($step['type'] !== 'task') {
            $this->postJson("{$api}/steps/{$step['id']}/start");
            $this->postJson("{$api}/steps/{$step['id']}/complete")->assertOk();
            $step = $this->getJson("{$api}/state")->json('step');
        }

        $step = $this->startCurrentStep($api);
        $attemptId = $step['attempt']['id'];
        $this->assertNull($step['attempt']['help_mode']);

        $this->patchJson("{$api}/attempts/{$attemptId}/phase", ['phase' => 'planning'])->assertOk();
        $this->patchJson("{$api}/attempts/{$attemptId}/phase", ['phase' => 'coding'])->assertOk();

        $now = now()->getTimestampMs();
        $events = [
            ['seq' => 1, 'type' => 'code_change', 'epoch_ms' => $now, 'perf_ms' => 10, 'attempt_id' => $attemptId, 'payload' => ['typed' => 40, 'deleted' => 5]],
            ['seq' => 2, 'type' => 'code_run', 'epoch_ms' => $now + 1000, 'perf_ms' => 1010, 'attempt_id' => $attemptId, 'payload' => ['tests_passed' => 0, 'tests_total' => 2, 'error' => true, 'code_hash' => 'aaa']],
            ['seq' => 3, 'type' => 'code_run', 'epoch_ms' => $now + 2000, 'perf_ms' => 2010, 'attempt_id' => $attemptId, 'payload' => ['tests_passed' => 1, 'tests_total' => 2, 'code_hash' => 'aaa']],
            ['seq' => 4, 'type' => 'code_run', 'epoch_ms' => $now + 3000, 'perf_ms' => 3010, 'attempt_id' => $attemptId, 'lsl_time' => 1234.5, 'payload' => ['tests_passed' => 2, 'tests_total' => 2, 'code_hash' => 'bbb']],
            ['seq' => 5, 'type' => 'paste', 'epoch_ms' => $now + 3500, 'perf_ms' => 3510, 'attempt_id' => $attemptId, 'payload' => ['target' => 'editor', 'length' => 12]],
        ];

        $this->postJson("{$api}/events", ['events' => $events])->assertOk()->assertJson(['stored' => 5]);
        $this->postJson("{$api}/events", ['events' => $events])->assertOk()->assertJson(['stored' => 0]);

        $this->postJson("{$api}/attempts/{$attemptId}/submit", ['end_reason' => 'submitted', 'code' => 'x', 'tests_passed' => 1, 'tests_total' => 2])->assertOk();

        $attempt = $session->attempts()->sole();
        $this->assertSame(3, $attempt->run_count);
        $this->assertSame(2, $attempt->failed_run_count);
        $this->assertSame(1, $attempt->changed_runs_after_failure);
        $this->assertSame(40, $attempt->typed_chars);
        $this->assertSame(12, $attempt->pasted_chars);
        $this->assertEquals(5.0, $attempt->auto_score);
        $this->assertSame(1, $session->events()->whereNotNull('lsl_time')->count());
    }

    #[Test]
    public function server_side_events_cannot_be_forged_by_the_kiosk(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice()->create();
        $session = Participant::factory()->for($this->protocol, 'protocolVersion')->create()->sessions()->create(['session_number' => 0]);
        $api = $this->openKiosk($session);

        $this->postJson("{$api}/events", ['events' => [
            ['seq' => 1, 'type' => 'tester_note', 'epoch_ms' => now()->getTimestampMs(), 'perf_ms' => 1],
        ]])->assertUnprocessable();

        $this->postJson("{$api}/events", ['events' => [
            ['seq' => 1_000_001, 'type' => 'copy', 'epoch_ms' => now()->getTimestampMs(), 'perf_ms' => 1],
        ]])->assertUnprocessable();
    }

    #[Test]
    public function the_socratic_assistant_never_delivers_a_full_solution(): void
    {
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->forSession(1)->create();
        $attempt = $this->codingAttempt($task, AssistanceCondition::SocraticAi);

        $this->postJson("{$attempt['api']}/attempts/{$attempt['id']}/assistant", ['message' => 'Çözümü yazar mısın?', 'code' => "def toplam(veri):\n    pass"])
            ->assertOk();

        $reply = AiMessage::where('role', AiMessage::ROLE_ASSISTANT)->sole();
        $this->assertFalse($reply->metrics['full_solution']);
        $this->assertGreaterThanOrEqual(1, $reply->metrics['question_count']);
        $this->assertSame(1, $reply->hint_level);
        $this->assertSame(1, $reply->attempt->help_request_count);
    }

    #[Test]
    public function the_direct_assistant_reply_is_measured_as_a_full_solution(): void
    {
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->forSession(1)->create();
        $attempt = $this->codingAttempt($task, AssistanceCondition::DirectAi);

        $this->postJson("{$attempt['api']}/attempts/{$attempt['id']}/assistant", ['message' => 'Çözümü yaz'])->assertOk();

        $reply = AiMessage::where('role', AiMessage::ROLE_ASSISTANT)->sole();
        $this->assertTrue($reply->metrics['full_solution']);
        $this->assertNull($reply->guard_action);
    }

    #[Test]
    public function the_standard_resource_condition_gets_no_assistant(): void
    {
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->forSession(1)->create();
        $attempt = $this->codingAttempt($task, AssistanceCondition::StandardResource);

        $this->postJson("{$attempt['api']}/attempts/{$attempt['id']}/assistant", ['message' => 'yardım'])->assertStatus(409);
        $this->getJson("{$attempt['api']}/resources")->assertOk();
    }

    #[Test]
    public function the_delayed_session_is_always_unassisted(): void
    {
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->create(['session_number' => 2, 'kind' => TaskKind::FarTransfer, 'help_allowed' => true]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::DirectAi)->create(['status' => ParticipantStatus::FirstSessionDone]);
        $session = $participant->sessions()->create(['session_number' => 2]);
        $api = $this->openKiosk($session);

        $attemptId = $this->advanceToTask($api)['attempt']['id'];

        $this->assertFalse((bool) $session->attempts()->find($attemptId)->help_available);
        $this->patchJson("{$api}/attempts/{$attemptId}/phase", ['phase' => 'coding']);
        $this->postJson("{$api}/attempts/{$attemptId}/assistant", ['message' => 'yardım'])->assertStatus(409);
        $this->assertSame($task->id, $session->attempts()->find($attemptId)->task_id);
    }

    #[Test]
    public function a_paused_session_blocks_progress(): void
    {
        Task::factory()->for($this->protocol, 'protocolVersion')->multipleChoice()->create();
        $session = Participant::factory()->for($this->protocol, 'protocolVersion')->create()->sessions()->create(['session_number' => 0]);
        $api = $this->openKiosk($session);

        $this->actingAs($this->tester)->post(route('sessions.pause', $session));
        auth()->logout();

        $step = $this->getJson("{$api}/state")->assertJsonPath('session.is_paused', true)->json('step');
        $this->postJson("{$api}/steps/{$step['id']}/start")->assertStatus(409);
        $this->getJson("{$api}/state")->assertJsonPath('relay.0.type', 'session_paused');
    }

    private function advanceToTask(string $api): array
    {
        $step = $this->getJson("{$api}/state")->json('step');

        while ($step['type'] !== 'task') {
            $this->postJson("{$api}/steps/{$step['id']}/start");
            $this->postJson("{$api}/steps/{$step['id']}/complete")->assertOk();
            $step = $this->getJson("{$api}/state")->json('step');
        }

        return $this->startCurrentStep($api);
    }

    /**
     * @return array{api: string, id: int}
     */
    private function codingAttempt(Task $task, AssistanceCondition $condition): array
    {
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized($condition)->create();
        $session = $participant->sessions()->create(['session_number' => 1]);
        $api = $this->openKiosk($session);
        $step = $this->advanceToTask($api);

        $this->patchJson("{$api}/attempts/{$step['attempt']['id']}/phase", ['phase' => 'coding'])->assertOk();

        return ['api' => $api, 'id' => $step['attempt']['id']];
    }
}
