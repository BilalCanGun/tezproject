<?php

namespace App\Models;

use App\Enums\DataFileKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'experiment_session_id', 'kind', 'original_name', 'path', 'size_bytes', 'sha256',
    'recorded_start_utc', 'recorded_end_utc', 'notes', 'uploaded_by',
])]
#[Hidden(['path'])]
class DataFile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DataFileKind::class,
            'size_bytes' => 'integer',
            'recorded_start_utc' => 'datetime',
            'recorded_end_utc' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
