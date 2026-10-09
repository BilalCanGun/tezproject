<?php

namespace App\Http\Controllers;

use App\Enums\DataFileKind;
use App\Models\DataFile;
use App\Models\ExperimentSession;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Raw recordings (XDF, vendor EEG/gaze files, wearable exports) attached to a
 * session. Stored on the private disk with a SHA-256 checksum so later copies
 * can be verified against the original.
 */
class DataFileController extends Controller
{
    public function store(Request $request, ExperimentSession $session, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(DataFileKind::class)],
            'file' => ['required', 'file', 'max:2097152', 'extensions:xdf,csv,edf,bdf,gdf,avro,json,txt,zip,tsv,fif'],
            'recorded_start_utc' => ['nullable', 'date'],
            'recorded_end_utc' => ['nullable', 'date', 'after_or_equal:recorded_start_utc'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $upload = $request->file('file');
        $code = $session->participant->code;
        $path = $upload->storeAs(
            "recordings/{$code}/session-{$session->session_number}",
            now()->format('Ymd_His').'_'.$validated['kind'].'.'.$upload->getClientOriginalExtension(),
            'local',
        );

        $file = $session->files()->create([
            'kind' => $validated['kind'],
            'original_name' => $upload->getClientOriginalName(),
            'path' => $path,
            'size_bytes' => $upload->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'recorded_start_utc' => $validated['recorded_start_utc'] ?? null,
            'recorded_end_utc' => $validated['recorded_end_utc'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        $audit->record('data_file.uploaded', $file, ['kind' => $file->kind->value, 'sha256' => $file->sha256]);

        return back()->with('success', 'Dosya yüklendi ve sağlama toplamı kaydedildi.');
    }

    public function show(ExperimentSession $session, DataFile $file, AuditLogger $audit): StreamedResponse
    {
        $audit->record('data_file.downloaded', $file);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function destroy(Request $request, ExperimentSession $session, DataFile $file, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        Storage::disk('local')->delete($file->path);
        $audit->record('data_file.deleted', $file, ['original_name' => $file->original_name, 'sha256' => $file->sha256]);
        $file->delete();

        return back()->with('success', 'Dosya silindi.');
    }
}
