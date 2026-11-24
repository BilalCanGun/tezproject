<?php

namespace Tests\Feature;

use App\Enums\TaskKind;
use App\Models\ProtocolVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Analytics\PilotValidation;
use Database\Factories\TaskFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskDesignWarningsTest extends TestCase
{
    use RefreshDatabase;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    private function tasks(): TaskFactory
    {
        return Task::factory()->for($this->protocol, 'protocolVersion');
    }

    /**
     * A battery that satisfies every design check.
     */
    private function completeBattery(): void
    {
        $this->tasks()->multipleChoice()->count(10)->create();
        $this->tasks()->multipleChoice()->kind(TaskKind::ConceptPrecheck)->count(3)->create();

        foreach (['A', 'B'] as $form) {
            $this->tasks()->withForm($form, TaskKind::FarTransfer)->count(3)->sequence(fn ($sequence) => ['isomorph_group' => 'far-'.$sequence->index])->create();
            $this->tasks()->withForm($form, TaskKind::NearTransfer)->create();
            $this->tasks()->withForm($form, TaskKind::Debugging)->create();
        }

        $this->tasks()->forSession(2)->multipleChoice()->kind(TaskKind::DelayedRecall)->create([
            'session_number' => 2,
            'isomorph_group' => 'recall',
            'scoring_key' => ['measures' => 'delayed_recall', 'learning_objectives' => ['tanima']],
        ]);

        $this->protocol->update([
            'core_lesson' => '# Ortak içerik',
            'learning_objectives' => [['key' => 'tanima', 'label' => 'Kavramı tanıma']],
        ]);
    }

    #[Test]
    public function the_core_lesson_and_uncovered_objectives_are_reported(): void
    {
        $this->completeBattery();
        $this->protocol->update([
            'core_lesson' => null,
            'learning_objectives' => [['key' => 'tanima', 'label' => 'Kavramı tanıma'], ['key' => 'genelleme', 'label' => 'Yeni probleme genelleme']],
        ]);

        $this->assertSame([
            'Ortak öğrenme içeriği girilmemiş; üç koşulun da gördüğü temel kavram anlatımı 1. oturumda gösterilemez.',
            '"Yeni probleme genelleme" öğrenme hedefini ölçen görev yok.',
        ], $this->warnings());
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        return app(PilotValidation::class)->designWarnings($this->protocol);
    }

    #[Test]
    public function a_complete_battery_has_no_warnings(): void
    {
        $this->completeBattery();

        $this->assertSame([], $this->warnings());
    }

    #[Test]
    public function an_empty_battery_reports_every_missing_part(): void
    {
        $warnings = implode("\n", $this->warnings());

        $this->assertStringContainsString('Form A içinde 0 uzak transfer', $warnings);
        $this->assertStringContainsString('Form B içinde 0 uzak transfer', $warnings);
        $this->assertStringContainsString('gecikmeli hatırlama görevi yok', $warnings);
        $this->assertStringContainsString('0 ön test maddesi', $warnings);
        $this->assertStringContainsString('kavram ön kontrolü yok', $warnings);
    }

    #[Test]
    public function unequal_forms_are_reported(): void
    {
        $this->completeBattery();
        $this->tasks()->withForm('A', TaskKind::Debugging)->create();

        $this->assertSame(['Hata bulma ve düzeltme görev sayısı formlar arasında eşit değil (A: 2, B: 1).'], $this->warnings());
    }

    #[Test]
    public function recall_sharing_an_isomorph_group_with_far_transfer_is_reported(): void
    {
        $this->completeBattery();
        Task::query()->where('kind', TaskKind::DelayedRecall)->update(['isomorph_group' => 'far-0']);

        $this->assertCount(1, $this->warnings());
        $this->assertStringContainsString('aynı izomorf grubu (far-0)', $this->warnings()[0]);
    }

    #[Test]
    public function a_scoring_key_that_contradicts_the_task_kind_is_reported(): void
    {
        $this->completeBattery();
        $this->tasks()->forSession(1)->kind(TaskKind::NearTransfer)->create(['title' => 'Yanlış etiket', 'scoring_key' => ['measures' => 'far_transfer']]);
        $this->tasks()->forSession(1)->kind(TaskKind::Learning)->create(['scoring_key' => ['measures' => 'persistence']]);

        $this->assertCount(1, $this->warnings());
        $this->assertStringContainsString('"Yanlış etiket"', $this->warnings()[0]);
    }

    #[Test]
    public function baseline_size_outside_eight_to_twelve_is_reported(): void
    {
        $this->completeBattery();
        $this->tasks()->multipleChoice()->count(3)->create();

        $this->assertSame(['Ön değerlendirmede 13 ön test maddesi var; genel programlama becerisi için 8–12 madde önerilir.'], $this->warnings());
    }

    #[Test]
    public function warnings_are_shown_on_the_protocol_and_task_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.protocols.edit', $this->protocol))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('designWarnings', 6));
        $this->actingAs($admin)->get(route('admin.protocols.tasks.index', $this->protocol))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('designWarnings', 6));
    }
}
