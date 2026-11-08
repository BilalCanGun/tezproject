<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProtocolVersion;
use App\Services\Analytics\ManipulationCheck;
use App\Services\Analytics\OutcomeDataset;
use App\Services\Analytics\PilotValidation;
use App\Services\Analytics\Statistics;
use App\Services\Analytics\StudyProgress;
use App\Services\Scoring\ReliabilityReport;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public const DEVICE_REPORT = 'analysis/device_validation.json';

    public function progress(Request $request, StudyProgress $progress, OutcomeDataset $dataset): Response
    {
        $protocol = $this->protocol($request);

        $rows = $protocol ? $dataset->rows($protocol)->filter(fn ($row) => $row['included_primary'] === 1)->values() : collect();

        $primary = $rows->groupBy('condition')->map(fn ($group, $condition) => [
            'condition' => $condition,
            'n' => $group->count(),
            'mean' => $this->round(Statistics::mean($group->pluck('s2_far_transfer_pct')->filter(fn ($v) => $v !== null)->values()->all())),
            'sd' => $this->round(Statistics::sd($group->pluck('s2_far_transfer_pct')->filter(fn ($v) => $v !== null)->values()->all())),
        ])->values();

        return Inertia::render('Admin/Reports/Progress', [
            ...$this->protocolProps($protocol),
            'summary' => $protocol ? $progress->summary($protocol) : null,
            'primaryDescriptives' => $primary,
        ]);
    }

    public function manipulation(Request $request, ManipulationCheck $check): Response
    {
        $protocol = $this->protocol($request);

        return Inertia::render('Admin/Reports/Manipulation', [
            ...$this->protocolProps($protocol),
            'report' => $protocol ? $check->forProtocol($protocol) : null,
        ]);
    }

    public function pilot(Request $request, PilotValidation $validation): Response
    {
        $protocol = $this->protocol($request);

        return Inertia::render('Admin/Reports/Pilot', [
            ...$this->protocolProps($protocol),
            'rows' => $protocol ? $validation->table($protocol) : [],
        ]);
    }

    public function reliability(Request $request, ReliabilityReport $report): Response
    {
        $protocol = $this->protocol($request);

        return Inertia::render('Admin/Reports/Reliability', [
            ...$this->protocolProps($protocol),
            'report' => $protocol ? $report->build($protocol) : null,
        ]);
    }

    /**
     * Results of the Python device validation and model comparison
     * (analysis/run_validation.py). The page explains how to produce them
     * when the file does not exist yet.
     */
    public function devices(): Response
    {
        $disk = Storage::disk('local');
        $report = $disk->exists(self::DEVICE_REPORT) ? json_decode($disk->get(self::DEVICE_REPORT), true) : null;

        return Inertia::render('Admin/Reports/Devices', [
            'report' => is_array($report) ? Arr::except($report, 'sections') : null,
        ]);
    }

    private function protocol(Request $request): ?ProtocolVersion
    {
        $id = $request->integer('protocol');

        return $id ? ProtocolVersion::find($id) : ProtocolVersion::active()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function protocolProps(?ProtocolVersion $protocol): array
    {
        return [
            'protocol' => $protocol?->only(['id', 'code', 'name']),
            'protocols' => ProtocolVersion::query()->orderByDesc('id')->get(['id', 'code', 'name']),
        ];
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }
}
