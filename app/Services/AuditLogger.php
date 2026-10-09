<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records research-relevant actions (randomisation, protocol freeze, exports,
 * data deletion) so the data trail can be reconstructed for the ethics board.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $action, ?Model $subject = null, array $properties = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->request->user()?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }
}
