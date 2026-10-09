<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $action = $request->string('action')->toString();

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => AuditLog::query()
                ->when($action, fn ($query) => $query->where('action', 'like', $action.'%'))
                ->with('user:id,name')
                ->orderByDesc('id')
                ->paginate(40)
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user?->name ?? 'Sistem',
                    'subject' => $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null,
                    'properties' => $log->properties,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'filters' => ['action' => $action],
        ]);
    }
}
