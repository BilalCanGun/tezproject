<?php

namespace App\Services\Analytics;

use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Enums\TaskKind;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Rating;
use App\Models\TaskAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * One analysis-ready row per participant (wide format). The primary
 * confirmatory outcome is s2_far_transfer_pct: delayed, unassisted far
 * transfer. Everything else is secondary or descriptive.
 */
class OutcomeDataset
{
    /**
     * Column => description, exported alongside the data as a codebook.
     *
     * @var array<string, string>
     */
    public const DICTIONARY = [
        'participant_code' => 'Kodlanmış katılımcı numarası',
        'protocol_code' => 'Protokol sürümü kodu',
        'phase' => 'pilot | main',
        'status' => 'Katılımcı son durumu',
        'condition' => 'direct_ai | socratic_ai | standard_resource',
        'stratum' => 'Ön test tabakası (low | medium | high)',
        'form_order' => 'Görev formu sırası: AB = 1. oturum A, 2. oturum B; BA = tersi',
        'baseline_pct' => 'Ön değerlendirme başarı yüzdesi (kovaryat)',
        'concept_precheck_percent' => 'Kavram ön kontrolü başarı yüzdesi (ön testten ayrı; tabakalamaya girmez)',
        'concept_prior_knowledge_flag' => '1 = kavram ön kontrolü eşiği aşıldı, kavramı önceden biliyor olabilir (otomatik dışlama yok, araştırmacı karar verir)',
        'age' => 'Yaş',
        'gender' => 'Cinsiyet (beyan)',
        'occupation_type' => 'student | employee | other',
        'programming_experience_months' => 'Programlama deneyimi (ay)',
        'delay_days' => '1. ve 2. oturum arası gün',
        's1_duration_min' => '1. oturum süresi (dk)',
        's2_duration_min' => '2. oturum süresi (dk)',
        's1_learning_pct' => '1. oturum öğrenme görevleri başarı %',
        's1_near_transfer_pct' => '1. oturum yakın transfer %',
        's1_far_transfer_pct' => '1. oturum uzak transfer % (yardımla)',
        's1_debugging_pct' => '1. oturum hata bulma/düzeltme %',
        's1_explanation_score' => '1. oturum açıklama puanı (insan puanlayıcı ortalaması)',
        's2_far_transfer_pct' => 'BİRİNCİL SONUÇ: 2. oturum yardımsız uzak transfer % (otomatik test puanı)',
        's2_far_transfer_reviewed_pct' => '2. oturum uzak transfer %, puanlama anahtarına göre insan puanı (kod puanlaması yapılan denemeler)',
        's2_near_transfer_pct' => '2. oturum yardımsız yakın transfer %',
        's2_debugging_pct' => '2. oturum hata bulma/düzeltme %',
        's2_recall_pct' => '2. oturum gecikmeli hatırlama %',
        's2_explanation_score' => '2. oturum açıklama puanı (insan puanlayıcı ortalaması)',
        's1_help_requests' => '1. oturumda toplam YZ isteği',
        's1_resource_views' => '1. oturumda toplam kaynak görüntüleme',
        's1_self_written_ratio' => '1. oturum yazılan / (yazılan+yapıştırılan) karakter oranı',
        's1_ai_pasted_chars' => '1. oturum YZ yanıtından yapıştırılan karakter',
        's2_runs_after_failure' => '2. oturum: ilk başarısız çalıştırmadan sonra kod değiştirilerek yapılan çalıştırma sayısı',
        's2_seconds_after_failure' => '2. oturum: ilk başarısızlıktan teslime kadar geçen süre (ortalama, sn)',
        's2_total_runs' => '2. oturum toplam kod çalıştırma',
        's2_gave_up' => '2. oturumda bırakılan görev sayısı',
        's1_quality' => '1. oturum veri kalitesi kararı',
        's2_quality' => '2. oturum veri kalitesi kararı',
        'included_primary' => 'Birincil analize dahil (1 = 2. oturumu tamamladı ve dışlanmadı)',
    ];

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(ProtocolVersion $protocol): LazyCollection
    {
        return Participant::query()
            ->where('protocol_version_id', $protocol->id)
            ->whereNotNull('condition')
            ->with(['protocolVersion', 'sessions.attempts.task', 'sessions.attempts.ratings', 'sessions.attempts.codeReviews', 'sessions.qualityReview'])
            ->orderBy('code')
            ->lazy(50)
            ->map(fn (Participant $participant) => $this->row($participant, $protocol));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Participant $participant, ProtocolVersion $protocol): array
    {
        $first = $participant->session(SessionKind::Learning);
        $second = $participant->session(SessionKind::Delayed);
        $firstAttempts = $first?->attempts ?? collect();
        $secondCoding = ($second?->attempts ?? collect())->filter(fn (TaskAttempt $attempt) => $attempt->task->kind->isCoding());
        $failureSeconds = $secondCoding->map->secondsAfterFirstFailure()->filter(fn ($v) => $v !== null)->values()->all();

        return [
            'participant_code' => $participant->code,
            'protocol_code' => $protocol->code,
            'phase' => $protocol->phase->value,
            'status' => $participant->status->value,
            'condition' => $participant->condition?->value,
            'stratum' => $participant->stratum?->value,
            'form_order' => $participant->form_order,
            'baseline_pct' => $participant->baselinePercentage(),
            'concept_precheck_percent' => $participant->conceptPrecheckPercentage(),
            'concept_prior_knowledge_flag' => $participant->concept_precheck_max ? (int) $participant->mayKnowConcept() : null,
            'age' => $participant->age,
            'gender' => $participant->gender,
            'occupation_type' => $participant->occupation_type,
            'programming_experience_months' => $participant->programming_experience_months,
            'delay_days' => $first?->started_at && $second?->started_at ? round($first->started_at->diffInHours($second->started_at) / 24, 1) : null,
            's1_duration_min' => $first?->durationMinutes(),
            's2_duration_min' => $second?->durationMinutes(),
            's1_learning_pct' => $this->percent($first, TaskKind::Learning),
            's1_near_transfer_pct' => $this->percent($first, TaskKind::NearTransfer),
            's1_far_transfer_pct' => $this->percent($first, TaskKind::FarTransfer),
            's1_debugging_pct' => $this->percent($first, TaskKind::Debugging),
            's1_explanation_score' => $this->explanationScore($first),
            's2_far_transfer_pct' => $this->percent($second, TaskKind::FarTransfer),
            's2_far_transfer_reviewed_pct' => $this->reviewedPercent($second, TaskKind::FarTransfer),
            's2_near_transfer_pct' => $this->percent($second, TaskKind::NearTransfer),
            's2_debugging_pct' => $this->percent($second, TaskKind::Debugging),
            's2_recall_pct' => $this->percent($second, TaskKind::DelayedRecall),
            's2_explanation_score' => $this->explanationScore($second),
            's1_help_requests' => $firstAttempts->sum('help_request_count'),
            's1_resource_views' => $firstAttempts->sum('resource_view_count'),
            's1_self_written_ratio' => $this->selfWritten($firstAttempts),
            's1_ai_pasted_chars' => $firstAttempts->sum('ai_pasted_chars'),
            's2_runs_after_failure' => $second ? $secondCoding->sum('changed_runs_after_failure') : null,
            's2_seconds_after_failure' => $failureSeconds ? round(Statistics::mean($failureSeconds), 1) : null,
            's2_total_runs' => $second ? $secondCoding->sum('run_count') : null,
            's2_gave_up' => $second ? $secondCoding->where('end_reason', TaskAttempt::END_GAVE_UP)->count() : null,
            's1_quality' => $first?->qualityReview?->verdict?->value,
            's2_quality' => $second?->qualityReview?->verdict?->value,
            'included_primary' => (int) ($second?->status === SessionStatus::Completed && $participant->status !== ParticipantStatus::Excluded),
        ];
    }

