<?php

namespace Database\Factories;

use App\Enums\TaskKind;
use App\Models\ProtocolVersion;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'protocol_version_id' => ProtocolVersion::factory(),
            'session_number' => 1,
            'kind' => TaskKind::Learning,
            'title' => fake()->sentence(3),
            'sort_order' => 0,
            'prompt' => 'İç içe listedeki sayıların toplamını döndüren `toplam(veri)` fonksiyonunu yazın.',
            'starter_code' => "def toplam(veri):\n    pass\n",
            'reference_solution' => "def toplam(veri):\n    sonuc = 0\n    for e in veri:\n        sonuc += toplam(e) if isinstance(e, list) else e\n    return sonuc\n",
            'test_cases' => [
                ['call' => 'toplam([1, [2]])', 'expected' => '3', 'hidden' => false],
                ['call' => 'toplam([])', 'expected' => '0', 'hidden' => true],
            ],
            'max_score' => 10,
            'min_reading_seconds' => 0,
            'planning_seconds' => 30,
            'time_limit_seconds' => 600,
            'help_allowed' => true,
            'is_active' => true,
        ];
    }

    public function forSession(int $session): static
    {
        return $this->state(fn () => ['session_number' => $session, 'help_allowed' => $session === 1]);
    }

    public function multipleChoice(string $answer = 'a'): static
    {
        return $this->state(fn () => [
            'kind' => TaskKind::Baseline,
            'session_number' => 0,
            'starter_code' => null,
            'reference_solution' => null,
            'test_cases' => null,
            'mcq_options' => [['key' => 'a', 'text' => '10'], ['key' => 'b', 'text' => '15']],
            'mcq_answer' => $answer,
            'max_score' => 1,
            'planning_seconds' => 0,
            'help_allowed' => false,
        ]);
    }

    public function kind(TaskKind $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }
}
