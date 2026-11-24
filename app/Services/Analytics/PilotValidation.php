<?php

namespace App\Services\Analytics;

use App\Enums\AssistanceCondition;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Enums\TaskKind;
use App\Http\Requests\TaskRequest;
use App\Models\ExperimentSession;
use App\Models\ProtocolVersion;
use App\Models\QualityReview;
use App\Models\Task;
use App\Models\TaskAttempt;
use Illuminate\Support\Collection;

/**
 * Produces "Tablo 1" of the application: each validated component, its control
 * criterion, the pre-set acceptance threshold, what the pilot observed and
 * whether the threshold was met.
 */
class PilotValidation
{
    public function __construct(private ManipulationCheck $manipulationCheck) {}

    /**
     * @return list<array{component: string, criterion: string, threshold: string, observed: string, status: string}>
     */
    public function table(ProtocolVersion $protocol): array
    {
        $sessions = ExperimentSession::query()
            ->whereHas('participant', fn ($query) => $query->where('protocol_version_id', $protocol->id))
            ->with(['qualityReview', 'attempts.task'])
            ->get();

        $learning = $sessions->where('session_number', SessionKind::Learning->value)->where('status', SessionStatus::Completed);
        $delayed = $sessions->where('session_number', SessionKind::Delayed->value)->where('status', SessionStatus::Completed);
        $attempts = $sessions->flatMap->attempts->filter(fn (TaskAttempt $attempt) => ! $attempt->task->kind->isPreAssessment());
        $reviews = $sessions->pluck('qualityReview')->filter();
        $manipulation = $this->manipulationCheck->forProtocol($protocol)['conditions'];
        $p = fn (string $key) => $protocol->setting("pilot.{$key}");
        $q = fn (string $key) => $protocol->setting("quality.{$key}");

        $farScores = $attempts
            ->filter(fn (TaskAttempt $attempt) => $attempt->task->kind === TaskKind::FarTransfer && $attempt->submitted_at)
            ->map(fn (TaskAttempt $attempt) => $attempt->task->max_score > 0 ? $attempt->auto_score / $attempt->task->max_score * 100 : 0)
            ->values()->all();

        $completion = $attempts->filter(fn (TaskAttempt $attempt) => $attempt->submitted_at)->count();
        $completedNormally = $attempts->where('end_reason', TaskAttempt::END_SUBMITTED)->count();
        $planningAttempts = $attempts->filter(fn (TaskAttempt $attempt) => $attempt->planning_started_at !== null);

        $direct = $manipulation[AssistanceCondition::DirectAi->value];
        $socratic = $manipulation[AssistanceCondition::SocraticAi->value];

        return [
            $this->row(
                'Oturum süresi (1. oturum)',
                'Medyan oturum süresi',
                '≤ '.$protocol->setting('session.session1_target_minutes').' dk',
                Statistics::median($learning->map->durationMinutes()->filter()->values()->all()),
                fn ($v) => $v <= $protocol->setting('session.session1_target_minutes'),
                'dk',
            ),
            $this->row(
                'Oturum süresi (2. oturum)',
                'Medyan oturum süresi',
                '≤ '.$protocol->setting('session.session2_target_minutes').' dk',
                Statistics::median($delayed->map->durationMinutes()->filter()->values()->all()),
                fn ($v) => $v <= $protocol->setting('session.session2_target_minutes'),
                'dk',
            ),
            $this->row(
                'Katılımcı uygulanabilirliği',
                'Süre dolmadan veya bırakılmadan teslim edilen görev oranı',
                '≥ %'.($p('min_task_completion_rate') * 100),
                $completion ? $completedNormally / $completion : null,
                fn ($v) => $v >= $p('min_task_completion_rate'),
                '%',
            ),
            $this->row(
                'Görev zorluğu (uzak transfer)',
                'Ortalama başarı yüzdesi taban/tavan etkisi göstermemeli',
                '%'.$p('far_transfer_floor_percent').'–%'.$p('far_transfer_ceiling_percent'),
                Statistics::mean($farScores),
                fn ($v) => $v >= $p('far_transfer_floor_percent') && $v <= $p('far_transfer_ceiling_percent'),
                'puan%',
            ),
            $this->row(
                'Doğrudan YZ koşulu',
                'Tam kod (≥3 satır) içeren yanıt oranı',
                '≥ %'.($p('min_direct_full_code_rate') * 100),
                $direct['full_code_rate'] ?? null,
                fn ($v) => $v >= $p('min_direct_full_code_rate'),
                '%',
            ),
            $this->row(
                'Doğrudan YZ koşulu — açık talepler',
                'Açık çözüm talebinden sonra tam çözüm verilen yanıt oranı',
                '≥ %'.($p('min_direct_request_fulfilment_rate') * 100),
                $direct['explicit_request_fulfilment_rate'] ?? null,
                fn ($v) => $v >= $p('min_direct_request_fulfilment_rate'),
                '%',
            ),
            $this->row(
                'Sokratik YZ koşulu — sızıntı',
                'Koruma sonrası çözüm/kod sızan yanıt oranı',
                '≤ %'.($p('max_socratic_leak_rate') * 100),
                $socratic['post_guard_leak_rate'] ?? null,
                fn ($v) => $v <= $p('max_socratic_leak_rate'),
                '%',
            ),
            $this->row(
                'Sokratik YZ koşulu — soru yöneltme',
                'Yanıt başına ortalama soru sayısı',
                '≥ '.$p('min_socratic_questions_per_reply'),
                $socratic['mean_questions'] ?? null,
                fn ($v) => $v >= $p('min_socratic_questions_per_reply'),
            ),
            $this->row(
                'Sessiz planlama fazı',
                'Görev başına klavye/fare ihlali',
                '≤ '.$p('max_planning_violations_per_task'),
                $planningAttempts->count() ? $planningAttempts->avg('planning_input_violations') : null,
                fn ($v) => $v <= $p('max_planning_violations_per_task'),
            ),
            $this->row(
                'EEG veri kalitesi',
                'Kullanılabilir epok oranı (oturum ortalaması)',
                '≥ %'.($q('min_eeg_usable_epoch_ratio') * 100),
                $this->meanOf($reviews, 'eeg_usable_epoch_ratio'),
                fn ($v) => $v >= $q('min_eeg_usable_epoch_ratio'),
                '%',
            ),
            $this->row(
                'Göz izleme veri kalitesi',
                'Geçerli bakış örneği oranı',
                '≥ %'.($q('min_gaze_valid_ratio') * 100),
                $this->meanOf($reviews, 'gaze_valid_ratio'),
                fn ($v) => $v >= $q('min_gaze_valid_ratio'),
                '%',
            ),
            $this->row(
                'Bileklik veri kalitesi',
                'Veri kaybı oranı',
                '≤ %'.($q('max_wearable_loss_ratio') * 100),
                $this->meanOf($reviews, 'wearable_loss_ratio'),
                fn ($v) => $v <= $q('max_wearable_loss_ratio'),
                '%',
            ),
            $this->row(
                'Olay marker bütünlüğü',
                'LSL’e ulaşıp onaylanan marker oranı (en kötü oturum)',
                '≥ %'.($q('min_marker_integrity') * 100),
                $reviews->map(fn (QualityReview $review) => $review->markerIntegrity())->filter(fn ($v) => $v !== null)->min(),
                fn ($v) => $v >= $q('min_marker_integrity'),
                '%',
            ),
        ];
    }

