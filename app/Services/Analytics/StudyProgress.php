<?php

namespace App\Services\Analytics;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Enums\SkillStratum;
use App\Models\ExperimentSession;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use Illuminate\Support\Carbon;

class StudyProgress
{
    /**
     * @return array<string, mixed>
     */
    public function summary(ProtocolVersion $protocol): array
    {
        $participants = $protocol->participants()->get(['id', 'status', 'condition', 'stratum']);
        $target = (int) $protocol->setting('sample.completers_per_condition');

        $grid = [];
        foreach (AssistanceCondition::cases() as $condition) {
            $inCondition = $participants->where('condition', $condition);
            $grid[$condition->value] = [
                'label' => $condition->label(),
                'assigned' => $inCondition->count(),
                'completed' => $inCondition->where('status', ParticipantStatus::Completed)->count(),
                'target' => $target,
                'by_stratum' => collect(SkillStratum::cases())
                    ->mapWithKeys(fn (SkillStratum $stratum) => [$stratum->value => $inCondition->where('stratum', $stratum)->count()])
                    ->all(),
            ];
        }

        return [
            'enrolled' => $participants->whereNotIn('status', [ParticipantStatus::Ineligible])->count(),
            'enrolment_target' => (int) $protocol->setting('sample.enrolment_target'),
            'by_status' => collect(ParticipantStatus::cases())
                ->mapWithKeys(fn (ParticipantStatus $status) => [$status->value => [
                    'label' => $status->label(),
                    'count' => $participants->where('status', $status)->count(),
                ]])->all(),
            'conditions' => $grid,
            'attrition' => $this->attrition($participants->where('status', ParticipantStatus::FirstSessionDone)->count()
                + $participants->where('status', ParticipantStatus::Completed)->count(),
                $participants->where('status', ParticipantStatus::Completed)->count()),
        ];
    }

    /**
     * Participants whose delayed session falls due, ordered by the end of their
     * allowed window so nobody silently slips outside the 6–8 day protocol.
     *
     * @return list<array<string, mixed>>
     */
    public function delayedSessionWindow(ProtocolVersion $protocol): array
    {
        $min = (int) $protocol->setting('session.delay_min_days');
        $max = (int) $protocol->setting('session.delay_max_days');

        return Participant::query()
            ->where('protocol_version_id', $protocol->id)
            ->where('status', ParticipantStatus::FirstSessionDone)
            ->with(['sessions' => fn ($query) => $query->select('id', 'participant_id', 'session_number', 'status', 'started_at', 'scheduled_at')])
            ->get()
            ->map(function (Participant $participant) use ($min, $max) {
                $first = $participant->session(SessionKind::Learning);
                $second = $participant->session(SessionKind::Delayed);
                $anchor = $first?->started_at?->copy()->startOfDay();

                if (! $anchor) {
                    return null;
                }

                $opens = $anchor->copy()->addDays($min);
                $closes = $anchor->copy()->addDays($max);

                return [
                    'participant_id' => $participant->id,
                    'code' => $participant->code,
                    'first_session_at' => $first->started_at->toIso8601String(),
                    'window_opens' => $opens->toDateString(),
                    'window_closes' => $closes->toDateString(),
                    'scheduled_at' => $second?->scheduled_at?->toIso8601String(),
                    'state' => $this->windowState(today(), $opens, $closes),
                ];
            })
            ->filter()
            ->sortBy('window_closes')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcomingSessions(int $days = 7): array
    {
        return ExperimentSession::query()
            ->whereIn('status', [SessionStatus::Scheduled, SessionStatus::Preparing, SessionStatus::Running])
            ->where(fn ($query) => $query
                ->whereBetween('scheduled_at', [today(), today()->addDays($days)->endOfDay()])
                ->orWhereIn('status', [SessionStatus::Preparing, SessionStatus::Running]))
            ->with(['participant:id,code,condition', 'conductor:id,name'])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ExperimentSession $session) => [
                'id' => $session->id,
                'participant_code' => $session->participant->code,
                'kind' => $session->kind()->label(),
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                'scheduled_at' => $session->scheduled_at?->toIso8601String(),
                'conductor' => $session->conductor?->name,
            ])
            ->all();
    }

    private function windowState(Carbon $today, Carbon $opens, Carbon $closes): string
    {
        return match (true) {
            $today->lt($opens) => 'upcoming',
            $today->lte($closes) => 'open',
            default => 'overdue',
        };
    }

    /**
     * @return array{started: int, completed: int, rate: ?float}
     */
    private function attrition(int $started, int $completed): array
    {
        return [
            'started' => $started,
            'completed' => $completed,
            'rate' => $started > 0 ? round(1 - $completed / $started, 3) : null,
        ];
    }
}
