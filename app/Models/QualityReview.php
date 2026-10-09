<?php

namespace App\Models;

use App\Enums\QualityVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'experiment_session_id', 'eeg_usable_epoch_ratio', 'gaze_valid_ratio', 'wearable_loss_ratio',
    'markers_expected', 'markers_acknowledged', 'all_steps_completed', 'verdict', 'needs_repeat',
    'failed_checks', 'notes', 'reviewed_by',
])]
class QualityReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eeg_usable_epoch_ratio' => 'float',
            'gaze_valid_ratio' => 'float',
            'wearable_loss_ratio' => 'float',
            'all_steps_completed' => 'boolean',
            'needs_repeat' => 'boolean',
            'verdict' => QualityVerdict::class,
            'failed_checks' => 'array',
        ];
    }

    public function markerIntegrity(): ?float
    {
        if (! $this->markers_expected) {
            return null;
        }

        return round($this->markers_acknowledged / $this->markers_expected, 4);
    }

    /**
     * @return BelongsTo<ExperimentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperimentSession::class, 'experiment_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