    /**
     * Design checks on the task battery. They never block freezing; they are
     * shown to the researcher as warnings on the protocol pages.
     *
     * @return list<string>
     */
    public function designWarnings(ProtocolVersion $protocol): array
    {
        $tasks = $protocol->tasks()->where('is_active', true)->get();
        $warnings = [];
        $count = fn (TaskKind $kind, string $form) => $tasks->where('form', $form)->where('kind', $kind)->count();

        foreach (['A', 'B'] as $form) {
            if (($far = $count(TaskKind::FarTransfer, $form)) < 3) {
                $warnings[] = "Form {$form} içinde {$far} uzak transfer görevi var; birincil sonuç tek göreve dayanmasın diye her formda en az 3 olmalı.";
            }
        }

        foreach (Task::FORM_KINDS as $kind) {
            if ($count($kind, 'A') !== $count($kind, 'B')) {
                $warnings[] = "{$kind->label()} görev sayısı formlar arasında eşit değil (A: {$count($kind, 'A')}, B: {$count($kind, 'B')}).";
            }
        }

        $recall = $tasks->where('kind', TaskKind::DelayedRecall)->where('session_number', SessionKind::Delayed->value);

        if ($recall->isEmpty()) {
            $warnings[] = '2. oturumda gecikmeli hatırlama görevi yok.';
        }

        $farGroups = $tasks->where('kind', TaskKind::FarTransfer)->pluck('isomorph_group')->filter()->unique();

        foreach ($recall->filter(fn (Task $task) => $task->isomorph_group && $farGroups->contains($task->isomorph_group)) as $task) {
            $warnings[] = "Gecikmeli hatırlama görevi \"{$task->title}\" bir uzak transfer göreviyle aynı izomorf grubu ({$task->isomorph_group}) paylaşıyor; hatırlama ve uzak transfer ayrı görevlerle ölçülmeli.";
        }

        foreach ($tasks as $task) {
            $measures = $task->scoring_key['measures'] ?? null;
            $persistenceOnCoding = $measures === TaskRequest::MEASURE_PERSISTENCE && $task->kind->isCoding();

            if ($measures && $measures !== $task->kind->value && ! $persistenceOnCoding) {
                $warnings[] = "\"{$task->title}\" görevinin türü {$task->kind->label()}, ama puanlama anahtarında ölçtüğü değişken \"{$measures}\" olarak girilmiş.";
            }
        }

        $baseline = $tasks->where('session_number', SessionKind::Baseline->value)->where('kind', TaskKind::Baseline)->count();

        if ($baseline < 8 || $baseline > 12) {
            $warnings[] = "Ön değerlendirmede {$baseline} ön test maddesi var; genel programlama becerisi için 8–12 madde önerilir.";
        }

        if ($tasks->where('kind', TaskKind::ConceptPrecheck)->isEmpty()) {
            $warnings[] = 'Ön değerlendirmede kavram ön kontrolü yok; hedef kavramı önceden bilen katılımcılar ayırt edilemez.';
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, QualityReview>  $reviews
     */
    private function meanOf(Collection $reviews, string $column): ?float
    {
        $values = $reviews->pluck($column)->filter(fn ($v) => $v !== null)->values()->all();

        return Statistics::mean($values);
    }

    /**
     * @return array{component: string, criterion: string, threshold: string, observed: string, status: string}
     */
    private function row(string $component, string $criterion, string $threshold, int|float|null $observed, callable $passes, string $unit = ''): array
    {
        $formatted = match (true) {
            $observed === null => '—',
            $unit === '%' => '%'.number_format($observed * 100, 1, ',', '.'),
            $unit === 'puan%' => '%'.number_format($observed, 1, ',', '.'),
            default => number_format($observed, 2, ',', '.').($unit ? " {$unit}" : ''),
        };

        return [
            'component' => $component,
            'criterion' => $criterion,
            'threshold' => $threshold,
            'observed' => $formatted,
            'status' => $observed === null ? 'no_data' : ($passes($observed) ? 'pass' : 'fail'),
        ];
    }
}
