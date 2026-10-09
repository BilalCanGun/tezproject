<?php

namespace App\Services\Sessions;

use App\Models\ExperimentSession;
use Illuminate\Support\Str;

/**
 * Kiosk links carry a single-use bearer token. Only its hash is stored, so a
 * database dump cannot be used to open a participant screen.
 */
class KioskAccess
{
    public function issue(ExperimentSession $session): string
    {
        $token = Str::random(48);

        $session->forceFill([
            'kiosk_token_hash' => hash('sha256', $token),
            'kiosk_token_expires_at' => now()->addHours(config('experiment.kiosk_token_hours')),
            'kiosk_connected_at' => null,
        ])->save();

        return $token;
    }

    public function resolve(string $token): ?ExperimentSession
    {
        $session = ExperimentSession::query()
            ->where('kiosk_token_hash', hash('sha256', $token))
            ->first();

        if (! $session || $session->kiosk_token_expires_at?->isPast()) {
            return null;
        }

        return $session;
    }

    public function revoke(ExperimentSession $session): void
    {
        $session->forceFill([
            'kiosk_token_hash' => null,
            'kiosk_token_expires_at' => null,
        ])->save();
    }
}
