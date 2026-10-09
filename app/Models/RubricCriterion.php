<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_version_id', 'key', 'label', 'description', 'min_score', 'max_score', 'anchors', 'sort_order'])]
class RubricCriterion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchors' => 'array',
            'min_score' => 'integer',
            'max_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProtocolVersion, $this>
     */
    public function protocolVersion(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class);
    }
}
