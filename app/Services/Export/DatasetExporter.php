<?php

namespace App\Services\Export;

use App\Enums\EventType;
use App\Models\AiMessage;
use App\Models\InteractionEvent;
use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Models\TaskAttempt;
use App\Services\Analytics\OutcomeDataset;
use App\Services\Scoring\RatingQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams analysis-ready CSV files keyed by participant code only. Contact
 * data is never part of any export.
 */
class DatasetExporter
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const DATASETS = [
        'outcomes' => ['label' => 'Birincil ve ikincil sonuçlar (geniş format)', 'description' => 'Katılımcı başına bir satır; birincil sonuç s2_far_transfer_pct.'],
        'codebook' => ['label' => 'Değişken sözlüğü', 'description' => 'Sonuç dosyasındaki her sütunun tanımı.'],
        'attempts' => ['label' => 'Görev denemeleri (uzun format)', 'description' => 'Görev başına faz süreleri, puanlar, çalıştırma ve ısrar göstergeleri.'],
        'events' => ['label' => 'Etkileşim olayları ve markerlar', 'description' => 'Zaman damgalı tüm olaylar; LSL zamanı ve AOI koordinatları dahil.'],
        'ai_messages' => ['label' => 'YZ konuşmaları (anonimleştirilmiş)', 'description' => 'İstem/yanıt metinleri ve manipülasyon kontrol ölçümleri.'],
        'ratings' => ['label' => 'Açıklama puanları', 'description' => 'İnsan ve LLM puanlayıcı ölçüt puanları.'],
        'markers' => ['label' => 'Marker kod tablosu', 'description' => 'LSL marker kodu ↔ olay eşlemesi.'],
    ];

    public function __construct(
        private OutcomeDataset $outcomes,
        private Anonymizer $anonymizer,
    ) {}

    public function stream(string $dataset, ProtocolVersion $protocol): StreamedResponse
    {
        [$header, $rows] = match ($dataset) {
            'outcomes' => [array_keys(OutcomeDataset::DICTIONARY), $this->outcomes->rows($protocol)],
            'codebook' => [['variable', 'description'], collect(OutcomeDataset::DICTIONARY)->map(fn ($description, $variable) => [$variable, $description])->values()],
            'attempts' => [$this->attemptHeader(), $this->attemptRows($protocol)],
            'events' => [['participant_code', 'session_number', 'sequence', 'type', 'marker_code', 'attempt_id', 'client_epoch_ms', 'client_perf_ms', 'lsl_time', 'payload_json'], $this->eventRows($protocol)],
            'ai_messages' => [['participant_code', 'condition', 'attempt_id', 'task_kind', 'role', 'created_at', 'content', 'provider', 'model', 'hint_level', 'latency_ms', 'input_tokens', 'output_tokens', 'guard_action', 'code_lines', 'question_count', 'full_solution', 'pre_guard_leak', 'error'], $this->aiRows($protocol)],
            'ratings' => [['blind_id', 'attempt_id', 'participant_code', 'session_number', 'rater_type', 'rater_id', 'total', 'scores_json'], $this->ratingRows($protocol)],
            'markers' => [['marker_code', 'event_type', 'description'], collect(EventType::cases())->filter(fn (EventType $type) => $type->markerCode() !== null)->map(fn (EventType $type) => [$type->markerCode(), $type->value, $type->description()])->sortBy(0)->values()],
            default => throw new InvalidArgumentException("Bilinmeyen veri seti: {$dataset}"),
        };

        $filename = Str::slug($protocol->code).'_'.$dataset.'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => is_bool($value) ? (int) $value : $value, array_values($row)), escape: '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    private function attemptHeader(): array
    {
        return [
            'participant_code', 'condition', 'session_number', 'attempt_id', 'task_id', 'task_kind', 'isomorph_group',
            'help_available', 'end_reason', 'auto_score', 'max_score', 'tests_passed', 'tests_total',
            'reading_sec', 'planning_sec', 'coding_sec', 'checking_sec',
            'run_count', 'failed_run_count', 'changed_runs_after_failure', 'seconds_after_first_failure',
            'typed_chars', 'deleted_chars', 'pasted_chars', 'ai_pasted_chars', 'self_written_ratio',
            'help_request_count', 'resource_view_count', 'planning_input_violations', 'focus_loss_count',
        ];
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    private function attemptRows(ProtocolVersion $protocol): iterable
    {
        $seconds = fn ($from, $to) => $from && $to ? round($from->diffInMilliseconds($to) / 1000, 2) : null;

        return $this->attemptQuery($protocol)
            ->with(['task', 'session.participant'])
            ->lazy(200)
            ->map(fn (TaskAttempt $attempt) => [
                $attempt->session->participant->code,
                $attempt->session->condition?->value,
                $attempt->session->session_number,
                $attempt->id,
                $attempt->task_id,
                $attempt->task->kind->value,
                $attempt->task->isomorph_group,
                $attempt->help_available,
                $attempt->end_reason,
                $attempt->auto_score,
                $attempt->task->max_score,
                $attempt->tests_passed,
                $attempt->tests_total,
                $seconds($attempt->started_at, $attempt->planning_started_at ?? $attempt->coding_started_at ?? $attempt->submitted_at),
                $seconds($attempt->planning_started_at, $attempt->coding_started_at),
                $seconds($attempt->coding_started_at, $attempt->checking_started_at ?? $attempt->submitted_at),
                $seconds($attempt->checking_started_at, $attempt->submitted_at),
                $attempt->run_count,
                $attempt->failed_run_count,
                $attempt->changed_runs_after_failure,
                $attempt->secondsAfterFirstFailure(),
                $attempt->typed_chars,
                $attempt->deleted_chars,
                $attempt->pasted_chars,
                $attempt->ai_pasted_chars,
                $attempt->selfWrittenRatio(),
                $attempt->help_request_count,
                $attempt->resource_view_count,
                $attempt->planning_input_violations,
                $attempt->focus_loss_count,
            ]);
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    private function eventRows(ProtocolVersion $protocol): iterable
    {
        return InteractionEvent::query()
            ->whereHas('session.participant', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->with('session:id,participant_id,session_number', 'session.participant:id,code')
            ->orderBy('experiment_session_id')
            ->orderBy('sequence')
            ->lazyById(1000)
            ->map(fn (InteractionEvent $event) => [
                $event->session->participant->code,
                $event->session->session_number,
                $event->sequence,
                $event->type,
                $event->marker_code,
                $event->task_attempt_id,
                $event->client_epoch_ms,
                $event->client_perf_ms,
                $event->lsl_time,
                $event->payload ? json_encode($this->scrubPayload($event->payload), JSON_UNESCAPED_UNICODE) : null,
            ]);
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    private function aiRows(ProtocolVersion $protocol): iterable
    {
        return AiMessage::query()
            ->whereHas('session.participant', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->with(['session.participant:id,code', 'attempt.task:id,kind'])
            ->orderBy('id')
            ->lazyById(500)
            ->map(fn (AiMessage $message) => [
                $message->session->participant->code,
                $message->condition->value,
                $message->task_attempt_id,
                $message->attempt->task->kind->value,
                $message->role,
                $message->created_at?->format('Y-m-d H:i:s.v'),
                $this->anonymizer->scrub($message->content),
                $message->provider,
                $message->model,
                $message->hint_level,
                $message->latency_ms,
                $message->input_tokens,
                $message->output_tokens,
                $message->guard_action,
                $message->metrics['code_lines'] ?? null,
                $message->metrics['question_count'] ?? null,
                $message->metrics['full_solution'] ?? null,
                isset($message->metrics['pre_guard']),
                $message->error,
            ]);
    }

    /**
     * @return iterable<array<int, mixed>>
     */
    private function ratingRows(ProtocolVersion $protocol): iterable
    {
        return Rating::query()
            ->whereHas('attempt.task', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->with('attempt.session.participant:id,code')
            ->orderBy('id')
            ->lazyById(500)
            ->map(fn (Rating $rating) => [
                RatingQueue::blindLabel($rating->attempt),
                $rating->task_attempt_id,
                $rating->attempt->session->participant->code,
                $rating->attempt->session->session_number,
                $rating->rater_type,
                $rating->rater_id,
                $rating->total,
                json_encode($rating->scores),
            ]);
    }

    /**
     * @return Builder<TaskAttempt>
     */
    private function attemptQuery(ProtocolVersion $protocol): Builder
    {
        return TaskAttempt::query()
            ->whereHas('session.participant', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrubPayload(array $payload): array
    {
        return array_map(fn ($value) => is_string($value) ? $this->anonymizer->scrub($value) : $value, $payload);
    }
}
