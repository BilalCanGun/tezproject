<?php

namespace App\Services\Assistance\Clients;

use App\Enums\AssistanceCondition;
use App\Services\Assistance\AssistantClient;
use App\Services\Assistance\AssistantReply;
use App\Services\Assistance\AssistantRequest;

/**
 * Deterministic stand-in used for dry runs and software testing before a real
 * provider is chosen. It mimics the observable shape of each condition so the
 * manipulation-check pipeline can be exercised end to end. Never use it for
 * real participants.
 */
class ScriptedAssistantClient implements AssistantClient
{
    public function complete(AssistantRequest $request): AssistantReply
    {
        $text = match ($request->condition) {
            AssistanceCondition::DirectAi => $this->directReply($request),
            AssistanceCondition::SocraticAi => $this->socraticReply($request->hintLevel ?? 1),
            AssistanceCondition::StandardResource => '',
        };

        return new AssistantReply(
            text: $text,
            model: 'scripted-v1',
            inputTokens: 0,
            outputTokens: 0,
            stopReason: 'end_turn',
            latencyMs: 400,
        );
    }

    private function directReply(AssistantRequest $request): string
    {
        $solution = $request->referenceSolution ?? "def cozum(veri):\n    pass";

        return "İşte çalışan bir çözüm:\n\n```python\n{$solution}\n```\n\nFonksiyon iç içe her elemanı kontrol ediyor; eleman bir listeyse aynı fonksiyonu o liste için tekrar çağırıyor, değilse doğrudan işliyor.";
    }

    private function socraticReply(int $hintLevel): string
    {
        return match ($hintLevel) {
            1 => 'Problemi en küçük hâliyle düşünelim: girdi hiç iç içe olmasaydı ne yapardınız? Sonra şunu sorun: bir eleman kendisi de bir liste olduğunda, onunla ne yapmak istersiniz?',
            2 => 'Kodunuzda her elemanı tek tek dolaşıyorsunuz. Bir eleman liste olduğunda o noktada hangi işi tekrar etmeniz gerekiyor? Bu işi zaten yapan bir fonksiyonunuz var mı?',
            default => 'Şu adımları kendi kodunuzla eşleştirin: (1) sonucu tutacak bir değişken, (2) her eleman için tür kontrolü, (3) liste ise fonksiyonun kendisini çağırıp dönen değeri sonuca ekleme, (4) değilse elemanı doğrudan işleme. Hangi adım eksik görünüyor?',
        };
    }
}
