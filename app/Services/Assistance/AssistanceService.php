<?php

namespace App\Services\Assistance;

use App\Enums\AssistanceCondition;
use App\Enums\AssistantProvider;
use App\Enums\AttemptPhase;
use App\Models\AiMessage;
use App\Models\TaskAttempt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles one participant request to the assistant: builds the condition
 * prompt, calls the provider, measures the reply and, in the Socratic
 * condition, enforces the no-solution rule with a regenerate-then-redact guard.
 */
class AssistanceService
{
    public const MAX_REQUESTS_PER_ATTEMPT = 40;

    public function __construct(
        private AssistantClientFactory $clients,
        private PromptComposer $prompts,
        private ResponseAnalyzer $analyzer,
    ) {}

    public function ask(TaskAttempt $attempt, string $question, ?string $code, ?string $lastOutput): AiMessage
    {
        $session = $attempt->session;
        $condition = $session->condition;

        if (! $attempt->help_available || ! $condition?->usesAssistant()) {
            throw new RuntimeException('Bu görevde yapay zeka desteği bulunmuyor.');
        }

        if (! in_array($attempt->phase, [AttemptPhase::Coding, AttemptPhase::Checking], true)) {
            throw new RuntimeException('Asistan yalnızca kod yazma fazında kullanılabilir.');
        }

        if ($attempt->help_request_count >= self::MAX_REQUESTS_PER_ATTEMPT) {
            throw new RuntimeException('Bu görev için asistan istek sınırına ulaşıldı.');
        }

        $snapshot = $session->ai_settings_snapshot;
        $settings = $snapshot['assistant'];
        $hintLevel = $condition === AssistanceCondition::SocraticAi ? $this->prompts->hintLevel($attempt) : null;

        $request = new AssistantRequest(
            condition: $condition,
            system: $this->prompts->system($condition, $snapshot, $attempt, $hintLevel),
            messages: $this->prompts->messages($attempt, $question, $code, $lastOutput, (int) $settings['history_turns']),
            settings: [
                'provider' => $settings['provider'],
                'model' => $settings['model'],
                'temperature' => AssistantProvider::from($settings['provider'])->supportsTemperature() ? (float) $settings['temperature'] : null,
                'effort' => $settings['provider'] === AssistantProvider::Anthropic->value ? $settings['effort'] : null,
                'max_tokens' => (int) $settings['max_tokens'],
            ],
            hintLevel: $hintLevel,
            referenceSolution: $settings['provider'] === AssistantProvider::Scripted->value ? $attempt->task->reference_solution : null,
        );

        $attempt->aiMessages()->create([
            'experiment_session_id' => $session->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $question,
            'condition' => $condition,
            'created_at' => now(),
        ]);
        $attempt->increment('help_request_count');

        $client = $this->clients->make(AssistantProvider::from($settings['provider']));

        try {
            return $this->reply($client, $request, $attempt, $settings);
        } catch (AssistantUnavailable $exception) {
            Log::warning('Assistant request failed', ['attempt' => $attempt->id, 'error' => $exception->getMessage()]);

            return $attempt->aiMessages()->create([
                'experiment_session_id' => $session->id,
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => 'Asistana şu anda ulaşılamıyor. Lütfen birkaç saniye sonra tekrar deneyin.',
                'condition' => $condition,
                'provider' => $settings['provider'],
                'model' => $settings['model'],
                'request_params' => $request->auditParams(),
                'hint_level' => $hintLevel,
                'error' => mb_substr($exception->getMessage(), 0, 250),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function reply(AssistantClient $client, AssistantRequest $request, TaskAttempt $attempt, array $settings): AiMessage
    {
        $task = $attempt->task;
        $entryFunction = ResponseAnalyzer::entryFunctionFrom($task->starter_code);
        $threshold = (float) $settings['socratic_similarity_threshold'];

        $reply = $client->complete($request);
        $metrics = $this->analyzer->analyze($reply->text, $entryFunction, $task->reference_solution, $threshold);
        $content = $reply->text;
        $raw = null;
        $guardAction = null;
        $latency = $reply->latencyMs;

        if ($request->condition === AssistanceCondition::SocraticAi && $this->leaks($metrics, $settings)) {
            $raw = $reply->text;
            $metrics['pre_guard'] = $metrics;

            $retry = $client->complete(new AssistantRequest(
                condition: $request->condition,
                system: $request->system."\n\n## Düzeltme\nÖnceki taslak yanıtın kod veya hazır çözüm içeriyordu. Kod bloğu, fonksiyon tanımı veya düzeltilmiş satır vermeden yalnızca soru ve sözel ipucu ile yanıt ver.",
                messages: $request->messages,
                settings: $request->settings,
                hintLevel: $request->hintLevel,
            ));
            $latency += $retry->latencyMs;
            $retryMetrics = $this->analyzer->analyze($retry->text, $entryFunction, $task->reference_solution, $threshold);

            if ($this->leaks($retryMetrics, $settings)) {
                $content = $this->redactCode($retry->text);
                $guardAction = 'redacted';
                $retryMetrics = $this->analyzer->analyze($content, $entryFunction, $task->reference_solution, $threshold);
            } else {
                $content = $retry->text;
                $guardAction = 'regenerated';
            }

            $metrics = [...$retryMetrics, 'pre_guard' => $metrics['pre_guard']];
            $reply = $retry;
        }

        return $attempt->aiMessages()->create([
            'experiment_session_id' => $attempt->experiment_session_id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $content,
            'raw_content' => $raw,
            'condition' => $request->condition,
            'provider' => $settings['provider'],
            'model' => $reply->model ?? $settings['model'],
            'request_params' => $request->auditParams(),
            'hint_level' => $request->hintLevel,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'latency_ms' => $latency,
            'stop_reason' => $reply->stopReason,
            'metrics' => $metrics,
            'guard_action' => $guardAction,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $settings
     */
    private function leaks(array $metrics, array $settings): bool
    {
        return $metrics['full_solution']
            || ($metrics['code_lines'] + $metrics['unfenced_code_lines']) > (int) $settings['socratic_max_code_lines'];
    }

    private function redactCode(string $text): string
    {
        $text = preg_replace('/```.*?```/su', '_[Kod içeriği kaldırıldı — bu asistan hazır kod vermez.]_', $text) ?? $text;

        return trim($text)."\n\nKendi kodunuza bakarak şunu düşünün: bir sonraki adımda neyi kontrol etmeniz gerekiyor?";
    }
}
