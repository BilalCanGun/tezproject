<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['experiment_session_id', 'device_profile_id', 'serial_number', 'lsl_stream_name', 'checks', 'notes'])]
class SessionDevice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checks' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExperimentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExperimentSession::class, 'experiment_session_id');
    }

    /**
     * @return BelongsTo<DeviceProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DeviceProfile::class, 'device_profile_id');
    }
}
