<?php

namespace App\Services\Scoring;

use App\Enums\AssistanceCondition;
use App\Enums\AssistantProvider;
use App\Models\Rating;
use App\Models\RubricCriterion;
use App\Models\TaskAttempt;
use App\Services\Assistance\AssistantClientFactory;
use App\Services\Assistance\AssistantRequest;
use App\Services\Assistance\AssistantUnavailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Secondary, automated rater for explanation quality. Its scores are stored
 * next to human ratings and only used after agreement with humans has been
 * reported; it is never the sole rater.
 */
class LlmRubricScorer
{
    public function __construct(private AssistantClientFactory $clients) {}

    public function score(TaskAttempt $attempt): Rating
    {
        $protocol = $attempt->task->protocolVersion;
        $criteria = $protocol->rubricCriteria()->orderBy('sort_order')->get();
        $settings = $protocol->resolvedSettings()['assistant'];
        $provider = AssistantProvider::from($settings['provider']);

        $scores = $provider === AssistantProvider::Scripted
            ? $this->heuristicScores($attempt, $criteria)
            : $this->modelScores($attempt, $criteria, $provider, $settings);

        return Rating::updateOrCreate(
            ['task_attempt_id' => $attempt->id, 'rater_id' => null, 'rater_type' => Rating::TYPE_LLM],
            [
                'scores' => $scores,
                'total' => array_sum($scores),
                'model' => $provider === AssistantProvider::Scripted ? 'heuristic-v1' : ($settings['model'] ?? $provider->value),
            ],
        );
    }

    /**
     * @param  Collection<int, RubricCriterion>  $criteria
     * @param  array<string, mixed>  $settings
     * @return array<string, int>
     */
    private function modelScores(TaskAttempt $attempt, Collection $criteria, AssistantProvider $provider, array $settings): array
    {
        $rubric = $criteria->map(function (RubricCriterion $criterion) {
            $anchors = collect($criterion->anchors ?? [])->map(fn ($text, $score) => "    {$score}: {$text}")->implode("\n");

            return "- {$criterion->key} ({$criterion->min_score}–{$criterion->max_score}): {$criterion->label}. {$criterion->description}\n{$anchors}";
        })->implode("\n");

        $system = <<<TXT
            Bir programlama öğrenme çalışmasında katılımcı açıklamalarını puanlayan bağımsız bir değerlendiricisin.
            Yalnızca aşağıdaki rubriği uygula. Yazım, dil bilgisi veya uzunluk puanı etkilemez.
            Yanıtını yalnızca şu biçimde tek bir JSON nesnesi olarak ver:
            {"scores": {"<ölçüt_anahtarı>": <tam sayı>, ...}}

            Rubrik:
            {$rubric}
            TXT;

        $elements = collect($attempt->task->explanationElements())->map(fn (string $element) => "- {$element}")->implode("\n");

        if ($elements !== '') {
            $system .= "\n\nBu görev için iyi bir açıklamada bulunması beklenen unsurlar (puanlarken rubrikle birlikte dikkate al):\n{$elements}";
        }

        $content = "Görev:\n{$attempt->task->prompt}\n\nKatılımcının açıklaması:\n\"\"\"\n{$attempt->explanation_text}\n\"\"\"";

        $reply = $this->clients->make($provider)->complete(new AssistantRequest(
            condition: AssistanceCondition::DirectAi,
            system: $system,
            messages: [['role' => 'user', 'content' => $content]],
            settings: [
                'provider' => $provider->value,
                'model' => $settings['model'],
                'temperature' => $provider->supportsTemperature() ? 0.0 : null,
                'effort' => $provider === AssistantProvider::Anthropic ? 'low' : null,
                'max_tokens' => 1500,
            ],
        ));

        if (! preg_match('/\{.*\}/su', $reply->text, $match) || ! is_array($decoded = json_decode($match[0], true))) {
            throw new AssistantUnavailable('LLM puanlayıcı geçerli JSON döndürmedi.');
        }

        return $criteria->mapWithKeys(fn (RubricCriterion $criterion) => [
            $criterion->key => (int) max($criterion->min_score, min($criterion->max_score, (int) data_get($decoded, "scores.{$criterion->key}", $criterion->min_score))),
        ])->all();
    }

    /**
     * Keyword heuristic so the rating and reliability pipeline can be
     * exercised in dry runs without any provider.
     *
     * @param  Collection<int, RubricCriterion>  $criteria
     * @return array<string, int>
     */
    private function heuristicScores(TaskAttempt $attempt, Collection $criteria): array
    {
        $text = Str::lower($attempt->explanation_text ?? '');
        $elements = $attempt->task->explanationElements();

        if ($elements !== []) {
            $matched = collect($elements)->filter(function (string $element) use ($text) {
                $words = collect(preg_split('/[^\p{L}\p{N}_]+/u', Str::lower($element)))->filter(fn ($word) => mb_strlen($word) >= 5);

                return $words->isNotEmpty() && $words->contains(fn ($word) => Str::contains($text, mb_substr($word, 0, 5)));
            })->count();

            return $criteria->mapWithKeys(fn (RubricCriterion $criterion) => [
                $criterion->key => (int) min($criterion->max_score, max($criterion->min_score, round($matched / count($elements) * $criterion->max_score))),
            ])->all();
        }

        $signals = [
            Str::contains($text, ['temel durum', 'durma koşulu', 'base case']),
            Str::contains($text, ['kendini çağır', 'kendisini çağır', 'özyinele', 'recursive', 'tekrar çağır']),
            Str::contains($text, ['liste', 'iç içe', 'alt']),
            Str::contains($text, ['boş', 'derin', 'sınır', 'tek eleman']),
        ];
        $level = count(array_filter($signals));

        return $criteria->mapWithKeys(fn (RubricCriterion $criterion) => [
            $criterion->key => (int) min($criterion->max_score, max($criterion->min_score, round($level / 4 * $criterion->max_score))),
        ])->all();
    }
}
