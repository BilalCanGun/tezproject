<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SkillStratum;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\RandomizationSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RandomizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProtocolVersion $protocol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->protocol = ProtocolVersion::factory()->active()->create();
    }

    private function generate(int $slots = 30, int $seed = 42): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.protocols.allocation.store', $this->protocol), ['slots_per_stratum' => $slots, 'seed' => $seed])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function every_permuted_block_is_balanced_across_conditions(): void
    {
        $this->generate();

        $blocks = RandomizationSlot::all()->groupBy(fn ($slot) => $slot->stratum->value.'-'.$slot->block_number);

        foreach ($blocks as $block) {
            $counts = $block->countBy(fn ($slot) => $slot->condition->value);
            $this->assertCount(3, $counts);
            $this->assertCount(1, $counts->unique());
        }

        foreach (SkillStratum::cases() as $stratum) {
            $this->assertGreaterThanOrEqual(30, RandomizationSlot::where('stratum', $stratum)->count());
        }
    }

    #[Test]
    public function the_same_seed_reproduces_the_same_sequence(): void
    {
        $this->generate(seed: 7);
        $first = RandomizationSlot::orderBy('stratum')->orderBy('position')->pluck('condition')->map->value->all();

        $this->generate(seed: 7);
        $second = RandomizationSlot::orderBy('stratum')->orderBy('position')->pluck('condition')->map->value->all();

        $this->assertSame($first, $second);
        $this->assertDatabaseMissing('audit_logs', ['properties' => json_encode(['seed' => 7])]);
    }

    #[Test]
    public function a_participant_draws_the_first_free_slot_of_their_stratum(): void
    {
        $this->generate();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->withBaseline(8, 10)->create();
        $expected = RandomizationSlot::where('stratum', SkillStratum::High)->orderBy('position')->first();

        $this->actingAs(User::factory()->create())
            ->post(route('participants.randomization.store', $participant))
            ->assertSessionHas('success');

        $participant->refresh();
        $this->assertSame(SkillStratum::High, $participant->stratum);
        $this->assertSame($expected->condition, $participant->condition);
        $this->assertSame(ParticipantStatus::Randomized, $participant->status);
        $this->assertSame($participant->id, $expected->fresh()->participant_id);
    }

    #[Test]
    public function randomization_requires_a_baseline_score(): void
    {
        $this->generate();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->create();

        $this->actingAs($this->admin)->post(route('participants.randomization.store', $participant))->assertSessionHas('error');

        $this->assertNull($participant->fresh()->condition);
    }

    #[Test]
    public function a_participant_cannot_be_randomized_twice(): void
    {
        $this->generate();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->withBaseline()->create();

        $this->actingAs($this->admin)->post(route('participants.randomization.store', $participant));
        $condition = $participant->fresh()->condition;
        $this->actingAs($this->admin)->post(route('participants.randomization.store', $participant))->assertSessionHas('error');

        $this->assertSame($condition, $participant->fresh()->condition);
        $this->assertSame(1, RandomizationSlot::whereNotNull('participant_id')->count());
    }

    #[Test]
    public function a_list_in_use_cannot_be_regenerated(): void
    {
        $this->generate();
        $participant = Participant::factory()->for($this->protocol, 'protocolVersion')->randomized(AssistanceCondition::DirectAi)->create();
        RandomizationSlot::first()->forceFill(['participant_id' => $participant->id])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.protocols.allocation.store', $this->protocol), ['slots_per_stratum' => 30, 'seed' => 99])
            ->assertSessionHas('error');
    }
}
