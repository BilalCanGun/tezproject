<?php

namespace App\Services\Participants;

use App\Enums\ParticipantStatus;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParticipantRegistrar
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $contact
     */
    public function register(ProtocolVersion $protocol, array $attributes, array $contact, User $creator): Participant
    {
        return DB::transaction(function () use ($protocol, $attributes, $contact, $creator) {
            ProtocolVersion::query()->whereKey($protocol->id)->lockForUpdate()->first();

            $participant = new Participant([
                ...$attributes,
                ...$this->screeningOutcome($attributes['screening']),
                'protocol_version_id' => $protocol->id,
                'code' => $this->nextCode($protocol),
                'created_by' => $creator->id,
            ]);
            $participant->save();

            if (array_filter($contact)) {
                $participant->contact()->create($contact);
            }

            return $participant;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $contact
     */
    public function update(Participant $participant, array $attributes, array $contact): Participant
    {
        return DB::transaction(function () use ($participant, $attributes, $contact) {
            $participant->fill($attributes);

            if (in_array($participant->status, [ParticipantStatus::Screening, ParticipantStatus::Eligible, ParticipantStatus::Ineligible], true)) {
                $participant->fill($this->screeningOutcome($attributes['screening']));
            }

            $participant->save();
            $participant->contact()->updateOrCreate([], $contact);

            return $participant;
        });
    }

    /**
     * Apply the inclusion/exclusion criteria. Review-level answers do not
     * exclude automatically; they are stored and flagged on the profile.
     *
     * @param  array<string, bool>  $screening
     * @return array{status: ParticipantStatus, exclusion_reason: ?string}
     */
    public function screeningOutcome(array $screening): array
    {
        $failed = collect(Participant::SCREENING_ITEMS)
            ->filter(fn (array $item, string $key) => $item['effect'] === 'exclude' && (bool) ($screening[$key] ?? ! $item['expected']) !== $item['expected'])
            ->pluck('label');

        return $failed->isEmpty()
            ? ['status' => ParticipantStatus::Eligible, 'exclusion_reason' => null]
            : ['status' => ParticipantStatus::Ineligible, 'exclusion_reason' => 'Ön eleme: '.$failed->implode('; ')];
    }

    /**
     * @return list<string>
     */
    public static function reviewFlags(?array $screening): array
    {
        return collect(Participant::SCREENING_ITEMS)
            ->filter(fn (array $item, string $key) => $item['effect'] === 'review' && isset($screening[$key]) && (bool) $screening[$key] !== $item['expected'])
            ->pluck('label')
            ->values()
            ->all();
    }

    private function nextCode(ProtocolVersion $protocol): string
    {
        $prefix = $protocol->phase->participantCodePrefix().'-';

        $last = Participant::query()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
