<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_version_id', 'section', 'title', 'body', 'sort_order'])]
class ResourceArticle extends Model
{
    public const SECTIONS = [
        'concept' => 'Kavram açıklaması',
        'examples' => 'Örnekler',
        'syntax' => 'Sözdizimi',
        'common_errors' => 'Sık yapılan hatalar',
    ];

    /**
     * @return BelongsTo<ProtocolVersion, $this>
     */
    public function protocolVersion(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class);
    }
}
