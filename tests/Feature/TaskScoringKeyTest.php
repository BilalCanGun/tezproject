<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\AssistantProvider;
use App\Enums\TaskKind;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\Services\Assistance\AssistantClient;
use App\Services\Assistance\AssistantClientFactory;
use App\Services\Assistance\AssistantReply;
use App\Services\Assistance\AssistantRequest;
use App\Services\Scoring\LlmRubricScorer;
use App\Services\Sessions\SessionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskScoringKeyTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    /**
     * @var array<string, mixed>
     */
    private array $scoringKey = [
        'measures' => 'far_transfer',
        'expected_approach' => 'Problemi aynı yapıdaki daha küçük alt problemlere bölmek.',
        'difficulty' => 'orta',
        'estimated_minutes' => 8,
        'category_rationale' => 'Yüzey özellikleri öğrenme görevinden farklı; ilkenin uygulanabileceğini öğrenci kendisi fark etmeli.',
        'alternative_solutions' => ['def f(x):\n    return sum(f(e) if isinstance(e, list) else e for e in x)'],
        'partial_credit' => [['criterion' => 'Temel durum doğru, birleştirme eksik', 'points' => 4]],
        'misconceptions' => [['code' => 'temel_durum_yok', 'description' => 'Durma koşulu tanımlanmamış']],
        'target_bugs' => [],
        'explanation_elements' => ['Temel durumun ne olduğu', 'Her çağrıda problemin küçüldüğü'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    #[Test]
    public function the_scoring_key_never_reaches_the_kiosk(): void
    {
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->create(['scoring_key' => $this->scoringKey]);

        $this->assertArrayNotHasKey('scoring_key', $task->toKioskArray());
        $this->assertArrayNotHasKey('scoring_key', $task->toArray());

        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::StandardResource)->create();
        $session = $participant->sessions()->create(['session_number' => 1]);
        $response = $this->actingAs(User::factory()->create())->post(route('sessions.kiosk-link.store', $session));
        $api = parse_url($response->getSession()->get('kiosk_url'), PHP_URL_PATH).'/api';
        auth()->logout();
        $runner = app(SessionRunner::class);

        while (($step = $runner->currentStep($session->fresh()))->type->value !== 'task') {
            $runner->startStep($session->fresh(), $step);
            $runner->completeStep($session->fresh(), $step->fresh());
        }

        $this->postJson("{$api}/steps/{$step->id}/start")->assertOk();
        $state = $this->getJson("{$api}/state")->assertOk();

        $this->assertSame($task->id, $state->json('step.task.id'));
        $this->assertStringNotContainsString('temel_durum_yok', $state->getContent());
        $this->assertStringNotContainsString('scoring_key', $state->getContent());
    }

    #[Test]
    public function an_admin_can_save_the_task_profile_and_scoring_key(): void
    {
        $payload = Task::factory()->make()->makeVisible(['reference_solution'])->toArray();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.protocols.tasks.store', $this->protocol), [
                ...$payload,
                'kind' => 'far_transfer',
                'help_allowed' => false,
                'session_number' => 2,
                'scoring_key' => $this->scoringKey,
            ])
            ->assertSessionHasNoErrors();

        $task = $this->protocol->tasks()->firstOrFail();
        $this->assertSame('far_transfer', $task->scoring_key['measures']);
        $this->assertSame('temel_durum_yok', $task->scoring_key['misconceptions'][0]['code']);
        $this->assertSame(['Temel durumun ne olduğu', 'Her çağrıda problemin küçüldüğü'], $task->explanationElements());
    }

    #[Test]
    public function invalid_scoring_key_values_are_rejected(): void
    {
        $payload = Task::factory()->make()->makeVisible(['reference_solution'])->toArray();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.protocols.tasks.store', $this->protocol), [
                ...$payload,
                'scoring_key' => ['measures' => 'bilinmeyen', 'difficulty' => 'çok zor'],
            ])
            ->assertSessionHasErrors(['scoring_key.measures', 'scoring_key.difficulty']);
    }

    #[Test]
    public function the_llm_rater_prompt_lists_the_explanation_elements(): void
    {
        $this->protocol->update(['settings' => array_replace_recursive($this->protocol->settings, ['assistant' => ['provider' => AssistantProvider::Anthropic->value, 'model' => 'test-model']])]);
        $this->protocol->rubricCriteria()->create(['key' => 'accuracy', 'label' => 'Doğruluk', 'description' => 'Kavram doğru açıklanmış', 'min_score' => 0, 'max_score' => 3, 'sort_order' => 1]);
        $task = Task::factory()->for($this->protocol, 'protocolVersion')->kind(TaskKind::Explanation)->create(['scoring_key' => $this->scoringKey]);
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::StandardResource)->create();
        $session = $participant->sessions()->create(['session_number' => 1]);
        $step = $session->steps()->create(['type' => 'task', 'task_id' => $task->id, 'label' => 'Açıklama', 'position' => 1]);
        $attempt = TaskAttempt::create(['experiment_session_id' => $session->id, 'session_step_id' => $step->id, 'task_id' => $task->id, 'phase' => 'submitted', 'started_at' => now()]);
        $attempt->forceFill(['explanation_text' => 'Fonksiyon kendini çağırır.', 'submitted_at' => now()])->save();

        $client = new class implements AssistantClient
        {
            public ?AssistantRequest $request = null;

            public function complete(AssistantRequest $request): AssistantReply
            {
                $this->request = $request;

                return new AssistantReply('{"scores": {"accuracy": 2}}');
            }
        };
        $this->mock(AssistantClientFactory::class)->shouldReceive('make')->andReturn($client);

        $rating = app(LlmRubricScorer::class)->score($attempt->fresh());

        $this->assertSame(['accuracy' => 2], $rating->scores);
        $this->assertStringContainsString('iyi bir açıklamada bulunması beklenen unsurlar', $client->request->system);
        $this->assertStringContainsString('- Her çağrıda problemin küçüldüğü', $client->request->system);
    }
}
