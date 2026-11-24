<?php

namespace Database\Factories;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SkillStratum;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'protocol_version_id' => ProtocolVersion::factory(),
            'code' => 'P-'.fake()->unique()->numerify('####'),
            'status' => ParticipantStatus::Eligible,
            'consent_version' => 'v1',
            'consented_at' => now()->subDay(),
            'age' => fake()->numberBetween(18, 40),
            'programming_experience_months' => fake()->numberBetween(3, 48),
            'screening' => collect(Participant::SCREENING_ITEMS)->map(fn (array $item) => $item['expected'])->all(),
        ];
    }

    public function withBaseline(float $score = 6, float $max = 10): static
    {
        return $this->state(fn () => ['baseline_score' => $score, 'baseline_max' => $max]);
    }

    public function randomized(AssistanceCondition $condition, SkillStratum $stratum = SkillStratum::Medium, string $formOrder = 'AB'): static
    {
        return $this->withBaseline()->state(fn () => [
            'status' => ParticipantStatus::Randomized,
            'condition' => $condition,
            'form_order' => $formOrder,
            'stratum' => $stratum,
            'randomized_at' => now(),
        ]);
    }
}
