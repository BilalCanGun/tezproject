<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Services\AuditLogger;
use App\Services\Export\DatasetExporter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Exports/Index', [
            'protocols' => ProtocolVersion::query()
                ->withCount('participants')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ProtocolVersion $protocol) => [
                    'id' => $protocol->id,
                    'code' => $protocol->code,
                    'name' => $protocol->name,
                    'phase_label' => $protocol->phase->label(),
                    'participants_count' => $protocol->participants_count,
                    'is_frozen' => $protocol->isFrozen(),
                ]),
            'datasets' => DatasetExporter::DATASETS,
        ]);
    }

    public function show(ProtocolVersion $protocol, string $dataset, DatasetExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        validator(['dataset' => $dataset], ['dataset' => [Rule::in(array_keys(DatasetExporter::DATASETS))]])->validate();

        $audit->record('export.downloaded', $protocol, ['dataset' => $dataset]);

        return $exporter->stream($dataset, $protocol);
    }
}
