<?php

namespace App\Services\Assistance;

use App\Enums\AssistanceCondition;
use App\Models\AiMessage;
use App\Models\TaskAttempt;

/**
 * Both AI conditions receive exactly the same task context (prompt, tests,
 * current code, last run output). Only the condition system prompt and, for
 * the Socratic tutor, the hint level differ. Neither model sees the reference
 * solution, so information parity between conditions is preserved.
 */
class PromptComposer
{
    /**
     * @param  array<string, mixed>  $snapshot  session ai_settings_snapshot
     */
    public function system(AssistanceCondition $condition, array $snapshot, TaskAttempt $attempt, ?int $hintLevel): string
    {
        $base = $condition === AssistanceCondition::DirectAi
            ? $snapshot['system_prompt_direct']
            : $snapshot['system_prompt_socratic'];

        $task = $attempt->task;
        $tests = collect($task->test_cases ?? [])
            ->reject(fn (array $test) => $test['hidden'] ?? false)
            ->map(fn (array $test) => "- `{$test['call']}` → `{$test['expected']}`")
            ->implode("\n");

        $context = <<<TXT
            ## Görev bağlamı
            Başlık: {$task->title}

            {$task->prompt}

            Başlangıç kodu:
            ```python
            {$task->starter_code}
            ```

            Örnek testler:
            {$tests}
            TXT;

        $hint = $condition === AssistanceCondition::SocraticAi && $hintLevel
            ? "\n\n## Bu yanıt için ipucu düzeyi: {$hintLevel}\n".$this->hintLevelGuide($hintLevel)
            : '';

        return trim($base)."\n\n".$context.$hint;
    }

    /**
     * Conversation history for this attempt plus the new participant turn,
     * with the current editor state attached so the model sees what the
     * participant sees.
     *
     * @return list<array{role: string, content: string}>
     */
    public function messages(TaskAttempt $attempt, string $question, ?string $code, ?string $lastOutput, int $historyTurns): array
    {
        $history = $attempt->aiMessages()
            ->whereNull('error')
            ->latest('id')
            ->take($historyTurns * 2)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();

        while ($history && $history[0]['role'] !== AiMessage::ROLE_USER) {
            array_shift($history);
        }

        $turn = "Katılımcının mesajı:\n{$question}";

        if ($code !== null && trim($code) !== '') {
            $turn .= "\n\nEditördeki mevcut kod:\n```python\n{$code}\n```";
        }

        if ($lastOutput !== null && trim($lastOutput) !== '') {
            $turn .= "\n\nSon çalıştırma çıktısı:\n```\n".mb_substr($lastOutput, 0, 2000)."\n```";
        }

        return [...$history, ['role' => AiMessage::ROLE_USER, 'content' => $turn]];
    }

    /**
     * Hints escalate with the number of replies already given in this task.
     */
    public function hintLevel(TaskAttempt $attempt): int
    {
        $given = $attempt->aiMessages()->where('role', AiMessage::ROLE_ASSISTANT)->whereNull('error')->count();

        return match (true) {
            $given < 2 => 1,
            $given < 4 => 2,
            default => 3,
        };
    }

    private function hintLevelGuide(int $level): string
    {
        return match ($level) {
            1 => 'Yalnızca yönlendirici soru sor. Kod parçası, fonksiyon adı önerisi veya adım listesi verme.',
            2 => 'Katılımcının kodundaki ilgili bölgeye dikkat çek ve neyi kontrol etmesi gerektiğini soru olarak sor. Kod yazma.',
            default => 'Çözüm adımlarını sözel olarak sırala, her adımı katılımcının kendi koduyla eşleştirmesini iste. Çalışır kod, tam fonksiyon veya düzeltilmiş satır verme.',
        };
    }
}
