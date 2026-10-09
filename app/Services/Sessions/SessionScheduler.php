<?php

namespace App\Services\Sessions;

use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Enforces the session order (pre-assessment → learning → delayed) and the
 * 6–8 day delay window. Out-of-window delayed sessions are allowed only with
 * an explicit justification, which is kept with the session for reporting.
 */
class SessionScheduler
{
    public function canSchedule(Participant $participant, SessionKind $kind): bool
    {
        $participant->loadMissing('sessions');

        if ($participant->session($kind) || $participant->status->isClosed()) {
            return false;
        }

        return match ($kind) {
            SessionKind::Baseline => $participant->status === ParticipantStatus::Eligible,
            SessionKind::Learning => $participant->status === ParticipantStatus::Randomized,
            SessionKind::Delayed => $participant->status === ParticipantStatus::FirstSessionDone
                && $participant->session(SessionKind::Learning)?->status === SessionStatus::Completed,
        };
    }

    /**
     * @return array{opens: Carbon, closes: Carbon}|null
     */
    public function delayWindow(Participant $participant): ?array
    {
        $first = $participant->session(SessionKind::Learning)?->started_at;

        if (! $first) {
            return null;
        }

        $protocol = $participant->protocolVersion;

        return [
            'opens' => $first->copy()->startOfDay()->addDays((int) $protocol->setting('session.delay_min_days')),
            'closes' => $first->copy()->endOfDay()->addDays((int) $protocol->setting('session.delay_max_days')),
        ];
    }

    public function schedule(Participant $participant, SessionKind $kind, ?Carbon $scheduledAt, User $conductor, ?string $windowJustification): ExperimentSession
    {
        if (! $this->canSchedule($participant, $kind)) {
            throw new RuntimeException("{$kind->label()} şu anda planlanamaz; önceki adımlar tamamlanmamış veya katılımcı kapalı durumda.");
        }

        $notes = null;

        if ($kind === SessionKind::Delayed && $scheduledAt) {
            $window = $this->delayWindow($participant);

            if ($window && ! $scheduledAt->between($window['opens'], $window['closes'])) {
                if (! $windowJustification) {
                    throw new RuntimeException(sprintf(
                        'Gecikmeli oturum %s – %s aralığında olmalı. Aralık dışı planlama için gerekçe girin.',
                        $window['opens']->format('d.m.Y'),
                        $window['closes']->format('d.m.Y'),
                    ));
                }

                $notes = "Protokol penceresi dışı planlama gerekçesi: {$windowJustification}";
            }
        }

        return $participant->sessions()->create([
            'session_number' => $kind->value,
            'status' => SessionStatus::Scheduled,
            'condition' => $kind === SessionKind::Baseline ? null : $participant->condition,
            'scheduled_at' => $scheduledAt,
            'conducted_by' => $conductor->id,
            'notes' => $notes,
        ]);
    }
}
