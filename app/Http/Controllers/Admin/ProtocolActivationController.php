<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Exactly one protocol version is active at a time; new participants are
 * enrolled into it.
 */
class ProtocolActivationController extends Controller
{
    public function store(ProtocolVersion $protocol, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($protocol) {
            ProtocolVersion::query()->where('is_active', true)->update(['is_active' => false]);
            $protocol->forceFill(['is_active' => true])->save();
        });

        $audit->record('protocol.activated', $protocol);

        return back()->with('success', "{$protocol->code} etkin protokol olarak ayarlandı. Yeni katılımcılar bu sürüme kaydedilecek.");
    }
}
