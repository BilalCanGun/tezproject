<?php

namespace App\Services\Analytics;

use App\Enums\AssistanceCondition;
use App\Enums\SessionKind;
use App\Models\AiMessage;
use App\Models\ProtocolVersion;
use App\Models\TaskAttempt;
use Illuminate\Support\Collection;

/**
 * Verifies that the conditions actually differed in the way the design
 * requires: full-code replies in the direct condition, questions and no
 * solution leakage in the Socratic condition, and the corresponding
 * behavioural footprint (self-written vs. pasted code) on the participant side.
 */
class ManipulationCheck
{
    /**
     * @return array{conditions: array<string, array<string, mixed>>, max_code_lines: int}
     */
    public function forProtocol(ProtocolVersion $protocol): array
    {
        $replies = AiMessage::query()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->whereNull('error')
            ->whereHas('session.participant', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->get(['id', 'task_attempt_id', 'condition', 'metrics', 'guard_action', 'hint_level', 'latency_ms', 'output_tokens']);

        $attempts = TaskAttempt::query()
            ->where('help_available', true)
            ->whereNotNull('submitted_at')
            ->whereHas('session', fn ($query) => $query
                ->where('session_number', SessionKind::Learning->value)
                ->whereHas('participant', fn ($inner) => $inner->where('protocol_version_id', $protocol->id)))
            ->with('session:id,condition')
            ->get();

        $maxCodeLines = (int) $protocol->setting('assistant.socratic_max_code_lines');
        $conditions = [];

        foreach (AssistanceCondition::cases() as $condition) {
            $conditionReplies = $replies->where('condition', $condition);
            $conditionAttempts = $attempts->filter(fn (TaskAttempt $attempt) => $attempt->session->condition === $condition);

            $conditions[$condition->value] = [
                'label' => $condition->label(),
                'attempts' => $conditionAttempts->count(),
                ...$this->behaviour($conditionAttempts),
                ...($condition->usesAssistant() ? $this->replyMetrics($conditionReplies, $conditionAttempts, $maxCodeLines) : []),
            ];
        }

        return ['conditions' => $conditions, 'max_code_lines' => $maxCodeLines];
    }

    /**
     * @param  Collection<int, AiMessage>  $replies
     * @param  Collection<int, TaskAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function replyMetrics(Collection $replies, Collection $attempts, int $maxCodeLines): array
    {
        $count = $replies->count();
        $metric = fn (string $key) => $replies->map(fn (AiMessage $reply) => $reply->metrics[$key] ?? 0)->all();

        $guarded = $replies->filter(fn (AiMessage $reply) => isset($reply->metrics['pre_guard']));
        $leaked = $replies->filter(fn (AiMessage $reply) => ($reply->metrics['full_solution'] ?? false)
            || (($reply->metrics['code_lines'] ?? 0) + ($reply->metrics['unfenced_code_lines'] ?? 0)) > $maxCodeLines);

        return [
            'replies' => $count,
            'replies_per_attempt' => $attempts->count() ? round($count / $attempts->count(), 2) : null,
            'full_code_rate' => $count ? round($replies->filter(fn ($r) => ($r->metrics['code_lines'] ?? 0) >= 3)->count() / $count, 3) : null,
            'direct_solution_rate' => $count ? round($replies->filter(fn ($r) => $r->metrics['full_solution'] ?? false)->count() / $count, 3) : null,
            'mean_code_lines' => $count ? round(Statistics::mean($metric('code_lines')), 2) : null,
            'mean_questions' => $count ? round(Statistics::mean($metric('question_count')), 2) : null,
            'pre_guard_leak_rate' => $count ? round($guarded->count() / $count, 3) : null,
            'post_guard_leak_rate' => $count ? round($leaked->count() / $count, 3) : null,
            'guard_regenerated' => $replies->where('guard_action', 'regenerated')->count(),
            'guard_redacted' => $replies->where('guard_action', 'redacted')->count(),
            'hints_by_level' => $replies->whereNotNull('hint_level')->countBy('hint_level')->sortKeys()->all(),
            'median_latency_ms' => Statistics::median($replies->pluck('latency_ms')->filter()->values()->all()),
        ];
    }

    /**
     * @param  Collection<int, TaskAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function behaviour(Collection $attempts): array
    {
        $ratios = $attempts->map(fn (TaskAttempt $attempt) => $attempt->selfWrittenRatio())->filter(fn ($ratio) => $ratio !== null)->values()->all();
        $aiPasted = $attempts->map(function (TaskAttempt $attempt) {
            $total = $attempt->typed_chars + $attempt->pasted_chars;

            return $total > 0 ? $attempt->ai_pasted_chars / $total : null;
        })->filter(fn ($ratio) => $ratio !== null)->values()->all();

        return [
            'mean_help_requests' => $attempts->count() ? round($attempts->avg('help_request_count'), 2) : null,
            'mean_resource_views' => $attempts->count() ? round($attempts->avg('resource_view_count'), 2) : null,
            'self_written_ratio' => $ratios ? round(Statistics::mean($ratios), 3) : null,
            'ai_pasted_ratio' => $aiPasted ? round(Statistics::mean($aiPasted), 3) : null,
        ];
    }
}