    private function percent(?ExperimentSession $session, TaskKind $kind): ?float
    {
        $attempts = $session?->attempts->filter(fn (TaskAttempt $attempt) => $attempt->task->kind === $kind && $attempt->submitted_at);

        if (! $attempts || $attempts->isEmpty()) {
            return null;
        }

        $max = $attempts->sum(fn (TaskAttempt $attempt) => $attempt->task->max_score);

        return $max > 0 ? round($attempts->sum('auto_score') / $max * 100, 1) : null;
    }

    /**
     * Percentage from human code reviews; attempts not yet reviewed are left
     * out, so the value is null until at least one attempt is reviewed.
     */
    private function reviewedPercent(?ExperimentSession $session, TaskKind $kind): ?float
    {
        $attempts = $session?->attempts->filter(fn (TaskAttempt $attempt) => $attempt->task->kind === $kind && $attempt->submitted_at && $attempt->reviewedScore() !== null);

        if (! $attempts || $attempts->isEmpty()) {
            return null;
        }

        $max = $attempts->sum(fn (TaskAttempt $attempt) => $attempt->task->max_score);

        return $max > 0 ? round($attempts->sum(fn (TaskAttempt $attempt) => $attempt->reviewedScore()) / $max * 100, 1) : null;
    }

    private function explanationScore(?ExperimentSession $session): ?float
    {
        $ratings = $session?->attempts
            ->filter(fn (TaskAttempt $attempt) => $attempt->task->kind === TaskKind::Explanation)
            ->flatMap->ratings
            ->where('rater_type', Rating::TYPE_HUMAN);

        return $ratings && $ratings->isNotEmpty() ? round($ratings->avg('total'), 2) : null;
    }

    /**
     * @param  Collection<int, TaskAttempt>  $attempts
     */
    private function selfWritten(Collection $attempts): ?float
    {
        $typed = $attempts->sum('typed_chars');
        $pasted = $attempts->sum('pasted_chars');

        return $typed + $pasted > 0 ? round($typed / ($typed + $pasted), 3) : null;
    }
}
