<?php

namespace Database\Factories;

use App\Enums\ProtocolPhase;
use App\Models\ProtocolVersion;
use App\Services\Assistance\DefaultPrompts;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtocolVersion>
 */
class ProtocolVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST-'.fake()->unique()->numerify('###'),
            'name' => 'Test protokolü',
            'phase' => ProtocolPhase::Pilot,
            'settings' => config('experiment.protocol_defaults'),
            'system_prompt_direct' => DefaultPrompts::DIRECT,
            'system_prompt_socratic' => DefaultPrompts::SOCRATIC,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function main(): static
    {
        return $this->state(fn () => ['phase' => ProtocolPhase::Main]);
    }
}
