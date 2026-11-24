<?php

namespace App\Services\Sessions;

use App\Enums\AssistanceCondition;
use App\Enums\SessionKind;
use App\Enums\StepType;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns the protocol's task battery into the ordered step list a session runs
 * through. The plan is materialised per session so later protocol edits never
 * change what an already scheduled participant sees.
 */
class SessionPlanBuilder
{
    public function build(ExperimentSession $session): void
    {
        if ($session->steps()->exists()) {
            throw new RuntimeException('Bu oturumun adım planı zaten oluşturulmuş.');
        }

        $participant = $session->participant;
        $protocol = $participant->protocolVersion;
        $kind = $session->kind();
        $form = $this->formFor($participant, $kind, $protocol);
        $tasks = $protocol->tasks()
            ->where('is_active', true)
            ->where(function ($query) use ($kind, $form) {
                $query->where(fn ($query) => $query->whereNull('form')->where('session_number', $kind->value));

                if ($form) {
                    $query->orWhere('form', $form);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $tasks = $this->orderByKind($tasks, $protocol, $kind);

        if ($tasks->isEmpty()) {
            throw new RuntimeException("Protokolde {$kind->label()} için etkin görev bulunmuyor.");
        }

        $steps = match ($kind) {
            SessionKind::Baseline => $this->baselinePlan($tasks),
            SessionKind::Learning => $this->learningPlan($protocol, $session->condition, $tasks),
            SessionKind::Delayed => $this->delayedPlan($protocol, $tasks),
        };

        DB::transaction(function () use ($session, $steps) {
            foreach (array_values($steps) as $index => $step) {
                $session->steps()->create([...$step, 'position' => $index + 1]);
            }
        });
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function baselinePlan(Collection $tasks): array
    {
        return [
            $this->instructions('Ön değerlendirme', <<<'MD'
                Birazdan kısa sorular göreceksiniz. Her soruda bir Python kod parçası ve seçenekler var.

                - Her soruyu kendi bilginizle yanıtlayın, tahmin etmeniz gerekirse tahmin edin.
                - Bu bölümde herhangi bir kaynak veya yardım kullanılmıyor.
                - Bir soruya geçtikten sonra geri dönülmez.

                Hazır olduğunuzda **Başla** düğmesine basın.
                MD),
            ...$this->taskSteps($tasks),
            $this->closing('Ön değerlendirme tamamlandı. Lütfen araştırmacıya haber verin.'),
        ];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function learningPlan(ProtocolVersion $protocol, ?AssistanceCondition $condition, Collection $tasks): array
    {
        if (! $condition) {
            throw new RuntimeException('Öğrenme oturumu için katılımcının önce bir koşula atanması gerekir.');
        }

        return [
            $this->instructions('Oturum hakkında', <<<'MD'
                Bu oturumda yeni bir programlama kavramını öğrenecek ve birkaç görev çözeceksiniz.

                Her görev aynı düzende ilerler:

                1. **Okuma** — problemi dikkatlice okuyun.
                2. **Sessiz planlama** — kısa bir süre klavye ve fareye dokunmadan çözümü kafanızda kurun. Bu süre bitince ekran kendiliğinden açılır.
                3. **Kod yazma** — kodunuzu yazın, istediğiniz kadar çalıştırıp deneyin.
                4. **Kontrol** — çözümünüzü gözden geçirip teslim edin.

                Ölçüm kalitesi için lütfen görev sırasında sesli düşünmeyin, konuşmayın ve mümkün olduğunca başınızı sabit tutun.
                MD),
            $this->restingBaseline($protocol),
            $this->syncGesture($protocol, 'start'),
            ...$this->coreLesson($protocol),
            $this->instructions('Bu oturumda kullanabileceğiniz destek', $this->conditionBriefing($condition)),
            ...$this->taskSteps($tasks),
            $this->syncGesture($protocol, 'end'),
            $this->closing('Oturum tamamlandı, teşekkür ederiz. Sensörler çıkarılırken lütfen yerinizden kalkmayın.'),
        ];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function delayedPlan(ProtocolVersion $protocol, Collection $tasks): array
    {
        return [
            $this->instructions('Değerlendirme oturumu', <<<'MD'
                Bu oturumda geçen hafta öğrendiğiniz konuyla ilgili görevler çözeceksiniz.

                - Bu oturumda **hiçbir yardım veya kaynak** bulunmuyor; görevleri yalnızca kendi bilginizle çözün.
                - Görev düzeni aynı: okuma, sessiz planlama, kod yazma ve kontrol.
                - Lütfen görev sırasında konuşmayın ve sesli düşünmeyin.
                MD),
            $this->restingBaseline($protocol),
            $this->syncGesture($protocol, 'start'),
            ...$this->taskSteps($tasks),
            $this->syncGesture($protocol, 'end'),
            $this->closing('Çalışmanın tüm bölümlerini tamamladınız. Katkınız için teşekkür ederiz.'),
        ];
    }

    /**
     * The form shown in this session. Sessions 1 and 2 need the participant's
     * counterbalanced form order as soon as the protocol has form tasks.
     */
    private function formFor(Participant $participant, SessionKind $kind, ProtocolVersion $protocol): ?string
    {
        if ($kind === SessionKind::Baseline) {
            return null;
        }

        $form = $participant->formForSession($kind);

        if (! $form && $protocol->tasks()->where('is_active', true)->whereNotNull('form')->exists()) {
            throw new RuntimeException("{$participant->code} katılımcısının form sırası (A-B / B-A) yok. Formlu görevler için katılımcının form sırası içeren bir atama listesiyle rastgele atanması gerekir.");
        }

        return $form;
    }

    /**
     * Order tasks by the protocol's kind order for the session, then by
     * sort_order and id. Kinds missing from the order keep their place after
     * the listed ones.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    private function orderByKind(Collection $tasks, ProtocolVersion $protocol, SessionKind $kind): Collection
    {
        $order = array_values((array) $protocol->setting("session.task_order.{$kind->value}", []));
        $rank = fn (Task $task) => ($position = array_search($task->kind->value, $order, true)) === false ? count($order) : $position;

        return $tasks->sortBy([
            fn (Task $a, Task $b) => $rank($a) <=> $rank($b),
            fn (Task $a, Task $b) => $a->sort_order <=> $b->sort_order,
            fn (Task $a, Task $b) => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * The common core lesson: identical for every condition, shown before the
     * condition-specific support is introduced.
     *
     * @return list<array<string, mixed>>
     */
    private function coreLesson(ProtocolVersion $protocol): array
    {
        if (blank($protocol->core_lesson)) {
            return [];
        }

        $step = $this->instructions('Ortak öğrenme içeriği', $protocol->core_lesson);
        $step['config']['lesson'] = true;

        return [$step];
    }

    private function conditionBriefing(AssistanceCondition $condition): string
    {
        return match ($condition) {
            AssistanceCondition::DirectAi => <<<'MD'
                Ekranın sağında bir **yapay zeka asistanı** bulunuyor. Ona istediğiniz soruyu sorabilir, kodunuzu gönderebilir, tam çözüm veya hata düzeltmesi isteyebilirsiniz.

                Asistanı kullanıp kullanmamak ve ne kadar kullanacağınız size kalmış.
                MD,
            AssistanceCondition::SocraticAi => <<<'MD'
                Ekranın sağında bir **yapay zeka asistanı** bulunuyor. Bu asistan size hazır çözüm vermez; sorular sorarak ve adım adım ipuçlarıyla düşünmenize yardım eder.

                Takıldığınızda ona yazabilirsiniz. Ne kadar kullanacağınız size kalmış.
                MD,
            AssistanceCondition::StandardResource => <<<'MD'
                Ekranın sağında konuyla ilgili bir **başvuru kaynağı** bulunuyor: kavram açıklaması, örnekler, sözdizimi ve sık yapılan hatalar.

                Kaynakta arama yapabilir, istediğiniz bölümü açabilirsiniz. Ne kadar kullanacağınız size kalmış.
                MD,
        };
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function taskSteps(Collection $tasks): array
    {
        return $tasks->map(fn (Task $task) => [
            'type' => StepType::Task,
            'task_id' => $task->id,
            'label' => "{$task->kind->label()}: {$task->title}",
            'config' => null,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function instructions(string $title, string $body): array
    {
        return [
            'type' => StepType::Instructions,
            'label' => $title,
            'config' => ['title' => $title, 'body' => $body],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restingBaseline(ProtocolVersion $protocol): array
    {
        return [
            'type' => StepType::RestingBaseline,
            'label' => 'Dinlenim kaydı (göz açık / göz kapalı)',
            'config' => [
                'eyes_open_seconds' => (int) $protocol->setting('session.eyes_open_seconds'),
                'eyes_closed_seconds' => (int) $protocol->setting('session.eyes_closed_seconds'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncGesture(ProtocolVersion $protocol, string $moment): array
    {
        return [
            'type' => StepType::SyncGesture,
            'label' => $moment === 'start' ? 'Bileklik senkronu (başlangıç)' : 'Bileklik senkronu (bitiş)',
            'config' => [
                'moment' => $moment,
                'taps' => (int) $protocol->setting('session.sync_gesture_taps'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closing(string $message): array
    {
        return [
            'type' => StepType::Closing,
            'label' => 'Kapanış',
            'config' => ['body' => $message],
        ];
    }
}
