<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identifying information used only for scheduling. Encrypted at rest, never
 * exported, and purged after the retention date set in the consent form.
 */
#[Fillable(['participant_id', 'full_name', 'email', 'phone', 'purge_after'])]
#[Hidden(['full_name', 'email', 'phone'])]
class ParticipantContact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'purge_after' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
