<?php

namespace App\Services\Randomization;

use App\Enums\ParticipantStatus;
use App\Enums\SkillStratum;
use App\Models\Participant;
use App\Models\RandomizationSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ParticipantRandomizer
{
    /**
     * Assign the next concealed slot of the participant's stratum. Row locks
     * keep two testers from drawing the same slot at the same moment.
     */
    public function assign(Participant $participant, User $assignedBy): RandomizationSlot
    {
        if ($participant->status !== ParticipantStatus::Eligible) {
            throw new RuntimeException('Yalnızca uygunluğu onaylanmış ve ön testi tamamlanmış katılımcılar atanabilir.');
        }

        if ($participant->baseline_score === null || $participant->consented_at === null) {
            throw new RuntimeException('Atama için onam ve ön değerlendirme puanı gereklidir.');
        }

        $protocol = $participant->protocolVersion;
        $stratum = SkillStratum::fromPercentage(
            $participant->baselinePercentage(),
            (float) $protocol->setting('stratification.low_upper_percent'),
            (float) $protocol->setting('stratification.medium_upper_percent'),
        );

        return DB::transaction(function () use ($participant, $assignedBy, $stratum) {
            $slot = RandomizationSlot::query()
                ->where('protocol_version_id', $participant->protocol_version_id)
                ->where('stratum', $stratum->value)
                ->whereNull('participant_id')
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw new RuntimeException("{$stratum->label()} tabakasında boş atama kalmadı. Yönetici listeyi uzatmalıdır.");
            }

            $slot->forceFill([
                'participant_id' => $participant->id,
                'assigned_at' => now(),
                'assigned_by' => $assignedBy->id,
            ])->save();

            $participant->forceFill([
                'stratum' => $stratum,
                'condition' => $slot->condition,
                'form_order' => $slot->form_order,
                'status' => ParticipantStatus::Randomized,
                'randomized_at' => now(),
                'randomized_by' => $assignedBy->id,
            ])->save();

            return $slot;
        });
    }
}
