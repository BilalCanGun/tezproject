<?php

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\ExperimentSession;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExperimentSession>
 */
class ExperimentSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'session_number' => 1,
            'status' => SessionStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
        ];
    }
}
