<?php

namespace App\Models;

use App\Enums\AssistanceCondition;
use App\Enums\SkillStratum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_version_id', 'stratum', 'block_number', 'block_size', 'position', 'condition'])]
class RandomizationSlot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stratum' => SkillStratum::class,
            'condition' => AssistanceCondition::class,
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProtocolVersion, $this>
     */
    public function protocolVersion(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class);
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
