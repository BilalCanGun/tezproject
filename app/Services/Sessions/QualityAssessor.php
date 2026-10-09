<?php

namespace App\Services\Sessions;

use App\Enums\QualityVerdict;
use App\Enums\SessionStatus;
use App\Models\ExperimentSession;

/**
 * Compares a session's quality metrics with the thresholds that were fixed in
 * the protocol before data collection.
 */
class QualityAssessor
{
    /**
     * @param  array<string, mixed>  $metrics
     * @return array{failed_checks: list<string>, suggested_verdict: QualityVerdict}
     */
    public function assess(ExperimentSession $session, array $metrics): array
    {
        $protocol = $session->participant->protocolVersion;
        $failed = [];

        $eeg = $metrics['eeg_usable_epoch_ratio'] ?? null;
        $gaze = $metrics['gaze_valid_ratio'] ?? null;
        $wearable = $metrics['wearable_loss_ratio'] ?? null;

        if ($eeg !== null && $eeg < $protocol->setting('quality.min_eeg_usable_epoch_ratio')) {
            $failed[] = 'eeg';
        }

        if ($gaze !== null && $gaze < $protocol->setting('quality.min_gaze_valid_ratio')) {
            $failed[] = 'gaze';
        }

        if ($wearable !== null && $wearable > $protocol->setting('quality.max_wearable_loss_ratio')) {
            $failed[] = 'wearable';
        }

        $markers = $session->events()->whereNotNull('marker_code');
        $expected = (clone $markers)->count();
        $integrity = $expected > 0 ? (clone $markers)->whereNotNull('lsl_time')->count() / $expected : null;

        if ($session->kind()->recordsPhysiology() && ($integrity === null || $integrity < $protocol->setting('quality.min_marker_integrity'))) {
            $failed[] = 'markers';
        }

        $behaviourComplete = $session->status === SessionStatus::Completed;

        $verdict = match (true) {
            ! $behaviourComplete => QualityVerdict::Unusable,
            $failed === [] => QualityVerdict::Usable,
            default => QualityVerdict::Partial,
        };

        return ['failed_checks' => $failed, 'suggested_verdict' => $verdict];
    }
}
