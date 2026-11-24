<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\TaskKind;
use App\Models\CodeReview;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\Services\Analytics\OutcomeDataset;
use App\Services\Analytics\PilotValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->protocol = ProtocolVersion::factory()->active()->create(['settings' => array_replace_recursive(config('experiment.protocol_defaults'), ['rating' => ['double_rating_percent' => 0]])]);
        $this->participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::DirectAi)->create();
    }

    private function submittedAttempt(Task $task, int $sessionNumber = 2, float $autoScore = 4): TaskAttempt
    {
        $session = $this->participant->sessions()->firstOrCreate(['session_number' => $sessionNumber]);
        $step = $session->steps()->create(['type' => 'task', 'task_id' => $task->id, 'label' => $task->title, 'position' => $session->steps()->count() + 1]);
        $attempt = TaskAttempt::create(['experiment_session_id' => $session->id, 'session_step_id' => $step->id, 'task_id' => $task->id, 'phase' => 'submitted', 'started_at' => now()]);
        $attempt->forceFill(['submitted_at' => now(), 'final_code' => 'def f(x):\n    return x', 'auto_score' => $autoScore, 'tests_passed' => 2, 'tests_total' => 5, 'end_reason' => 'submitted'])->save();

        return $attempt;
    }

    private function farTask(string $form = 'B'): Task
    {
        return Task::factory()->for($this->protocol, 'protocolVersion')->withForm($form)->create([
            'scoring_key' => [
                'partial_credit' => [['criterion' => 'Durumlar doğru tanımlanmış', 'points' => 4], ['criterion' => 'Geçişler eksiksiz', 'points' => 4]],
                'misconceptions' => [['code' => 'durum_guncellenmiyor', 'description' => 'Durum değişkeni güncellenmiyor']],
            ],
        ]);
    }

    #[Test]
    public function coding_attempts_are_queued_blind_and_scored_against_the_key(): void
    {
        $attempt = $this->submittedAttempt($this->farTask());
        $rater = User::factory()->create();

        $this->actingAs($rater)->get(route('code-reviews.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 1)->where('pending.0.id', $attempt->id));

        $this->actingAs($rater)->get(route('code-reviews.edit', $attempt))
            ->assertOk()
            ->assertDontSee($this->participant->code)
            ->assertDontSee('direct_ai');

        $this->actingAs($rater)->put(route('code-reviews.update', $attempt), [
            'score' => 8,
            'partial_credit' => [0, 1],
            'misconceptions' => ['durum_guncellenmiyor'],
            'alternative_solution' => true,
        ])->assertRedirect(route('code-reviews.index'));

        $review = CodeReview::sole();
        $this->assertSame(8.0, $review->score);
        $this->assertSame([0, 1], $review->partial_credit);
        $this->assertTrue($review->alternative_solution);
        $this->assertSame(4.0, $attempt->fresh()->auto_score);

        $row = app(OutcomeDataset::class)->row($this->participant->fresh()->load('sessions.attempts.task', 'sessions.attempts.codeReviews', 'sessions.attempts.ratings'), $this->protocol);
        $this->assertSame(40.0, $row['s2_far_transfer_pct']);
        $this->assertSame(80.0, $row['s2_far_transfer_reviewed_pct']);

        $this->actingAs($rater)->get(route('code-reviews.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 0));
    }

    #[Test]
    public function codes_outside_the_scoring_key_are_rejected(): void
    {
        $attempt = $this->submittedAttempt($this->farTask());

        $this->actingAs(User::factory()->create())->put(route('code-reviews.update', $attempt), [
            'score' => 20,
            'partial_credit' => [5],
            'misconceptions' => ['uydurma'],
        ])->assertSessionHasErrors(['score', 'partial_credit.0', 'misconceptions.0']);
    }

    #[Test]
    public function explanation_attempts_are_not_code_reviewed(): void
    {
        $attempt = $this->submittedAttempt(Task::factory()->for($this->protocol, 'protocolVersion')->kind(TaskKind::Explanation)->create());

        $this->actingAs(User::factory()->create())->get(route('code-reviews.edit', $attempt))->assertNotFound();
    }

    #[Test]
    public function the_pilot_table_compares_form_a_and_form_b(): void
    {
        $this->submittedAttempt($this->farTask('A'), 1, 9);
        $this->submittedAttempt($this->farTask('B'), 2, 5);

        $row = collect(app(PilotValidation::class)->table($this->protocol))->firstWhere('component', 'Form eşdeğerliği (Uzak transfer)');

        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('A %90.0, B %50.0', $row['criterion']);
        $this->assertSame('40,00 puan', $row['observed']);
    }
}
